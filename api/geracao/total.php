<?php
// ============================================================
// Arquivo: api/geracao/total.php
// Objetivo: Série anual consolidada de geração (kWh), todo o histórico — SEM consumo.
//           Gráfico de barras no front.
// Autor: Fernando / CIP Cloud Copilot
// Versão: 1.0.0 | 2026-10-15
// ============================================================
declare(strict_types=1);

$is_dev = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local');
ini_set('display_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';
require_once __DIR__ . '/../../app/auth.php';
requireAuthApi();

use app\helpers\Tenant;

$controlador_id = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT)
                ?: (int)($_GET['controlador_id'] ?? 0);

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'controlador_id inválido']);
    exit;
}

try {
    $pdo = getDbConnection();

    $sqlCtrl = "SELECT id, codigo, apelido, timezone FROM controladores WHERE id = :id " . Tenant::filtroSQL() . " LIMIT 1";
    $paramsCtrl = [':id' => $controlador_id];
    Tenant::aplicarParam($paramsCtrl);
    $stmtCtrl = $pdo->prepare($sqlCtrl);
    $stmtCtrl->execute($paramsCtrl);
    $controlador = $stmtCtrl->fetch(PDO::FETCH_ASSOC);

    if (!$controlador) {
        http_response_code(403);
        echo json_encode(['sucesso' => false, 'erro' => 'Controlador não encontrado ou acesso negado']);
        exit;
    }

    $tz = $controlador['timezone'] ?: 'America/Sao_Paulo';
    $tzObj = new DateTimeZone($tz);
    $anoAtual = (int) (new DateTime('now', $tzObj))->format('Y');
    $anoInicio = $anoAtual - 4;
    $anoFim = $anoAtual;

    $stmt = $pdo->prepare("
        SELECT
            YEAR(CONVERT_TZ(timestamp_utc, 'UTC', :tz)) AS ano,
            SUM(potencia_geracao_w * (5.0/60.0)) / 1000 AS geracao_kwh
        FROM telemetria_5min
        WHERE controlador_id = :id
          AND YEAR(CONVERT_TZ(timestamp_utc, 'UTC', :tz2)) BETWEEN :ano_inicio AND :ano_fim
        GROUP BY ano
        ORDER BY ano ASC
    ");
    $stmt->execute([
        ':tz' => $tz,
        ':tz2' => $tz,
        ':id' => $controlador_id,
        ':ano_inicio' => $anoInicio,
        ':ano_fim' => $anoFim,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $anosMap = [];
    foreach ($rows as $row) {
        $anosMap[(int) $row['ano']] = (float) $row['geracao_kwh'];
    }

    $labels = [];
    $geracao = [];
    $totalKwh = 0.0;
    $picoKwh = 0.0;

    for ($y = $anoInicio; $y <= $anoFim; $y++) {
        $labels[] = (string) $y;
        $val = isset($anosMap[$y]) ? round($anosMap[$y], 3) : 0.0;
        $geracao[] = $val;
        $totalKwh += $val;
        if ($val > $picoKwh) {
            $picoKwh = $val;
        }
    }

    echo json_encode([
        'sucesso' => true,
        'periodo' => 'total',
        'tipo_grafico' => 'bar',
        'controlador_id' => $controlador_id,
        'controlador_apelido' => $controlador['apelido'],
        'timezone' => $tz,
        'labels' => $labels,
        'geracao' => $geracao,
        'resumo' => [
            'total_geracao_kwh' => round($totalKwh, 3),
            'pico_geracao_kwh'  => round($picoKwh, 3),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[geracao/total.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro no banco de dados']);
}
