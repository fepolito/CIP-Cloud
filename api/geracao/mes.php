<?php
// ============================================================
// Arquivo: api/geracao/mes.php
// Objetivo: Série diária de geração (kWh) para um mês — SEM consumo.
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
$mes            = filter_input(INPUT_GET, 'mes', FILTER_DEFAULT)
                ?: (string)($_GET['mes'] ?? date('Y-m'));

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'controlador_id inválido']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'formato inválido, use YYYY-MM']);
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
    $inicio = date('Y-m-01', strtotime($mes . '-01'));
    $fim    = date('Y-m-t',  strtotime($mes . '-01'));

    $stmt = $pdo->prepare("
        SELECT
            DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz)) AS dia,
            SUM(potencia_geracao_w * (5.0/60.0)) / 1000 AS geracao_kwh
        FROM telemetria_5min
        WHERE controlador_id = :id
          AND DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz2)) BETWEEN :inicio AND :fim
        GROUP BY dia
        ORDER BY dia ASC
    ");
    $stmt->execute([':tz' => $tz, ':tz2' => $tz, ':id' => $controlador_id, ':inicio' => $inicio, ':fim' => $fim]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $diasNoMes = (int) date('t', strtotime($mes . '-01'));

    $diasMap = [];
    foreach ($rows as $row) {
        $diasMap[$row['dia']] = (float) $row['geracao_kwh'];
    }

    $tzObj = new DateTimeZone($tz);
    $agora = new DateTime('now', $tzObj);
    $mesAtualLocal = $agora->format('Y-m');
    $diaHoje = (int) $agora->format('d');

    $labels = [];
    $geracao = [];
    $totalKwh = 0.0;
    $picoKwh = 0.0;

    for ($d = 1; $d <= $diasNoMes; $d++) {
        $diaStr = sprintf('%s-%02d', $mes, $d);
        $labels[] = sprintf('%02d', $d);

        if ($mes === $mesAtualLocal && $d > $diaHoje) {
            $geracao[] = null;
        } elseif (isset($diasMap[$diaStr])) {
            $val = round($diasMap[$diaStr], 3);
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
        'periodo' => 'mes',
        'tipo_grafico' => 'bar',
        'mes' => $mes,
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
    error_log('[geracao/mes.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro no banco de dados']);
}
