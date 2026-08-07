<?php
/**
 * @arquivo       api/v1/cip/sync.php
 * @versao        1.0.0
 * @modificado_em 2026-07-29
 * @objetivo      Endpoint único de sync de limites (pull+ack) Cloud<->Firmware.
 *                Autentica via HmacAuth, valida hash da curva, resolve LWW por
 *                epoch UTC e devolve a versão vencedora (ok|conflict).
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php'; // Ou config/app.php dependendo de onde o isDev esta configurado
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../app/services/HmacAuth.php';
require_once __DIR__ . '/../../../app/services/LimitesSync.php';

header('Content-Type: application/json; charset=utf-8');

$isDev = (defined('IS_DEV') && IS_DEV) || (defined('APP_ENV') && APP_ENV === 'dev');

function responder(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function erro(int $code, string $msg, ?string $detalhe = null): never
{
    global $isDev;
    $out = ['erro' => $msg];
    if ($isDev && $detalhe !== null) {
        $out['detalhe'] = $detalhe;
    }
    responder($code, $out);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        erro(405, 'Metodo nao permitido.');
    }

    // --- Corpo cru (envelope de transporte) ---
    $raw = file_get_contents('php://input') ?: '';
    $env = json_decode($raw, true);
    if (!is_array($env)) {
        erro(400, 'JSON invalido.');
    }

    // --- 1. Autenticação (HmacAuth: serial:ts) ---
    $serial   = $_SERVER['HTTP_X_CIP_SERIAL'] ?? '';
    $tsWindow = $_SERVER['HTTP_X_CIP_TS']     ?? '';
    $sigRecv  = $_SERVER['HTTP_X_CIP_SIG']    ?? '';
    if ($serial === '' || $tsWindow === '' || $sigRecv === '') {
        erro(401, 'Headers de autenticacao ausentes.');
    }

    $pdo = getDbConnection();

    // A coluna correta em controladores é 'codigo'
    $stmt = $pdo->prepare(
        'SELECT id, empresa_id, hmac_secret
           FROM controladores
          WHERE codigo = :serial
          LIMIT 1'
    );
    $stmt->execute([':serial' => $serial]);
    $ctrl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ctrl) {
        erro(401, 'Dispositivo nao autorizado.');
    }

    $message = $serial . ':' . $tsWindow;
    $sigCalc = hash_hmac('sha256', $message, (string) $ctrl['hmac_secret']);
    if (!hash_equals($sigCalc, strtolower($sigRecv))) {
        erro(401, 'Assinatura invalida.');
    }

    // --- 2. Extrai curva do envelope e valida integridade ---
    // Envelope: {"editado_em_local": <ts|0|ISO>, "hash": "...", "payload_json": {...}}
    $curvaFwRaw = $env['payload_json'] ?? null;
    $hashFw     = strtolower((string) ($env['hash'] ?? ''));
    $editadoFw  = $env['editado_em_local'] ?? null;

    if (!is_array($curvaFwRaw) || $hashFw === '') {
        erro(400, 'Envelope incompleto (payload_json/hash).');
    }

    try {
        $curvaFw = LimitesSync::validarEstrutura($curvaFwRaw);
    } catch (InvalidArgumentException $e) {
        erro(422, 'Curva do firmware invalida.', $e->getMessage());
    }

    // Confere hash declarado vs recalculado (integridade de transporte)
    $hashCalc = LimitesSync::calcularHash($curvaFw);
    if (!hash_equals($hashCalc, $hashFw)) {
        erro(422, 'Hash da curva divergente.', "esperado={$hashCalc} recebido={$hashFw}");
    }

    // --- 3. Epoch UTC do firmware (LWW) ---
    $epochFw = null;
    if (is_int($editadoFw) || (is_string($editadoFw) && ctype_digit($editadoFw))) {
        $epochFw = (int) $editadoFw;
    } elseif (is_string($editadoFw) && $editadoFw !== '') {
        $ts = strtotime($editadoFw . ' UTC');
        $epochFw = $ts !== false ? $ts : null;
    }

    // --- 4. Curva ativa do cloud ---
    // Nomes de colunas ajustados para o schema real do banco
    $stmt = $pdo->prepare(
        'SELECT id, versao, payload_json, hash_payload, origem, sync_status,
                UNIX_TIMESTAMP(atualizado_em) AS epoch_cloud
           FROM tabela_limites
          WHERE controlador_id = :cid AND ativa = 1
          FOR UPDATE'
    );
    $pdo->beginTransaction();
    $stmt->execute([':cid' => (int) $ctrl['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $epochCloud = $row ? (int) $row['epoch_cloud'] : null;
    $forcar = filter_var($env['forcar'] ?? false, FILTER_VALIDATE_BOOLEAN);

    // --- 5. FW sem NTP => divergente (não aplica automático) ---
    if ($epochFw === 0 && !$forcar) {
        $pdo->rollBack();
        registrarLog($pdo, (int) $ctrl['id'], 'divergente',
            'editado_em_local=0 (FW sem NTP) — decisao manual.');
        responder(200, [
            'sucesso' => true,
            'data' => ['status' => 'divergente', 'motivo' => 'fw_sem_ntp'],
        ]);
    }

    // --- 6. LWW ---
    // Regra: maior epoch vence; empate => Cloud; forcar => Cloud sempre.
    $cloudVence = $forcar
        || $epochCloud === null && false            // sem cloud, FW vence
        || ($epochCloud !== null && $epochFw !== null && $epochCloud >= $epochFw)
        || ($epochCloud !== null && $epochFw === null);

    if ($epochCloud === null) {
        $cloudVence = false;
    }

    if ($cloudVence && $row) {
        $pdo->rollBack(); // Libera o row lock
        
        registrarLog($pdo, (int) $ctrl['id'], 'conflict',
            "Cloud vence (cloud={$epochCloud} fw=" . ($epochFw ?? 'null') . ').');

        $curvaCloud = json_decode((string) $row['payload_json'], true) ?: [];
        responder(200, [
            'sucesso' => true,
            'data' => [
                'status'     => 'conflict',
                'updated_at' => $epochCloud,
                'hash'       => $row['hash_payload'],
                'curva'      => $curvaCloud,
            ],
        ]);
    }

    // --- 7. FW vence (ou cloud vazio) => Cloud adota a curva do firmware ---
    $curvaCanon = LimitesSync::canonizar($curvaFw);
    $novaVersao = $row ? ((int) $row['versao'] + 1) : 1;

    // CIP-DEC-20260725-001: Mover antiga para historico e inserir nova ativa
    if ($row) {
        $stmtHist = $pdo->prepare("INSERT INTO tabela_limites_historico (controlador_id, versao, payload_json, origem, usuario_id, sync_status_final) VALUES (:cid, :v, :p, :o, NULL, :s)");
        $stmtHist->execute([
            ':cid' => (int) $ctrl['id'],
            ':v'   => $row['versao'],
            ':p'   => $row['payload_json'],
            ':o'   => $row['origem'],
            ':s'   => $row['sync_status']
        ]);

        $stmtUpdate = $pdo->prepare("UPDATE tabela_limites SET ativa = 0 WHERE id = :id");
        $stmtUpdate->execute([':id' => $row['id']]);
    }

    $up = $pdo->prepare(
        'INSERT INTO tabela_limites
            (controlador_id, versao, payload_json, hash_payload,
             atualizado_em, origem, sync_status, ativa)
         VALUES
            (:cid, :versao, :curva, :hash,
             FROM_UNIXTIME(:epoch), :origem, :sync_status, 1)'
    );
    $up->execute([
        ':cid'         => (int) $ctrl['id'],
        ':curva'       => $curvaCanon,
        ':hash'        => $hashCalc,
        ':epoch'       => $epochFw ?? time(),
        ':versao'      => $novaVersao,
        ':origem'      => 'firmware',
        ':sync_status' => 'sincronizada'
    ]);

    $pdo->commit();

    registrarLog($pdo, (int) $ctrl['id'], 'executado',
        "FW vence — cloud atualizado (v{$novaVersao}).");

    responder(200, [
        'sucesso' => true,
        'data' => [
            'status'     => 'ok',
            'updated_at' => $epochFw ?? time(),
            'hash'       => $hashCalc,
        ],
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    erro(500, 'Erro de banco.', $e->getMessage());
} catch (Throwable $e) {
    erro(500, 'Erro interno.', $e->getMessage());
}

/** Log de auditoria em logs_sistema. */
function registrarLog(PDO $pdo, int $ctrlId, string $estado, string $msg): void
{
    try {
        $l = $pdo->prepare(
            'INSERT INTO logs_sistema
                (controlador_id, origem, categoria, mensagem, criado_em_utc)
             VALUES (:cid, :origem, :cat, :msg, UTC_TIMESTAMP())'
        );
        $l->execute([
            ':cid'    => $ctrlId,
            ':origem' => 'sync_limites',
            ':cat'    => $estado,
            ':msg'    => $msg,
        ]);
    } catch (Throwable) {
        // Log é best-effort; não derruba o sync.
    }
}
