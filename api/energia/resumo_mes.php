<?php
/**
 * Historico:
 *   2026-06-05  v1.0.0  Criacao
 *   2026-06-06  v1.1.0  Correcao: consumo_total agora e calculado em PHP.
 *                       Motivo: firmware nao popula energia_ativa_total_kwh
 *                       (sempre 0.0000). Workaround documentado, com flag
 *                       CALCULAR_CONSUMO_NO_PHP para reversao futura quando
 *                       firmware for corrigido (ver docs/CONTRATO_API.md).
 *   2026-06-06  v1.1.1  Correcao: alias SQL "consumo_kwh_firmware_kwh_firmware"
 *                       estava duplicado, renomeado para "consumo_kwh_firmware".
 *                       Sem impacto funcional enquanto CALCULAR_CONSUMO_NO_PHP=true,
 *                       mas evita bug silencioso em reversao futura.
 */
declare(strict_types=1);

// ─── Flag de comportamento ────────────────────────────────────────
// TRUE  → cloud calcula consumo_total via formula canonica (estado atual)
// FALSE → cloud le energia_ativa_total_kwh direto do banco (quando firmware corrigir)
const CALCULAR_CONSUMO_NO_PHP = true;

$is_dev = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local')
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.test');

ini_set('display_errors', $is_dev ? '1' : '0');
ini_set('display_startup_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

// Entrada
$controlador_id = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
$data = filter_input(INPUT_GET, 'data', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro controlador_id inválido ou ausente.', 'detalhe' => null]);
    exit;
}

if (!$data || !preg_match('/^\d{4}-\d{2}$/', $data)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro data inválido. Use o formato YYYY-MM.', 'detalhe' => null]);
    exit;
}

$parts = explode('-', $data);
if (!checkdate((int)$parts[1], 1, (int)$parts[0])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Mês inválido.', 'detalhe' => null]);
    exit;
}

try {
    $pdo = getDbConnection();

    // Validar controlador
    $stmtCtrl = $pdo->prepare("SELECT id, codigo, apelido, timezone FROM controladores WHERE id = :id LIMIT 1");
    $stmtCtrl->execute([':id' => $controlador_id]);
    $controlador = $stmtCtrl->fetch(PDO::FETCH_ASSOC);

    if (!$controlador) {
        http_response_code(404);
        echo json_encode(['erro' => "Controlador ID {$controlador_id} não encontrado.", 'detalhe' => null]);
        exit;
    }

    $timezone = $controlador['timezone'] ?? 'America/Sao_Paulo';

    // Headers de cache
    $dtMesAtual = new DateTime('now', new DateTimeZone($timezone));
    $mesLocal = $dtMesAtual->format('Y-m');
    if ($data < $mesLocal) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache, must-revalidate');
    }

    // Consulta de agregados por dia para o mes inteiro
    $start_date = $data . '-01';
    
    $sql = "
        SELECT 
            DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz)) AS dia,
            COALESCE(MAX(energia_geracao_kwh) - MIN(energia_geracao_kwh), 0) AS geracao_kwh,
            COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh,
            COALESCE(MAX(energia_importada_kwh) - MIN(energia_importada_kwh), 0) AS importada_kwh,
            COALESCE(MAX(energia_ativa_total_kwh) - MIN(energia_ativa_total_kwh), 0) AS consumo_kwh_firmware
        FROM telemetria_5min
        WHERE controlador_id = :id
          AND DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz2)) >= :start_date
          AND DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz3)) <= LAST_DAY(:start_date2)
        GROUP BY dia
        ORDER BY dia ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $controlador_id,
        ':tz' => $timezone,
        ':tz2' => $timezone,
        ':tz3' => $timezone,
        ':start_date' => $start_date,
        ':start_date2' => $start_date
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totais = [
        "geracao" => 0.0,
        "exportada" => 0.0,
        "importada" => 0.0,
        "consumo_total" => 0.0,
        "autoconsumo" => 0.0
    ];

    $por_dia = [];
    $dias_com_dados = 0;

    foreach ($rows as $r) {
        $g = (float) $r['geracao_kwh'];
        $e = (float) $r['exportada_kwh'];
        $i = (float) $r['importada_kwh'];
        $c_firmware = (float) $r['consumo_kwh_firmware'];

        // autoconsumo = max(0, geracao - exportada)
        $a = $g - $e;
        if ($a < 0) {
            error_log(sprintf(
                '[resumo_mes.php] Anomalia: autoconsumo negativo | ctrl=%d | dia=%s | geracao=%.4f | exportada=%.4f',
                $controlador_id, $r['dia'], $g, $e
            ));
            $a = 0.0;
        }

        // consumo_total: calculado em PHP OU lido do firmware (controlado por flag)
        $c = CALCULAR_CONSUMO_NO_PHP ? ($a + $i) : $c_firmware;

        $totais['geracao'] += $g;
        $totais['exportada'] += $e;
        $totais['importada'] += $i;
        $totais['consumo_total'] += $c;
        $totais['autoconsumo'] += $a;

        $por_dia[] = [
            "data" => $r['dia'],
            "geracao" => round($g, 4),
            "exportada" => round($e, 4),
            "importada" => round($i, 4),
            "consumo" => round($c, 4)
        ];
        
        $dias_com_dados++;
    }

    $dtBase = new DateTime($start_date);
    $dias_no_mes = (int) $dtBase->format('t');
    $cobertura_pct = round(($dias_com_dados / $dias_no_mes) * 100, 1);

    $resposta = [
        "sucesso" => true,
        "mes" => $data,
        "controlador_id" => $controlador_id,
        "controlador_codigo" => $controlador['codigo'],
        "controlador_apelido" => $controlador['apelido'],
        "timezone" => $timezone,
        "totais_mes_kwh" => [
            "geracao" => round($totais['geracao'], 4),
            "exportada" => round($totais['exportada'], 4),
            "importada" => round($totais['importada'], 4),
            "consumo_total" => round($totais['consumo_total'], 4),
            "autoconsumo" => round($totais['autoconsumo'], 4)
        ],
        "por_dia" => $por_dia,
        "qualidade" => [
            "dias_com_dados" => $dias_com_dados,
            "dias_no_mes" => $dias_no_mes,
            "cobertura_pct" => $cobertura_pct,
            "fonte_consumo" => CALCULAR_CONSUMO_NO_PHP ? 'cloud_calculado' : 'firmware'
        ]
    ];

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[resumo_mes.php] PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno no banco de dados.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
} catch (Throwable $e) {
    error_log('[resumo_mes.php] Erro: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno do servidor.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
}

/*
Exemplo de curl:
curl -i "http://monitor.aeonium.com.br.test/api/energia/resumo_mes.php?controlador_id=3&data=2026-06"
*/
