<?php
// ============================================================
// Arquivo: api/geracao/ano.php
// Objetivo: Série mensal de geração (kWh) para um ano — SEM consumo.
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
$ano            = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT)
                ?: (int)($_GET['ano'] ?? date('Y'));

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'controlador_id inválido']);
    exit;
}
if ($ano < 2000 || $ano > 2100) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'ano inválido']);
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

    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(CONVERT_TZ(timestamp_utc, 'UTC', :tz), '%Y-%m') AS mes,
            SUM(potencia_geracao_w * (5.0/60.0)) / 1000 AS geracao_kwh
        FROM telemetria_5min
        WHERE controlador_id = :id
          AND YEAR(CONVERT_TZ(timestamp_utc, 'UTC', :tz2)) = :ano
        GROUP BY mes
        ORDER BY mes ASC
    ");
    $stmt->execute([':tz' => $tz, ':tz2' => $tz, ':id' => $controlador_id, ':ano' => $ano]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mesesMap = [];
    foreach ($rows as $row) {
        $mesesMap[$row['mes']] = (float) $row['geracao_kwh'];
    }

    $tzObj = new DateTimeZone($tz);
    $agora = new DateTime('now', $tzObj);
    $anoAtual = (int) $agora->format('Y');
    $mesAtualNum = (int) $agora->format('m');

    $mesesPt = [
        1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr',
        5 => 'Mai', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'
    ];

    $labels = [];
    $geracao = [];
    $totalKwh = 0.0;
    $picoKwh = 0.0;

    for ($m = 1; $m <= 12; $m++) {
        $mesKey = sprintf('%04d-%02d', $ano, $m);
        $labels[] = $mesesPt[$m];

        if ($ano === $anoAtual && $m > $mesAtualNum) {
            $geracao[] = null;
        } elseif (isset($mesesMap[$mesKey])) {
            $val = round($mesesMap[$mesKey], 3);
            $geracao[] = $val;
            $totalKwh += $val;
            if ($val > $picoKwh) {
                $picoKwh = $val;
            }
        } else {
            $geracao[] = 0.0;
        }
    }

    echo json_encode([
        'sucesso' => true,
        'periodo' => 'ano',
        'tipo_grafico' => 'bar',
        'ano' => $ano,
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
    error_log('[geracao/ano.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro no banco de dados']);
}
