<?php
/**
 * @arquivo       api/energia/economia.php
 * @versao        1.0.0
 * @modificado_em 2026-07-18
 * @objetivo      Endpoint financeiro: economia estimada do dia (autoconsumo +
 *                crédito de injeção) via TarifaService. Query própria, tenant-aware.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

$is_dev = true; // FORCE DEV TO SEE ERROR
if ($is_dev) {
    ini_set('display_errors', '1');
} else {
    ini_set('display_errors', '0');
}
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';

use app\helpers\Tenant;
use app\services\TarifaService;

require_once __DIR__ . '/../../app/services/TarifaService.php';

$usuario = authUsuario();

$controladorId = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
if ($controladorId === false || $controladorId === null || $controladorId <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Parametro controlador_id ausente ou invalido', 'detalhe' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['sucesso' => false, 'erro' => 'Banco de dados indisponivel', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $filtroTenant = Tenant::filtroSQL('c');
    $sqlCtrl = "
        SELECT c.id, c.timezone
          FROM controladores c
         WHERE c.id = :id
           {$filtroTenant}
         LIMIT 1
    ";
    
    $paramsCtrl = [':id' => $controladorId];
    Tenant::aplicarParam($paramsCtrl);
    
    $stmtCtrl = $pdo->prepare($sqlCtrl);
    $stmtCtrl->execute($paramsCtrl);
    $controlador = $stmtCtrl->fetch(PDO::FETCH_ASSOC);
    
    if (!$controlador) {
        $stmtCheck = $pdo->prepare("SELECT id FROM controladores WHERE id = :id LIMIT 1");
        $stmtCheck->execute([':id' => $controladorId]);
        if ($stmtCheck->fetch()) {
            http_response_code(403);
            echo json_encode(['sucesso' => false, 'erro' => 'Acesso negado a este controlador', 'detalhe' => null], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['sucesso' => false, 'erro' => 'Controlador nao encontrado', 'detalhe' => null], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    $tzStr = $controlador['timezone'] ?: 'America/Sao_Paulo';
    try {
        $tz = new DateTimeZone($tzStr);
    } catch (Exception $e) {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $tzStr = 'America/Sao_Paulo';
    }
    
    $dtInicio = new DateTimeImmutable('today', $tz);
    $dtFim    = $dtInicio->modify('+1 day');
    
    $inicioUtc = $dtInicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fimUtc    = $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    $sqlData = "
        SELECT 
          COALESCE(SUM(energia_geracao_kwh), 0) AS geracao_kwh,
          COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh
        FROM telemetria_5min
        WHERE controlador_id = :ctrl_id
          AND timestamp_utc >= :ini
          AND timestamp_utc < :fim
    ";
    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute([
        ':ctrl_id' => $controladorId,
        ':ini'     => $inicioUtc,
        ':fim'     => $fimUtc
    ]);
    
    $data = $stmtData->fetch(PDO::FETCH_ASSOC);
    
    $geracaoKwh   = (float)$data['geracao_kwh'];
    $exportadaKwh = (float)$data['exportada_kwh'];
    
    $eco = TarifaService::economia($geracaoKwh, $exportadaKwh);
    echo json_encode(['sucesso' => true, 'data' => $eco]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro na consulta de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}
