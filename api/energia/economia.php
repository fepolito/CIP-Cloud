<?php
/**
 * @arquivo       api/energia/economia.php
 * @versao        1.3.2
 * @modificado_em 2026-09-08
 * @objetivo      Endpoint financeiro: economia estimada do dia e do mes (autoconsumo +
 *                crédito de injeção) via TarifaService, agora com variação %. Query própria, tenant-aware.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

// Ambiente detectado por config/app.php (por host: .test/.local/IP privado).
// PROD ('production') nunca exibe erros ao cliente.
$is_dev = (defined('APP_ENV') && APP_ENV === 'development');
ini_set('display_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';

use app\helpers\Tenant;
use app\services\TarifaService;

require_once __DIR__ . '/../../app/services/TarifaService.php';
require_once __DIR__ . '/../../app/helpers/EnergiaCalc.php';

$usuario = authUsuario();

$periodo = (($_GET['periodo'] ?? 'dia') === 'mes') ? 'mes' : 'dia';
$comparar = (($_GET['comparar'] ?? '0') === '1');
$ref = trim((string)($_GET['ref'] ?? $_GET['data'] ?? ''));

$controladorId = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
if ($controladorId === false || $controladorId === null || $controladorId <= 0) {
    $controladorId = filter_var($_GET['controlador_id'] ?? null, FILTER_VALIDATE_INT);
}
if ($controladorId === false || $controladorId === null || $controladorId <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Parametro controlador_id ausente ou invalido', 'detalhe' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Calcula economia (R$) de UMA janela [iniUtc, fimUtc).
 * Reaproveita $sqlData + TarifaService intactos.
 */
function calcularEconomiaJanela(PDO $pdo, int $ctrlId, string $iniUtc, string $fimUtc, float $tarifaKwh, float $fatorInjecao, string $tzStr, float $deltaMax, float $custoDisp, string $modo): array {
    // Checar apenas número de registros
    $st = $pdo->prepare("SELECT COUNT(*) FROM telemetria_5min WHERE controlador_id = :cid AND timestamp_utc >= :ini AND timestamp_utc < :fim");
    $st->execute([':cid' => $ctrlId, ':ini' => $iniUtc, ':fim' => $fimUtc]);
    $n_registros = (int)$st->fetchColumn();

    $importadaKwh = EnergiaCalc::somaDeltas($pdo, $ctrlId, $iniUtc, $fimUtc, 'energia_importada_kwh', $deltaMax);
    $exportadaKwh = EnergiaCalc::somaDeltas($pdo, $ctrlId, $iniUtc, $fimUtc, 'energia_exportada_kwh', $deltaMax);
    $geracaoKwh   = EnergiaCalc::integraPotencia($pdo, $ctrlId, $iniUtc, $fimUtc, 'potencia_geracao_w');
    
    // --- Autoconsumo: energia gerada e usada localmente (não exportada) ---
    $autoconsumoKwh = max(0.0, $geracaoKwh - $exportadaKwh);
    $autoconsumoRs  = $autoconsumoKwh * $tarifaKwh;

    // --- Compensado: importada abatida da disponibilidade, valorada ao fator de injeção ---
    $compensadoKwh = max(0.0, $importadaKwh - $custoDisp);
    $compensadoRs  = $compensadoKwh * $fatorInjecao;

    // --- Total ---
    // --- À Compensar ESTIMADO (projeção do crédito gerado pela exportação) ---
    $aCompensarKwh = $exportadaKwh;
    $aCompensarRs  = $aCompensarKwh * $fatorInjecao;

    $economiaTotalRs = $autoconsumoRs + $compensadoRs;

    // Headline DIA: economia percebida = realizado + projeção de crédito.
    // NÃO confundir com economia_total_rs (contábil, só realizado).
    $estimativaDiaRs = $autoconsumoRs + $aCompensarRs;

    $ret = [
        'importada_kwh'    => round($importadaKwh, 2),
        'exportada_kwh'    => round($exportadaKwh, 2),
        'geracao_kwh'      => round($geracaoKwh, 2),
        'autoconsumo_kwh'  => round($autoconsumoKwh, 2),
        'autoconsumo_rs'   => round($autoconsumoRs, 2),
        'compensado_kwh'   => round($compensadoKwh, 2),
        'compensado_rs'    => round($compensadoRs, 2),
        'a_compensar_kwh'  => round($aCompensarKwh, 2),
        'a_compensar_rs'   => round($aCompensarRs, 2),
        'estimativa_dia_rs'=> round($estimativaDiaRs, 2),
        'modo'             => $modo,
        'economia_total_rs'=> round($economiaTotalRs, 2),
        'total'            => round($economiaTotalRs, 2),
        'tarifa_kwh'       => $tarifaKwh,
        'fator_injecao'    => $fatorInjecao,
        'sem_dados'        => ($n_registros === 0)
    ];
    
    return $ret;
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
        SELECT c.id, c.timezone, c.tarifa_kwh, c.fator_injecao, c.delta_max_kwh, c.custo_disponibilidade_kwh
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
    $tarifaKwh = (float)($controlador['tarifa_kwh'] ?? 0.9482);
    $fatorInjecao = (float)($controlador['fator_injecao'] ?? 0.760);
    $deltaMax = (float)($controlador['delta_max_kwh'] ?? 2.000);
    $custoDisp = (float)($controlador['custo_disponibilidade_kwh'] ?? 100);
    try {
        $tz = new DateTimeZone($tzStr);
    } catch (Exception $e) {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $tzStr = 'America/Sao_Paulo';
    }
    
    $utc   = new DateTimeZone('UTC');
    $hoje = new DateTimeImmutable('now', $tz);

    $limiteInferior = $hoje->modify('-12 month')->setTime(0, 0, 0);

    if ($periodo === 'mes') {
        $base = $ref !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ref . '-01 00:00:00', $tz)
            : $hoje;
        if ($base === false) { throw new InvalidArgumentException('ref inválido (esperado YYYY-MM)'); }

        $ini = $base->modify('first day of this month')->setTime(0, 0, 0);
        $fim = $ini->modify('first day of next month');
    } else {
        $base = $ref !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ref . ' 00:00:00', $tz)
            : $hoje;
        if ($base === false) { throw new InvalidArgumentException('ref inválido (esperado YYYY-MM-DD)'); }

        $ini = $base->setTime(0, 0, 0);
        $fim = $ini->modify('+1 day');
    }

    if ($ini < $limiteInferior) {
        throw new InvalidArgumentException('Período fora da janela permitida (máx. 12 meses).');
    }

    $iniAtual = ($periodo === 'mes')
        ? $hoje->modify('first day of this month')->setTime(0, 0, 0)
        : $hoje->setTime(0, 0, 0);
    $ehPeriodoAtual = ($ini == $iniAtual);

    if ($periodo === 'mes') {
        $iniAnt = $ini->modify('first day of last month');
        if ($ehPeriodoAtual) {
            $deltaDias = (int)$hoje->diff($ini)->format('%a');
            $fimAnt = $iniAnt->modify("+{$deltaDias} day");
        } else {
            $fimAnt = $ini;
        }
    } else {
        $iniAnt = $ini->modify('-1 day');
        $fimAnt = $ini;
    }
    
    $iniUtc = $ini->setTimezone($utc)->format('Y-m-d H:i:s');
    $fimUtc = $fim->setTimezone($utc)->format('Y-m-d H:i:s');
    
    $atual = calcularEconomiaJanela($pdo, (int)$controladorId, $iniUtc, $fimUtc, $tarifaKwh, $fatorInjecao, $tzStr, $deltaMax, $custoDisp, $periodo);
    
    $resp = $atual;
    $resp['ref'] = $ref !== '' ? $ref : ($periodo === 'mes' ? $ini->format('Y-m') : $ini->format('Y-m-d'));
    $resp['periodo_atual'] = $ehPeriodoAtual;

    if ($comparar) {
        $iniAntUtc = $iniAnt->setTimezone($utc)->format('Y-m-d H:i:s');
        $fimAntUtc = $fimAnt->setTimezone($utc)->format('Y-m-d H:i:s');
        $ant = calcularEconomiaJanela($pdo, (int)$controladorId, $iniAntUtc, $fimAntUtc, $tarifaKwh, $fatorInjecao, $tzStr, $deltaMax, $custoDisp, $periodo);

        $tAtual = (float)($periodo === 'dia' ? ($atual['estimativa_dia_rs'] ?? $atual['total']) : ($atual['total'] ?? 0));
        $tAnt   = (float)($periodo === 'dia' ? ($ant['estimativa_dia_rs'] ?? $ant['total']) : ($ant['total'] ?? 0));
        $resp['anterior']     = ['total' => $tAnt];
        $resp['variacao_pct'] = $tAnt > 0 ? round((($tAtual - $tAnt) / $tAnt) * 100, 1) : null;
    }

    echo json_encode(['sucesso' => true, 'data' => $resp]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage(), 'detalhe' => null], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro na consulta de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}
