<?php
// ============================================================
// Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo      : api/dashboard/dados.php
// Objetivo     : Retorna dados de telemetria para o dashboard
//                (leituras atuais, series temporais, totalizadores,
//                 info do controlador e custo estimado de energia)
// Dependencias de arquivos:
//   - config/database.php   (credenciais e conexao PDO)
//   - app/auth.php          (isAuthenticated)
// Dependencias de hardware:
//   - Controlador CIP-ESP32S3 enviando leituras para leituras_energia
// Tabelas utilizadas:
//   - leituras_energia  (telemetria do controlador)
//   - controladores     (metadados e status do dispositivo)
// Parametros GET:
//   - controlador_id  INT  (obrigatorio)
//   - periodo         STR  1h | 6h | 24h | 7d  (default: 1h)
// Retorno JSON:
//   - success         BOOL
//   - atual           OBJ   ultima leitura
//   - totais          OBJ   agregados do dia (min/max/media/contagem)
//   - series          OBJ   arrays [timestamp_ms, valor] p/ ApexCharts
//   - controlador     OBJ   metadados do dispositivo
//   - custo_dia       FLOAT custo estimado do dia (tarifa R$ 0,85/kWh)
//   - tarifa_kwh      FLOAT tarifa utilizada no calculo
//   - nota_tarifa     STR   aviso sobre tarifa estatica (futura integracao ANEEL)
// Historico:
//   2026-04-08  v1.0.0  Implementacao inicial
//                       - Leitura de leituras_energia e controladores
//                       - Series temporais para ApexCharts (datetime ms)
//                       - Totalizadores diarios (min/max/media/contagem)
//                       - Custo estimado com tarifa fixa R$ 0,85/kWh
//                       - Nota de roadmap: integracao futura com API ANEEL
//                       - Autenticacao via isAuthenticated() (sessao PHP)
//                       - Filtro por periodo: 1h / 6h / 24h / 7d
//   2026-07-12  v1.1.0  [FIX DEB-06] ultimo_ping em ISO-8601 UTC ('Z')
//                       para cálculo correto de idade no browser.
// ============================================================

declare(strict_types=1);

// ── Headers ──────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// ── Dependencias ─────────────────────────────────────────────
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/auth.php';

// ── Autenticacao via sessao PHP ───────────────────────────────
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'erro'    => 'Não autorizado. Sessão inválida ou expirada.',
    ]);
    exit;
}

// ── Parametros de entrada ─────────────────────────────────────
$controlador_id = isset($_GET['controlador_id'])
    ? (int) $_GET['controlador_id']
    : 0;

$periodo_raw = $_GET['periodo'] ?? '1h';
$periodos_validos = ['1h', '6h', '24h', '7d'];
$periodo = in_array($periodo_raw, $periodos_validos, true)
    ? $periodo_raw
    : '1h';

if ($controlador_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'erro'    => 'Parâmetro controlador_id inválido ou ausente.',
    ]);
    exit;
}

// ── Mapa de periodos → intervalo SQL ─────────────────────────
$mapa_periodo = [
    '1h'  => '1 HOUR',
    '6h'  => '6 HOUR',
    '24h' => '24 HOUR',
    '7d'  => '7 DAY',
];
$intervalo_sql = $mapa_periodo[$periodo];

// ── Tarifa de energia ─────────────────────────────────────────
// Agora é carregada dinamicamente da tabela `controladores`
// A integração direta via API ANEEL está prevista no roadmap futuro.

// ── Conexao PDO ───────────────────────────────────────────────
// getDbConnection() definida em config/database.php v1.1.0
try {
    $pdo = getDbConnection();
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'erro'    => 'Serviço de banco de dados indisponível.',
    ]);
    exit;
}


// ─────────────────────────────────────────────────────────────
// QUERY 1 — Ultima leitura do controlador
// ─────────────────────────────────────────────────────────────
$sql_atual = "
    SELECT
        tensao_rede_v AS tensao_v,
        NULL AS corrente_a, -- Na nova tabela temos corrente por fase, no total
        potencia_importada_w - potencia_exportada_w AS potencia_rede_w,
        energia_importada_kwh AS energia_kwh,
        frequencia_rede_hz AS frequencia_hz,
        fator_potencia_total AS fator_potencia,
        'medidor' AS tipo_leitura,
        'total' AS fase,
        timestamp_utc AS timestamp_medicao
    FROM telemetria_5min
    WHERE controlador_id = :cid
    ORDER BY timestamp_utc DESC
    LIMIT 1
";

$stmt = $pdo->prepare($sql_atual);
$stmt->execute([':cid' => $controlador_id]);
$atual_raw = $stmt->fetch(PDO::FETCH_ASSOC);

// Normaliza potencia_kw → potencia_w para compatibilidade com dashboard
$atual = null;
if ($atual_raw) {
    $atual = [
        'tensao_v'          => $atual_raw['tensao_v'],
        'corrente_a'        => $atual_raw['corrente_a'],
        'potencia_w'        => $atual_raw['potencia_rede_w'] !== null
                                ? (float)$atual_raw['potencia_rede_w']
                                : null,
        'energia_kwh'       => $atual_raw['energia_kwh'],
        'frequencia_hz'     => $atual_raw['frequencia_hz'],
        'fator_potencia'    => $atual_raw['fator_potencia'],
        'tipo_leitura'      => $atual_raw['tipo_leitura'],
        'fase'              => $atual_raw['fase'],
        'timestamp_medicao' => $atual_raw['timestamp_medicao'],
    ];
}

// ─────────────────────────────────────────────────────────────
// QUERY 2 — Totalizadores do dia (independente do periodo)
// ─────────────────────────────────────────────────────────────
$sql_totais = "
    SELECT
        MIN(tensao_rede_v)                   AS tensao_min,
        MAX(tensao_rede_v)                   AS tensao_max,
        AVG(tensao_rede_v)                   AS tensao_media,
        MAX(potencia_importada_w - potencia_exportada_w) AS potencia_max,
        AVG(potencia_importada_w - potencia_exportada_w) AS potencia_media,
        AVG(fator_potencia_total)            AS fp_medio,
        COUNT(*)                             AS total_leituras,
        MAX(energia_importada_kwh)           AS energia_kwh_max
    FROM telemetria_5min
    WHERE controlador_id = :cid
      AND DATE(timestamp_utc) = CURDATE()
";

$stmt = $pdo->prepare($sql_totais);
$stmt->execute([':cid' => $controlador_id]);
$totais_raw = $stmt->fetch(PDO::FETCH_ASSOC);

$totais = [
    'tensao_min'      => $totais_raw['tensao_min']      !== null ? round((float)$totais_raw['tensao_min'],   2) : null,
    'tensao_max'      => $totais_raw['tensao_max']      !== null ? round((float)$totais_raw['tensao_max'],   2) : null,
    'tensao_media'    => $totais_raw['tensao_media']    !== null ? round((float)$totais_raw['tensao_media'], 2) : null,
    'potencia_max'    => $totais_raw['potencia_max']    !== null ? round((float)$totais_raw['potencia_max'], 2) : null,
    'potencia_media'  => $totais_raw['potencia_media']  !== null ? round((float)$totais_raw['potencia_media'], 2) : null,
    'fp_medio'        => $totais_raw['fp_medio']        !== null ? round((float)$totais_raw['fp_medio'],     3) : null,
    'total_leituras'  => (int)($totais_raw['total_leituras'] ?? 0),
    'energia_kwh_dia' => $totais_raw['energia_kwh_max'] !== null ? round((float)$totais_raw['energia_kwh_max'], 3) : 0.0,
];

// ─────────────────────────────────────────────────────────────
// QUERY 3 — Series temporais para ApexCharts
//           Retorna arrays no formato [ [timestamp_ms, valor], ... ]
//           Limita a 500 pontos para performance do grafico
// ─────────────────────────────────────────────────────────────
$sql_series = "
    SELECT
        UNIX_TIMESTAMP(timestamp_utc) * 1000  AS ts_ms,
        tensao_rede_v AS tensao_v,
        NULL AS corrente_a,
        (potencia_importada_w - potencia_exportada_w) AS potencia_w,
        frequencia_rede_hz AS frequencia_hz,
        fator_potencia_total AS fator_potencia,
        -- Adicionando geração apenas caso a view consuma (se no futuro o chart suportar duas linhas)
        potencia_geracao_w
    FROM telemetria_5min
    WHERE controlador_id = :cid
      AND timestamp_utc >= NOW() - INTERVAL {$intervalo_sql}
    ORDER BY timestamp_utc ASC
    LIMIT 500
";

$stmt = $pdo->prepare($sql_series);
$stmt->execute([':cid' => $controlador_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Monta arrays separados por grandeza
$series = [
    'potencia'  => [],
    'tensao'    => [],
    'corrente'  => [],
    'freq'      => [],
    'fp'        => [],
];

foreach ($rows as $r) {
    $ts = (float) $r['ts_ms'];

    if ($r['potencia_w']    !== null) $series['potencia'][]  = [$ts, round((float)$r['potencia_w'],    2)];
    if ($r['tensao_v']      !== null) $series['tensao'][]    = [$ts, round((float)$r['tensao_v'],      2)];
    if ($r['corrente_a']    !== null) $series['corrente'][]  = [$ts, round((float)$r['corrente_a'],    3)];
    if ($r['frequencia_hz'] !== null) $series['freq'][]      = [$ts, round((float)$r['frequencia_hz'], 2)];
    if ($r['fator_potencia']!== null) $series['fp'][]        = [$ts, round((float)$r['fator_potencia'],3)];
}

// ─────────────────────────────────────────────────────────────
// QUERY 4 — Metadados do controlador
// ─────────────────────────────────────────────────────────────
$sql_ctrl = "
    SELECT
        id,
        codigo,
        apelido                     AS nome,
        tipo,
        local_instalacao            AS localizacao,
        ip_address,
        status,
        fw_version,
        online,
        ultimo_contato,
        last_seen_at,
        last_telemetry_at,
        tarifa_kwh,
        fator_injecao
    FROM controladores
    WHERE id = :cid
    LIMIT 1
";

$stmt = $pdo->prepare($sql_ctrl);
$stmt->execute([':cid' => $controlador_id]);
$controlador = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Monta campo ultimo_ping unificado (ISO-8601 UTC explícito) ──
// Fix DEB-06: timestamps do banco são UTC (sem marcador). Anexar 'Z'
// para que new Date()/Date.parse() no browser NÃO interprete como
// horário local (causava idade negativa → semáforo mentiroso).
// Prioridade: last_telemetry_at > ultimo_contato > last_seen_at
if ($controlador) {
    $pingRaw =
        $controlador['last_telemetry_at']
        ?? $controlador['ultimo_contato']
        ?? $controlador['last_seen_at']
        ?? null;

    $controlador['ultimo_ping'] = $pingRaw
        ? str_replace(' ', 'T', $pingRaw) . 'Z'
        : null;
}

// ─────────────────────────────────────────────────────────────
// Calculo de custo estimado
// ─────────────────────────────────────────────────────────────
$tarifaKwh = $controlador ? (float) ($controlador['tarifa_kwh'] ?? 0.9482) : 0.9482;
$energia_dia  = $totais['energia_kwh_dia'] ?? 0.0;
$custo_dia    = round($energia_dia * $tarifaKwh, 2);

// ─────────────────────────────────────────────────────────────
// Resposta JSON
// ─────────────────────────────────────────────────────────────
echo json_encode([
    'success'      => true,
    'periodo'      => $periodo,
    'atual'        => $atual,
    'totais'       => $totais,
    'series'       => $series,
    'controlador'  => $controlador,
    'custo_dia'    => $custo_dia,
    'tarifa_kwh'   => $tarifaKwh,
    'nota_tarifa'  => 'Tarifa dinâmica baseada na configuração do controlador.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
