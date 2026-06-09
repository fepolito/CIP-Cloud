<?php
// ============================================================
// Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo      : api/energia/instantaneo.php
// Versao       : v1.0.1
// Data         : 2026-06-07
// Objetivo     : Retornar a ultima leitura disponivel da telemetria_5min de um controlador para o dashboard
// Dependencias : config/app.php, config/database.php, app/auth.php, app/helpers/Tenant.php
// Tabelas      : controladores, telemetria_5min
// Parametros   : controlador_id (int)
// Retorno      : JSON com dados detalhados instantaneos da telemetria (potencias, rede, cos fi, limites)
// Historico    :
//   2026-06-07  v1.0.0  Criacao inicial
//   2026-06-07  v1.0.1  Blindar SERVER_NAME contra ausencia, preservar NULL 
//                        em is_exporting (estado desconhecido vs nao-exportando)
// ============================================================
// NOTA TECNICA:
//   A coluna potencia_ativa_total_w (medida direta EA777) NAO eh exposta
//   no JSON. Motivo: EA777 reporta potencias como unsigned, e o firmware
//   infere sinal (export/import) via deltas de energia. Comparar com
//   potencia_consumo_total_w (calculada) gera divergencia confusa para
//   o usuario final. Quando o fabricante EA777 publicar firmware com
//   potencias signed, reavaliar exposicao via campo "ativa_rede".
//   Ver: docs/CONTRATO_API.md secao "Inferencia de sinal EA777".

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
    
    $sqlTelemetria = "
        SELECT timestamp_utc, potencia_geracao_w, potencia_exportada_w,
               potencia_importada_w, potencia_consumo_total_w,
               tensao_rede_v, frequencia_rede_hz, fator_potencia_total,
               fator_potencia_fase_a, fator_potencia_fase_b, fator_potencia_fase_c,
               limite_exportacao_ativo_w, is_exporting, qualidade_dado,
               firmware_versao, geracao_origem, status_inversor, temperatura_inversor_c
          FROM telemetria_5min
         WHERE controlador_id = :id
         ORDER BY timestamp_utc DESC
         LIMIT 1
    ";
    $stmtTele = $pdo->prepare($sqlTelemetria);
    $stmtTele->execute([':id' => $controladorId]);
    $leitura = $stmtTele->fetch(PDO::FETCH_ASSOC);
    
    if (!$leitura) {
        http_response_code(404);
        echo json_encode(['sucesso' => false, 'erro' => 'Sem leituras disponiveis para este controlador', 'detalhe' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $dtUtc = new DateTimeImmutable($leitura['timestamp_utc'], new DateTimeZone('UTC'));
    
    $tzStr = $controlador['timezone'] ?: 'America/Sao_Paulo';
    try {
        $tz = new DateTimeZone($tzStr);
    } catch (Exception $e) {
        $tz = new DateTimeZone('America/Sao_Paulo');
        $tzStr = 'America/Sao_Paulo';
    }
    $dtLocal = $dtUtc->setTimezone($tz);
    
    $agoraUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $idadeSegundos = $agoraUtc->getTimestamp() - $dtUtc->getTimestamp();
    $idadeAceitavel = ($idadeSegundos <= 600);
    
    $toKw = fn($w) => $w !== null ? round((float)$w / 1000.0, 3) : null;
    $toFloat = fn($val) => $val !== null ? (float)$val : null;
    $toRound = fn($val, $prec) => $val !== null ? round((float)$val, $prec) : null;
    $toBool = fn($val) => $val !== null ? (bool)$val : false;
    
    $resposta = [
        'sucesso' => true,
        'controlador_id' => (int)$controlador['id'],
        'controlador_codigo' => $controlador['codigo'],
        'controlador_apelido' => $controlador['apelido'],
        'timezone' => $tzStr,
        'timestamp_utc' => $dtUtc->format('Y-m-d\TH:i:s\Z'),
        'timestamp_local' => $dtLocal->format('c'),
        'idade_segundos' => $idadeSegundos,
        'potencia_kw' => [
            'geracao'   => $toKw($leitura['potencia_geracao_w']),
            'exportada' => $toKw($leitura['potencia_exportada_w']),
            'importada' => $toKw($leitura['potencia_importada_w']),
            'consumo'   => $toKw($leitura['potencia_consumo_total_w'])
        ],
        'rede' => [
            'tensao_v'      => $toFloat($leitura['tensao_rede_v']),
            'frequencia_hz' => $toFloat($leitura['frequencia_rede_hz']),
            'fp_total'      => $toRound($leitura['fator_potencia_total'], 3)
        ],
        'cos_fi_fases' => [
            'fase_a' => $toRound($leitura['fator_potencia_fase_a'], 3),
            'fase_b' => $toRound($leitura['fator_potencia_fase_b'], 3),
            'fase_c' => $toRound($leitura['fator_potencia_fase_c'], 3)
        ],
        'inversor' => [
            'status'         => $leitura['status_inversor'],
            'temperatura_c'  => $toFloat($leitura['temperatura_inversor_c']),
            'geracao_origem' => $leitura['geracao_origem']
        ],
        'controle' => [
            'modo'                       => $controlador['modo_controle'],
            'limite_exportacao_ativo_kw' => $toKw($leitura['limite_exportacao_ativo_w']),
            'is_exporting'               => $leitura['is_exporting'] !== null ? (bool)$leitura['is_exporting'] : null,
            'controle_ativo'             => $toBool($controlador['controle_exportacao_ativo'])
        ],
        'qualidade' => [
            'qualidade_dado'  => $leitura['qualidade_dado'] !== null ? (int)$leitura['qualidade_dado'] : null,
            'firmware_versao' => $leitura['firmware_versao'],
            'fonte'           => 'telemetria_5min',
            'idade_aceitavel' => $idadeAceitavel
        ],
        'limites_card' => [
            'potencia_nominal_kw'  => $toFloat($controlador['potencia_nominal_kw']),
            'potencia_pico_90d_kw' => $toFloat($controlador['potencia_pico_90d_kw'])
        ]
    ];
    
    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    error_log('[instantaneo.php] PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno de banco de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[instantaneo.php] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}

/*
Exemplo de curl (com sessao):
curl -i -b "PHPSESSID=xxx" "http://monitor.aeonium.com.br.test/api/energia/instantaneo.php?controlador_id=3"
*/
