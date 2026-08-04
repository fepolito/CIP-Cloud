<?php
/**
 * @arquivo       api/sync/limites_check.php
 * @versao        1.0.0
 * @modificado_em 2026-07-25
 * @objetivo      Firmware consulta estado da curva. Compara LWW e retorna acao
 *                (push/pull/ok/divergente). ACK embutido: se ESP manda o hash
 *                aplicado, marca sincronizada.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/DeviceAuth.php';
require_once __DIR__ . '/../../app/services/LimitesSync.php';

try {
    $pdo = getDbConnection();
    $dev = DeviceAuth::autenticar($pdo);

    // Entrada do ESP (query ou body): estado local + ack opcional
    $in            = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $espEditado    = $in['editado_em_local'] ?? null;   // UTC
    $espHash       = $in['hash']             ?? null;
    $ackHash       = $in['ack_hash']         ?? null;    // hash que o ESP acabou de aplicar

    // Estado atual da cloud (filtro multi-tenant via controlador do device)
    $st = $pdo->prepare(
        'SELECT versao, atualizado_em, hash_payload, payload_json, sync_status
           FROM tabela_limites
          WHERE controlador_id = :cid
          LIMIT 1'
    );
    $st->execute([':cid' => $dev['id']]);
    $cloud = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // --- ACK embutido: ESP confirma que aplicou o push anterior ---
    if ($ackHash !== null && $cloud && $ackHash === $cloud['hash_payload']) {
        $pdo->prepare(
            "UPDATE tabela_limites
                SET sync_status='sincronizada'
              WHERE controlador_id=:cid"
        )->execute([':cid' => $dev['id']]);
        $cloud['sync_status'] = 'sincronizada';
    }

    $acao = LimitesSync::decidir(
        $cloud['atualizado_em']  ?? null,
        $cloud['hash_payload']   ?? null,
        $espEditado,
        $espHash
    );

    $data = [
        'acao'  => $acao,
        'cloud' => $cloud ? [
            'versao'        => (int)$cloud['versao'],
            'atualizado_em' => $cloud['atualizado_em'], // UTC
            'hash'          => $cloud['hash_payload'],
        ] : null,
    ];

    if ($acao === 'push' && $cloud) {
        // Marca pendente_ack (timeout lazy usa aplicada_em)
        $pdo->prepare(
            "UPDATE tabela_limites
                SET sync_status='pendente_ack', aplicada_em=UTC_TIMESTAMP()
              WHERE controlador_id=:cid"
        )->execute([':cid' => $dev['id']]);
        $data['payload_json'] = json_decode($cloud['payload_json'], true);
    }

    echo json_encode(['sucesso' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha de banco'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
