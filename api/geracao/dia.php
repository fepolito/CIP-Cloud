<?php
// ============================================================
// Arquivo: api/geracao/dia.php
// Objetivo: Série 5min de geração (kW) para um dia — SEM consumo/importada/exportada.
//           Gráfico de linha no front.
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
$data           = filter_input(INPUT_GET, 'data', FILTER_DEFAULT)
                ?: (string)($_GET['data'] ?? '');

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'controlador_id inválido']);
    exit;
}
if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'data inválida, use YYYY-MM-DD']);
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
    $hoje_local = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');
    header($data < $hoje_local ? 'Cache-Control: public, max-age=3600' : 'Cache-Control: no-cache, must-revalidate');

    $stmt = $pdo->prepare("
        SELECT
            FLOOR(UNIX_TIMESTAMP(timestamp_utc) / 300) * 300 AS bucket_utc,
            DATE_FORMAT(CONVERT_TZ(FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(timestamp_utc) / 300) * 300), 'UTC', :tz), '%H:%i') AS label,
            ROUND(AVG(potencia_geracao_w) / 1000, 3) AS geracao
        FROM telemetria_5min
        WHERE controlador_id = :id
          AND DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz2)) = :data
        GROUP BY bucket_utc, label
        ORDER BY bucket_utc ASC
    ");
    $stmt->execute([':tz' => $tz, ':tz2' => $tz, ':id' => $controlador_id, ':data' => $data]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bucketMap = [];
    foreach ($rows as $row) {
        $bucketMap[$row['label']] = (float) $row['geracao'];
    }

    $tzObj = new DateTimeZone($tz);
    $agora = new DateTime('now', $tzObj);
    $hojeLocal = $agora->format('Y-m-d');
    $horaAtual = $agora->format('H:i');

    $labels = [];
    $geracao = [];
    $totalKwh = 0.0;
    $picoKw = 0.0;

    for ($h = 0; $h < 24; $h++) {
        for ($m = 0; $m < 60; $m += 5) {
            $lbl = sprintf('%02d:%02d', $h, $m);
            $labels[] = $lbl;

            if ($data === $hojeLocal && $lbl > $horaAtual) {
                $geracao[] = null;
            } elseif (isset($bucketMap[$lbl])) {
                $val = $bucketMap[$lbl];
                $geracao[] = $val;
                $totalKwh += $val * (5.0 / 60.0);
                if ($val > $picoKw) {
                    $picoKw = $val;
                }
            } else {
                $geracao[] = 0.0;
            }
        }
    }

    echo json_encode([
        'sucesso' => true,
        'periodo' => 'dia',
        'tipo_grafico' => 'line',
        'data' => $data,
        'controlador_id' => $controlador_id,
        'controlador_apelido' => $controlador['apelido'],
        'timezone' => $tz,
        'labels' => $labels,
        'geracao' => $geracao,
        'resumo' => [
            'total_geracao_kwh' => round($totalKwh, 3),
            'pico_geracao_kw'   => round($picoKw, 3),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[geracao/dia.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro no banco de dados']);
}
