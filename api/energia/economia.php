<?php
/**
 * @arquivo       api/energia/economia.php
 * @versao        1.2.0
 * @modificado_em 2026-07-19
 * @objetivo      Endpoint financeiro: economia estimada do dia e do mes (autoconsumo +
 *                crédito de injeção) via TarifaService, agora com variação %. Query própria, tenant-aware.
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

$periodo = (($_GET['periodo'] ?? 'dia') === 'mes') ? 'mes' : 'dia';
$comparar = (($_GET['comparar'] ?? '0') === '1');

$controladorId = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
if ($controladorId === false || $controladorId === null || $controladorId <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Parametro controlador_id ausente ou invalido', 'detalhe' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Calcula economia (R$) de UMA janela [iniUtc, fimUtc).
 * Reaproveita $sqlData + TarifaService intactos.
 */
function calcularEconomiaJanela(PDO $pdo, int $ctrlId, string $iniUtc, string $fimUtc): array {
    $sqlData = "
        SELECT 
          COALESCE(SUM(energia_geracao_kwh), 0) AS geracao_kwh,
          COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh
        FROM telemetria_5min
        WHERE controlador_id = :ctrl_id
          AND timestamp_utc >= :ini
          AND timestamp_utc <  :fim
    ";
    $st = $pdo->prepare($sqlData);
    $st->execute([':ctrl_id' => $ctrlId, ':ini' => $iniUtc, ':fim' => $fimUtc]);
    $data = $st->fetch(PDO::FETCH_ASSOC) ?: ['geracao_kwh' => 0, 'exportada_kwh' => 0];
    
    $geracaoKwh   = (float)$data['geracao_kwh'];
    $exportadaKwh = (float)$data['exportada_kwh'];
    
    return TarifaService::economia($geracaoKwh, $exportadaKwh);
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
    
    $utc   = new DateTimeZone('UTC');
    $agora = new DateTimeImmutable('now', $tz);

    if ($periodo === 'mes') {
        $ini  = new DateTimeImmutable('first day of this month 00:00:00', $tz);
        $fim  = $ini->modify('first day of next month');
        // anterior: mesmo recorte parcial do mês passado
        $iniAnt = $ini->modify('first day of last month');
        $deltaDias = (int)$agora->diff($ini)->format('%a'); // dias decorridos no mês atual
        $fimAnt = $iniAnt->modify("+{$deltaDias} day");
    } else {
        $ini  = new DateTimeImmutable('today', $tz);
        $fim  = $ini->modify('+1 day');
        // anterior: dia inteiro anterior
        $iniAnt = $ini->modify('-1 day');
        $fimAnt = $ini;
    }
    
    $iniUtc = $ini->setTimezone($utc)->format('Y-m-d H:i:s');
    $fimUtc = $fim->setTimezone($utc)->format('Y-m-d H:i:s');
    
    $atual = calcularEconomiaJanela($pdo, (int)$controladorId, $iniUtc, $fimUtc);
    $resp  = $atual;

    if ($comparar) {
        $iniAntUtc = $iniAnt->setTimezone($utc)->format('Y-m-d H:i:s');
        $fimAntUtc = $fimAnt->setTimezone($utc)->format('Y-m-d H:i:s');
        $ant = calcularEconomiaJanela($pdo, (int)$controladorId, $iniAntUtc, $fimAntUtc);

        $tAtual = (float)($atual['total'] ?? 0);
        $tAnt   = (float)($ant['total'] ?? 0);
        $resp['anterior']     = ['total' => $tAnt];
        $resp['variacao_pct'] = $tAnt > 0 ? round((($tAtual - $tAnt) / $tAnt) * 100, 1) : null;
    }

    echo json_encode(['sucesso' => true, 'data' => $resp]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro na consulta de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}
