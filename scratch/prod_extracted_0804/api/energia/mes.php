<?php
/**
 * =============================================================================
 * Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo      : api/energia/mes.php
 * Objetivo     : Retorna dados diarios de importacao, geracao, consumo e
 *                exportacao para um mes especifico
 * Dependencias de hardware:
 *   - Servidor com MySQL/MariaDB acessivel via localhost:3306
 *   - Controlador CIP-ESP32S3
 * Dependencias de software:
 *   - PHP 8.3+
 *   - app/auth.php        (requireAuthApi)
 *   - config/app.php
 *   - config/database.php (getDbConnection)
 *   - Tabela: telemetria_5min, controladores
 * Historico de implementacoes:
 *   - 2026-04-12 | v1.0 | Criacao inicial
 *   - 2026-04-12 | v1.1 | Fix critico: config/db.php -> config/database.php
 *                          + getDbConnection() no lugar de $pdo direto
 * =============================================================================
 */

declare(strict_types=1);
use app\helpers\Tenant;   // 👈 ESTA LINHA RESOLVE O ERRO
$is_dev = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local');

ini_set('display_errors', $is_dev ? '1' : '0');
ini_set('display_startup_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';   // 👈 GARANTE QUE A CLASSE
require_once __DIR__ . '/../../app/auth.php';   // 👈 ADICIONA ISTO
requireAuthApi();                                // 👈 ADICIONA ISTO
// ─── Entrada ──────────────────────────────────────────────────


$controlador_id = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
$mes            = filter_input(INPUT_GET, 'mes', FILTER_SANITIZE_SPECIAL_CHARS) ?? date('Y-m');

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'controlador_id invalido']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'formato invalido, use YYYY-MM']);
    exit;
}

try {
    $pdo = getDbConnection();

    // 🛡️ Blindagem multi-tenant: busca controlador JÁ filtrando por empresa
    $sqlCtrl = "
        SELECT id, codigo, apelido, timezone
          FROM controladores
         WHERE id = :id
           " . Tenant::filtroSQL() . "
         LIMIT 1
    ";
   $paramsCtrl = [':id' => $controlador_id];
    Tenant::aplicarParam($paramsCtrl);

    $stmtCtrl = $pdo->prepare($sqlCtrl);
    $stmtCtrl->execute($paramsCtrl);
    $controlador = $stmtCtrl->fetch(PDO::FETCH_ASSOC);

    if (!$controlador) {
        http_response_code(403);
        echo json_encode([
            'sucesso' => false,
            'erro'    => 'Controlador não encontrado ou acesso negado',
        ]);
        exit;
    }

    $tz = $controlador['timezone'] ?: 'America/Sao_Paulo';

    // 🔧 Aliases CORRIGIDOS
    $sql = "
        SELECT
            DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz))                      AS dia,
            SUM(potencia_importada_w     * (5.0/60.0)) / 1000                AS importada_kwh,
            SUM(potencia_exportada_w     * (5.0/60.0)) / 1000                AS exportada_kwh,
            SUM(potencia_geracao_w       * (5.0/60.0)) / 1000                AS geracao_kwh,
            SUM((COALESCE(potencia_importada_w, 0) + COALESCE(potencia_geracao_w, 0) - COALESCE(potencia_exportada_w, 0)) * (5.0/60.0)) / 1000                AS consumo_kwh
        FROM telemetria_5min
        WHERE
            controlador_id = :controlador_id
            AND DATE_FORMAT(CONVERT_TZ(timestamp_utc, 'UTC', :tz2), '%Y-%m') = :mes
        GROUP BY dia
        ORDER BY dia ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tz'             => $tz,
        ':tz2'            => $tz,
        ':controlador_id' => $controlador_id,
        ':mes'            => $mes,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $importada = $exportada = $geracao = $consumo = [];

    foreach ($rows as $row) {
        $ts = strtotime($row['dia'] . ' 12:00:00') * 1000;
        $importada[] = [$ts, round((float)($row['importada_kwh'] ?? 0), 3)];
        $exportada[] = [$ts, round((float)($row['exportada_kwh'] ?? 0), 3)];
        $geracao[]   = [$ts, round((float)($row['geracao_kwh']   ?? 0), 3)];
        $consumo[]   = [$ts, round((float)($row['consumo_kwh']   ?? 0), 3)];
    }

    $totalImportada = round(array_sum(array_column($rows, 'importada_kwh')), 3);
    $totalExportada = round(array_sum(array_column($rows, 'exportada_kwh')), 3);
    $totalGeracao   = round(array_sum(array_column($rows, 'geracao_kwh')),   3);
    $totalConsumo   = round(array_sum(array_column($rows, 'consumo_kwh')),   3);

    $picosImp = array_column($rows, 'importada_kwh');
    $picosExp = array_column($rows, 'exportada_kwh');
    $picosGer = array_column($rows, 'geracao_kwh');
    $picosCon = array_column($rows, 'consumo_kwh');

    echo json_encode([
        'sucesso'              => true,
        'mes'                  => $mes,
        'controlador_id'       => $controlador_id,
        'controlador_codigo'   => $controlador['codigo'],
        'controlador_apelido'  => $controlador['apelido'],
        'timezone'             => $tz,
        'total_registros'      => count($rows),
        'series' => [
            ['name' => 'Importada', 'data' => $importada],
            ['name' => 'Exportada', 'data' => $exportada],
            ['name' => 'Geração',   'data' => $geracao],
            ['name' => 'Consumo',   'data' => $consumo],
        ],
        'resumo' => [
            'total_importada_kwh' => $totalImportada,
            'total_exportada_kwh' => $totalExportada,
            'total_geracao_kwh'   => $totalGeracao,
            'total_consumo_kwh'   => $totalConsumo,
            'saldo_kwh'           => round($totalExportada - $totalImportada, 3),
            'pico_importada_kwh'  => round((float)(count($picosImp) ? max($picosImp) : 0), 3),
            'pico_exportada_kwh'  => round((float)(count($picosExp) ? max($picosExp) : 0), 3),
            'pico_geracao_kwh'    => round((float)(count($picosGer) ? max($picosGer) : 0), 3),
            'pico_consumo_kwh'    => round((float)(count($picosCon) ? max($picosCon) : 0), 3),
        ],
    ], JSON_UNESCAPED_UNICODE);  // 🚫 Removi JSON_PRETTY_PRINT (peso desnecessário em produção)

} catch (PDOException $e) {
    error_log('[mes.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro'    => 'Erro no banco de dados',
    ]);
}
