<?php
// ============================================================
// Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo      : api/energia/media_12m.php
// Versao       : v1.0.0
// Data         : 2026-06-07
// Objetivo     : Retornar media de consumo dos ultimos 12 meses fechados e delta com mes corrente
// Dependencias : config/app.php, config/database.php, app/auth.php, app/helpers/Tenant.php
// Tabelas      : controladores, telemetria_5min
// Parametros   : controlador_id (int)
// Retorno      : JSON com agregacao de 12 meses
// Historico    :
//   2026-06-07  v1.0.0  Criacao inicial
// ============================================================

declare(strict_types=1);

$serverName = $_SERVER['SERVER_NAME'] ?? '';
$is_dev = ($serverName === 'localhost' 
        || str_ends_with($serverName, '.local') 
        || str_ends_with($serverName, '.test'));

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
        SELECT c.id, c.codigo, c.apelido, c.timezone, c.modo_controle, 
               c.controle_exportacao_ativo, c.potencia_nominal_kw, c.potencia_pico_90d_kw
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
    
    $dtInicio = (new DateTimeImmutable('first day of this month', $tz))
                  ->modify('-12 months')
                  ->setTime(0, 0, 0);
    $dtFim    = (new DateTimeImmutable('first day of this month', $tz))
                  ->setTime(0, 0, 0);
    
    $inicioUtc = $dtInicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fimUtc    = $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    $sqlMedia = "
        WITH meses_fechados AS (
          SELECT 
            DATE_FORMAT(CONVERT_TZ(timestamp_utc, 'UTC', :tz_str), '%Y-%m') AS mes_ref,
            MAX(energia_importada_kwh) - MIN(energia_importada_kwh) AS importada_kwh,
            MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh) AS exportada_kwh,
            MAX(energia_geracao_kwh)   - MIN(energia_geracao_kwh)   AS geracao_kwh,
            (MAX(energia_importada_kwh) - MIN(energia_importada_kwh)) -
            (MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh)) AS consumo_kwh
          FROM telemetria_5min
          WHERE controlador_id = :cid
            AND timestamp_utc >= :inicio_utc
            AND timestamp_utc <  :fim_utc
          GROUP BY mes_ref
        )
        SELECT
          COUNT(*) AS meses_computados,
          COALESCE(AVG(importada_kwh), 0) AS media_importada_kwh,
          COALESCE(AVG(exportada_kwh), 0) AS media_exportada_kwh,
          COALESCE(AVG(geracao_kwh),   0) AS media_geracao_kwh,
          COALESCE(AVG(consumo_kwh),   0) AS media_consumo_kwh
        FROM meses_fechados;
    ";
    
    $stmtMedia = $pdo->prepare($sqlMedia);
    $stmtMedia->execute([
        ':tz_str'     => $tzStr,
        ':cid'        => $controladorId,
        ':inicio_utc' => $inicioUtc,
        ':fim_utc'    => $fimUtc
    ]);
    $linhaMedia = $stmtMedia->fetch(PDO::FETCH_ASSOC);
    if (!$linhaMedia) {
        $linhaMedia = [
            'meses_computados' => 0, 'media_importada_kwh' => 0, 'media_exportada_kwh' => 0,
            'media_geracao_kwh' => 0, 'media_consumo_kwh' => 0
        ];
    }
    
    $dtFimMesCorrente = $dtFim->modify('+1 month');
    $inicioMesUtc = $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fimMesUtc = $dtFimMesCorrente->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    $sqlMesCorrente = "
        SELECT 
          COALESCE(
            (MAX(energia_importada_kwh) - MIN(energia_importada_kwh)) -
            (MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh)),
            0
          ) AS consumo_parcial_kwh
        FROM telemetria_5min
        WHERE controlador_id = :cid
          AND timestamp_utc >= :inicio_mes_utc
          AND timestamp_utc <  :fim_mes_utc;
    ";
    $stmtMesCorrente = $pdo->prepare($sqlMesCorrente);
    $stmtMesCorrente->execute([
        ':cid' => $controladorId,
        ':inicio_mes_utc' => $inicioMesUtc,
        ':fim_mes_utc' => $fimMesUtc
    ]);
    $linhaMesCorrente = $stmtMesCorrente->fetch(PDO::FETCH_ASSOC);
    $consumoParcial = (float) ($linhaMesCorrente['consumo_parcial_kwh'] ?? 0);
    
    $agora = new DateTimeImmutable('now', $tz);
    $diaAtual = (int) $agora->format('j');
    $diasNoMes = (int) $agora->format('t');
    
    $projecaoMes = ($diaAtual > 0) ? ($consumoParcial / $diaAtual) * $diasNoMes : 0.0;
    
    $mediaConsumo = (float) $linhaMedia['media_consumo_kwh'];
    $deltaKwh = $projecaoMes - $mediaConsumo;
    $deltaPct = ($mediaConsumo > 0) ? ($deltaKwh / $mediaConsumo) * 100.0 : 0.0;
    
    if ($mediaConsumo == 0) {
        $deltaPct = 0.0;
        $tendencia = 'estavel';
    } else {
        if ($deltaPct > 5.0) {
            $tendencia = 'acima';
        } elseif ($deltaPct < -5.0) {
            $tendencia = 'abaixo';
        } else {
            $tendencia = 'estavel';
        }
    }
    
    $mesesComputados = (int)$linhaMedia['meses_computados'];
    $historicoSuficiente = ($mesesComputados >= 3);
    
    $toKw = fn($w) => $w !== null ? round((float)$w / 1000.0, 3) : null;
    $toFloat = fn($val) => $val !== null ? (float)$val : null;
    $toRound = fn($val, $prec) => $val !== null ? round((float)$val, $prec) : null;
    
    $resposta = [
        'sucesso' => true,
        'controlador_id' => (int)$controlador['id'],
        'timezone' => $tzStr,
        'periodo_referencia' => [
            'tipo'             => '12_meses_fechados',
            'inicio_local'     => $dtInicio->format('Y-m-d'),
            'fim_local'        => $dtFim->format('Y-m-d'),
            'meses_computados' => $mesesComputados,
            'meses_esperados'  => 12
        ],
        'media_mensal_kwh' => [
            'importada' => $toRound($linhaMedia['media_importada_kwh'], 3),
            'exportada' => $toRound($linhaMedia['media_exportada_kwh'], 3),
            'geracao'   => $toRound($linhaMedia['media_geracao_kwh'], 3),
            'consumo'   => $toRound($linhaMedia['media_consumo_kwh'], 3)
        ],
        'comparativo_mes_corrente' => $historicoSuficiente ? [
            'dia_atual'             => $diaAtual,
            'dias_no_mes'           => $diasNoMes,
            'consumo_parcial_kwh'   => $toRound($consumoParcial, 3),
            'consumo_projetado_kwh' => $toRound($projecaoMes, 3),
            'media_12m_kwh'         => $toRound($mediaConsumo, 3),
            'delta_kwh'             => $toRound($deltaKwh, 3),
            'delta_percentual'      => $toRound($deltaPct, 1),
            'tendencia'             => $tendencia,
            'metodo_projecao'       => 'linear_simples'
        ] : null,
        'observacoes' => [
            'historico_suficiente'     => $historicoSuficiente,
            'minimo_meses_recomendado' => 3
        ]
    ];
    
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[media_12m.php] PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno de banco de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[media_12m.php] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}

/*
Exemplo de curl:
curl -i -b "PHPSESSID=xxx" "http://monitor.aeonium.com.br.test/api/energia/media_12m.php?controlador_id=3"
*/
