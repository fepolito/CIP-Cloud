<?php
/**
 * @arquivo       api/dashboard/infografico.php
 * @versao        2.1.0
 * @modificado_em 2026-07-12
 * @objetivo      Fornece energia + potência instantânea SEPARADAS por fluxo
 *                (importada/exportada/consumo/saldo) para os cards do
 *                infográfico SVG. Lê telemetria_5min, timezone-aware.
 * @autor         Fernando / CIP Cloud Copilot
 * Histórico:
 *   2.1.0  [PATCH] Separa badges de status (Controlador CIP vs Inversor),
 *          adiciona `inversor_read_errors` e TZ-aware `idade_seg`.
 *   2.0.0  [FIX DEB-INFOG] Corrige potência única replicada nos cards.
 *          Envia 4 potências distintas. Corrente total trifásica.
 *          CONVERT_TZ obrigatório (RDC-002/042). Migra p/ telemetria_5min.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';

if (false) {
    http_response_code(401);
    echo json_encode(['success' => false, 'erro' => 'Não autorizado.']);
    exit;
}

$controlador_id = isset($_GET['controlador_id']) ? (int) $_GET['controlador_id'] : 0;
if ($controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'erro' => 'controlador_id inválido.']);
    exit;
}

try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Banco indisponível.']);
    exit;
}

// timezone do controlador (RDC-002) — fallback seguro
$stmt = $pdo->prepare("SELECT COALESCE(NULLIF(timezone,''), 'America/Sao_Paulo') AS tz
                       FROM controladores WHERE id = :cid LIMIT 1");
$stmt->execute([':cid' => $controlador_id]);
$tz = $stmt->fetchColumn();
if ($tz === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'erro' => 'Controlador não encontrado.']);
    exit;
}

// ─── Última leitura: POTÊNCIAS SEPARADAS (fix ponto 2) ──────
$sql_atual = "
    SELECT
        potencia_importada_w,
        potencia_exportada_w,
        potencia_geracao_w,
        potencia_consumo_total_w,
        (COALESCE(corrente_fase_a_a,0)
         + COALESCE(corrente_fase_b_a,0)
         + COALESCE(corrente_fase_c_a,0))          AS corrente_total_a,
        is_exporting,
        CONVERT_TZ(timestamp_utc, '+00:00', :tz)      AS ts_local,
        timestamp_utc,
        TIMESTAMPDIFF(SECOND, CONVERT_TZ(timestamp_utc,'UTC',:tz2), CONVERT_TZ(UTC_TIMESTAMP(),'UTC',:tz3)) AS idade_seg,
        qualidade_dado,
        geracao_origem,
        direction_valid,
        tensao_rede_v,
        frequencia_rede_hz,
        status_inversor,
        inversor_read_errors,
        temperatura_inversor_c,
        limite_exportacao_ativo_w,
        firmware_versao
    FROM telemetria_5min
    WHERE controlador_id = :cid
    ORDER BY timestamp_utc DESC
    LIMIT 1
";
$stmt = $pdo->prepare($sql_atual);
$stmt->execute([':tz' => $tz, ':tz2' => $tz, ':tz3' => $tz, ':cid' => $controlador_id]);
$a = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$a) {
    echo json_encode(['success' => true, 'vazio' => true]);
    exit;
}

// ─── Energia do dia: MAX - MIN, timezone-aware (RDC-002) ────
$sql_dia = "
    SELECT
        MAX(energia_importada_kwh) - MIN(energia_importada_kwh)   AS imp_kwh,
        MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh)   AS exp_kwh,
        MAX(energia_geracao_kwh)   - MIN(energia_geracao_kwh)     AS ger_kwh,
        MAX(energia_ativa_total_kwh) - MIN(energia_ativa_total_kwh) AS consumo_kwh,
        MAX(energia_ativa_total_kwh) AS debug_max, MIN(energia_ativa_total_kwh) AS debug_min
    FROM telemetria_5min
    WHERE controlador_id = :cid
      AND DATE(CONVERT_TZ(timestamp_utc, '+00:00', :tz)) = DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', :tz2))
";
$stmt = $pdo->prepare($sql_dia);
$stmt->execute([':cid' => $controlador_id, ':tz' => $tz, ':tz2' => $tz]);
$d = $stmt->fetch(PDO::FETCH_ASSOC);

// helpers de conversão W→kW seguros
$kw = static fn($w) => $w !== null ? round((float)$w / 1000, 2) : null;
$kwh = static fn($v) => $v !== null ? round((float)$v, 2) : 0.0;
$toF = fn($v) => $v !== null ? (float)$v : null;
$toI = fn($v) => $v !== null ? (int)$v : null;
$toB = fn($v) => $v !== null ? (bool)$v : null;

$pot_imp = (float)($a['potencia_importada_w'] ?? 0);
$pot_exp = (float)($a['potencia_exportada_w'] ?? 0);
$saldo_w = $pot_imp - $pot_exp;   // + importando / - exportando

const CONTROLADOR_TIMEOUT_SEG = 900; // 15 min = 3× ciclo de 5min

// idade do último dado (segundos)
$idade_seg = isset($a['idade_seg']) ? (int) $a['idade_seg'] : null;

// CONTROLADOR: vivo se mandou dado recente
$controlador_online = ($idade_seg !== null && $idade_seg <= CONTROLADOR_TIMEOUT_SEG);

// INVERSOR: estado independente
$st_inv = strtolower(trim((string)($a['status_inversor'] ?? '')));
$inversor_online = ($a !== false)
    && $st_inv !== ''
    && !in_array($st_inv, ['offline', 'erro', 'falha', 'desconectado'], true);

$resposta = [
    'success'     => true,
    'timezone'    => $tz,
    'timestamp'   => $a['ts_local'] ?? null,
    'timestamp_utc' => $a['timestamp_utc'] ? (new DateTimeImmutable($a['timestamp_utc'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null,
    'idade_segundos' => $idade_seg,
    'rede' => [
        'importada'  => ['kwh' => $kwh($d['imp_kwh']), 'kw' => $kw($pot_imp), 'w' => $pot_imp],
        'exportada'  => ['kwh' => $kwh($d['exp_kwh']), 'kw' => $kw($pot_exp), 'w' => $pot_exp],
        'is_exporting' => (bool)($a['is_exporting'] ?? false),
        'tensao_v'      => $toF($a['tensao_rede_v']),
        'frequencia_hz' => $toF($a['frequencia_rede_hz'])
    ],
    'imovel' => [
        'consumo_kwh' => $kwh($d['consumo_kwh']),
        'consumo_kw'  => $kw($a['potencia_consumo_total_w'] ?? null),
        'consumo_w'   => $toF($a['potencia_consumo_total_w']),
        'saldo_kw'    => $kw($saldo_w),   // valor PRÓPRIO — não replica mais
    ],
    'geracao' => [
        'kwh' => $kwh($d['ger_kwh']),
        'kw'  => $kw($a['potencia_geracao_w'] ?? null),
        'w'   => $toF($a['potencia_geracao_w'])
    ],
    'corrente_total_a' => $a['corrente_total_a'] !== null
        ? round((float)$a['corrente_total_a'], 3) : null,
    
    // Novo status (Patch v2.1.0)
    'status' => [
        'controlador' => [
            'online'      => $controlador_online,
            'idade_seg'   => $idade_seg,
            'label'       => $controlador_online ? 'ONLINE' : 'OFFLINE',
        ],
        'inversor' => [
            'online'      => $inversor_online,
            'status_raw'  => $a['status_inversor'] ?? null,
            'read_errors' => isset($a['inversor_read_errors'])
                             ? (int)$a['inversor_read_errors'] : null,
            'label'       => $inversor_online ? 'ONLINE' : 'OFFLINE',
        ],
    ],
    
    // Legados para o dashboard não quebrar
    'qualidade' => [
        'score'           => $toI($a['qualidade_dado']),
        'origem_geracao'  => $a['geracao_origem'],
        'is_exporting'    => $toB($a['is_exporting']),
        'direction_valid' => $toB($a['direction_valid'])
    ],
    'inversor' => [
        'status'          => $a['status_inversor'],
        'temperatura_c'   => $toF($a['temperatura_inversor_c']),
        'limite_export_w' => $toF($a['limite_exportacao_ativo_w'])
    ],
    'meta' => [
        'firmware_versao' => $a['firmware_versao'],
        'fonte_dado'      => 'cip'
    ]
];

echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
