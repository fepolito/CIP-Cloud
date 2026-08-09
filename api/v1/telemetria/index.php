<?php
declare(strict_types=1);

// =============================================================
// Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo   : api/v1/telemetria/index.php
// Objetivo  : Endpoint REST que recebe telemetria do ESP32 e
//             persiste em telemetria_5min (schema v2 — por fase)
// Metodo    : POST
// Auth      : Header X-CIP-Token vs controladores.token_api_hash
// Historico :
//   2026-04-10  v1.0.0  Criacao
//   2026-05-15  v1.7.0  Alinhado com getDbConnection()
//   2026-05-16  v2.0.0  Schema expandido (por fase, freq A/B/C, diag)
//                       + INSERT ... ON DUPLICATE KEY UPDATE (UPSERT)
// =============================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/database.php';

function reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Pega valor do payload, retorna null se ausente ou não numérico. */
function num(array $d, string $key): ?float {
    return isset($d[$key]) && is_numeric($d[$key]) ? (float) $d[$key] : null;
}

/** Pega valor do payload, retorna null se ausente. Para inteiros. */
function intn(array $d, string $key): ?int {
    return isset($d[$key]) && is_numeric($d[$key]) ? (int) $d[$key] : null;
}

/** Pega bool 0/1, retorna null se ausente. */
function boolnum(array $d, string $key): ?int {
    if (!isset($d[$key])) return null;
    return $d[$key] ? 1 : 0;
}

// ─── 1. Método ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// ─── 2. Token ───────────────────────────────────────────────
$token_raw = $_SERVER['HTTP_X_CIP_TOKEN'] ?? '';
if ($token_raw === '') {
    reply(401, ['ok' => false, 'error' => 'missing_token']);
}
$token_hash = hash('sha256', $token_raw);

// ─── 3. Body ────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// TODO: Remover após o debug
error_log("[telemetria] Payload recebido: " . $raw);

if (!is_array($data) || empty($data['serial'])) {
    reply(400, ['ok' => false, 'error' => 'invalid_json_or_missing_serial']);
}

// ─── 4. Conexão ─────────────────────────────────────────────
try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    error_log('[telemetria] DB connect: ' . $e->getMessage());
    reply(500, ['ok' => false, 'error' => 'db_connect_failed']);
}

// ─── 5. Autenticação ────────────────────────────────────────
$stmt = $pdo->prepare(
    'SELECT id, token_api_hash FROM controladores WHERE codigo = :c LIMIT 1'
);
$stmt->execute([':c' => $data['serial']]);
$ctrl = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ctrl || !hash_equals($ctrl['token_api_hash'], $token_hash)) {
    reply(401, ['ok' => false, 'error' => 'auth_failed']);
}

// ─── 6. Normalizações ───────────────────────────────────────
if (isset($data['ts_unix']) && is_numeric($data['ts_unix'])) {
    $ts_utc = gmdate('Y-m-d H:i:s', (int) $data['ts_unix']);
} elseif (!empty($data['ts'])) {
    $ts_utc = gmdate('Y-m-d H:i:s', strtotime($data['ts']));
} else {
    $ts_utc = gmdate('Y-m-d H:i:s');
}

$status_inversor = null;
if (isset($data['inversor_ok'])) {
    $status_inversor = $data['inversor_ok'] ? 'online' : 'offline';
} elseif (isset($data['status_inversor'])) {
    $status_inversor = (string) $data['status_inversor'];
}

// ─── 7. Mapeamento campos → colunas ─────────────────────────
// Colunas que aceitam INSERT direto do payload (key = nome da coluna)
$colunas_numericas = [
    // Fluxo de potência
    'potencia_importada_w', 'potencia_exportada_w', 'potencia_geracao_w',
    'potencia_consumo_total_w', 'power_injected_w',
    // Energia
    'energia_importada_kwh', 'energia_exportada_kwh', 'energia_geracao_kwh',
    'energia_ativa_total_kwh', 'energia_reativa_total_kvarh',
    'energia_reativa_import_kvarh', 'energia_reativa_export_kvarh',
    // Config
    'limite_exportacao_ativo_w',
    // Tensão
    'tensao_rede_v', 'tensao_fase_a_v', 'tensao_fase_b_v', 'tensao_fase_c_v',
    // Corrente
    'corrente_fase_a_a', 'corrente_fase_b_a', 'corrente_fase_c_a',
    // Potência ativa
    'potencia_ativa_fase_a_w', 'potencia_ativa_fase_b_w',
    'potencia_ativa_fase_c_w', 'potencia_ativa_total_w',
    // Potência reativa
    'potencia_reativa_fase_a_var', 'potencia_reativa_fase_b_var',
    'potencia_reativa_fase_c_var', 'potencia_reativa_total_var',
    // Potência aparente
    'potencia_aparente_fase_a_va', 'potencia_aparente_fase_b_va',
    'potencia_aparente_fase_c_va', 'potencia_aparente_total_va',
    // FP
    'fator_potencia_fase_a', 'fator_potencia_fase_b',
    'fator_potencia_fase_c', 'fator_potencia_total',
    // Frequência
    'frequencia_rede_hz',
    'frequencia_fase_a_hz', 'frequencia_fase_b_hz', 'frequencia_fase_c_hz',
    // Inversor
    'temperatura_inversor_c',
];

$colunas_inteiras = [
    'meter_read_errors', 'meter_retry_recoveries',
    'meter_optional_failures', 'inversor_read_errors',
    'qualidade_dado',
];

$colunas_bool = ['is_exporting', 'direction_valid'];

// ─── 8. Monta INSERT dinâmico com UPSERT ────────────────────
$cols = ['controlador_id', 'timestamp_utc', 'status_inversor', 'firmware_versao'];
$vals = [':cid', ':ts', ':sti', ':fwv'];
$params = [
    ':cid' => $ctrl['id'],
    ':ts'  => $ts_utc,
    ':sti' => $status_inversor,
    ':fwv' => $data['firmware_versao'] ?? null,
];

foreach ($colunas_numericas as $c) {
    $cols[] = $c;
    $vals[] = ":$c";
    $params[":$c"] = num($data, $c);
}
foreach ($colunas_inteiras as $c) {
    $cols[] = $c;
    $vals[] = ":$c";
    $params[":$c"] = intn($data, $c);
}
foreach ($colunas_bool as $c) {
    $cols[] = $c;
    $vals[] = ":$c";
    $params[":$c"] = boolnum($data, $c);
}

// Cláusula UPDATE (todos os campos exceto chaves)
$updates = [];
foreach (array_slice($cols, 2) as $c) {  // pula controlador_id e timestamp_utc
    $updates[] = "$c = VALUES($c)";
}

$sql = 'INSERT INTO telemetria_5min ('
     . implode(', ', $cols)
     . ') VALUES ('
     . implode(', ', $vals)
     . ') ON DUPLICATE KEY UPDATE '
     . implode(', ', $updates);

// ─── 9. Executa ─────────────────────────────────────────────
$inserted_id = 0;
try {
    $ins = $pdo->prepare($sql);
    $ins->execute($params);

    $inserted_id = (int) $pdo->lastInsertId();

    if ($inserted_id === 0) {
        // Caso UPDATE (linha já existia)
        $q = $pdo->prepare(
            'SELECT id FROM telemetria_5min
              WHERE controlador_id = :cid AND timestamp_utc = :ts
              LIMIT 1'
        );
        $q->execute([':cid' => $ctrl['id'], ':ts' => $ts_utc]);
        $inserted_id = (int) $q->fetchColumn();
    }

    // Atualiza heartbeat do controlador
    $pdo->prepare(
        'UPDATE controladores
            SET last_telemetry_at = UTC_TIMESTAMP(),
                last_seen_at      = UTC_TIMESTAMP(),
                online            = 1,
                ultimo_contato    = NOW()
          WHERE id = :id'
    )->execute([':id' => $ctrl['id']]);

} catch (PDOException $e) {
    error_log('[telemetria] DB error: ' . $e->getMessage());
    reply(500, ['ok' => false, 'error' => 'db_error', 'detail' => $e->getMessage()]);
}

reply(200, [
    'ok'        => true,
    'id'        => $inserted_id,
    'stored_at' => $ts_utc,
]);
