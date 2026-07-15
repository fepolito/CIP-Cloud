<?php
// ============================================================
// Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo      : api/energia/resumo_mes.php
// Versao       : v1.0.0
// Data         : 2026-06-07
// Objetivo     : Retornar agregado do mes corrente com projecao
// Dependencias : config/app.php, config/database.php, app/auth.php, app/helpers/Tenant.php
// Tabelas      : controladores, telemetria_5min
// Parametros   : controlador_id (int)
// Retorno      : JSON com agregacao mensal
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
    
    $dtInicio = (new DateTimeImmutable('first day of this month', $tz))->setTime(0, 0, 0);
    $dtFim    = (new DateTimeImmutable('first day of this month', $tz))->modify('+1 month')->setTime(0, 0, 0);
    
    $inicioMes = $dtInicio->format('Y-m-d H:i:s');
    $fimMes    = $dtFim->format('Y-m-d H:i:s');
    
    $sqlData = "
        SELECT 
          COALESCE(SUM(importada_dia), 0) AS importada_kwh,
          COALESCE(SUM(exportada_dia), 0) AS exportada_kwh,
          COALESCE(SUM(geracao_dia), 0) AS geracao_kwh,
          COALESCE(SUM(consumo_dia), 0) AS consumo_kwh,
          COALESCE(AVG(pot_media_importada_w), 0) AS pot_media_importada_w,
          COALESCE(AVG(pot_media_exportada_w), 0) AS pot_media_exportada_w,
          COALESCE(AVG(pot_media_geracao_w), 0) AS pot_media_geracao_w,
          COALESCE(AVG(pot_media_consumo_w), 0) AS pot_media_consumo_w,
          COALESCE(AVG(qualidade_media), 0) AS qualidade_media,
          SUM(amostras) AS amostras,
          SUM(amostras_sem_inversor) AS amostras_sem_inversor,
          -- >>> TEMP-COBERTURA-SOLIS (remover quando SolisCloud API estiver ativa) <<<
          ROUND(SUM(cobertura_geracao_pct * amostras) / NULLIF(SUM(amostras), 0), 1) AS cobertura_geracao_pct
          -- >>> FIM TEMP-COBERTURA-SOLIS <<<
        FROM (
          SELECT 
            MAX(energia_importada_kwh) - MIN(energia_importada_kwh) AS importada_dia,
            MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh) AS exportada_dia,
            SUM(energia_geracao_kwh) AS geracao_dia,
            (MAX(energia_importada_kwh) - MIN(energia_importada_kwh)) +
            COALESCE(SUM(energia_geracao_kwh), 0) -
            (MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh)) AS consumo_dia,
            AVG(potencia_importada_w) AS pot_media_importada_w,
            AVG(potencia_exportada_w) AS pot_media_exportada_w,
            AVG(potencia_geracao_w) AS pot_media_geracao_w,
            AVG(potencia_importada_w) - AVG(potencia_exportada_w) AS pot_media_consumo_w,
            AVG(qualidade_dado) AS qualidade_media,
            COUNT(*) AS amostras,
            SUM(CASE WHEN geracao_origem = 'indisponivel' THEN 1 ELSE 0 END) AS amostras_sem_inversor,
            -- >>> TEMP-COBERTURA-SOLIS (remover quando SolisCloud API estiver ativa) <<<
            ROUND(
              100.0 * SUM(energia_geracao_kwh IS NOT NULL) / NULLIF(COUNT(*), 0),
              1
            ) AS cobertura_geracao_pct
            -- >>> FIM TEMP-COBERTURA-SOLIS <<<
          FROM telemetria_5min
          WHERE controlador_id = :cid
            AND CONVERT_TZ(timestamp_utc, 'UTC', :tz) >= :inicio_mes
            AND CONVERT_TZ(timestamp_utc, 'UTC', :tz) < :fim_mes
          GROUP BY DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz))
        ) AS daily_stats
    ";
    
    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute([
        ':cid' => $controladorId,
        ':tz'  => $tzStr,
        ':inicio_mes' => $inicioMes,
        ':fim_mes' => $fimMes
    ]);
    $linha = $stmtData->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        $linha = [
            'importada_kwh' => 0, 'exportada_kwh' => 0, 'geracao_kwh' => 0, 'consumo_kwh' => 0,
            'pot_media_importada_w' => 0, 'pot_media_exportada_w' => 0, 'pot_media_geracao_w' => 0, 'pot_media_consumo_w' => 0,
            'qualidade_media' => 0, 'amostras' => 0, 'amostras_sem_inversor' => 0
        ];
    }
    
    $agora = new DateTimeImmutable('now', $tz);
    $diaAtual = (int) $agora->format('j');
    $diasNoMes = (int) $agora->format('t');
    
    $consumoParcialKwh = (float) $linha['consumo_kwh'];
    $projecaoMesKwh = ($diaAtual > 0)
        ? ($consumoParcialKwh / $diaAtual) * $diasNoMes
        : 0.0;
        
    $amostrasEsperadas = $diasNoMes * 288;
    $amostras = (int) $linha['amostras'];
    $amostrasSemInversor = (int) $linha['amostras_sem_inversor'];
    
    $conectado = ($amostras > 0 && $amostrasSemInversor < $amostras);
    $completudePct = ($amostras == 0) ? 0.0 : round(($amostras / $amostrasEsperadas) * 100, 1);
    
    $toKw = fn($w) => $w !== null ? round((float)$w / 1000.0, 3) : null;
    $toFloat = fn($val) => $val !== null ? (float)$val : null;
    $toRound = fn($val, $prec) => $val !== null ? round((float)$val, $prec) : null;
    
    $resposta = [
        'sucesso' => true,
        'controlador_id' => (int)$controlador['id'],
        'timezone' => $tzStr,
        'periodo' => [
            'tipo'         => 'mes_corrente',
            'inicio_local' => $dtInicio->format('c'),
            'fim_local'    => $dtFim->format('c'),
            'dia_atual'    => $diaAtual,
            'dias_no_mes'  => $diasNoMes
        ],
        'energia_kwh' => [
            'importada' => $toRound($linha['importada_kwh'], 3),
            'exportada' => $toRound($linha['exportada_kwh'], 3),
            'geracao'   => $toRound($linha['geracao_kwh'], 3),
            'consumo'   => $toRound($linha['consumo_kwh'], 3)
        ],
        'potencia_media_kw' => [
            'importada' => $toKw($linha['pot_media_importada_w']),
            'exportada' => $toKw($linha['pot_media_exportada_w']),
            'geracao'   => $toKw($linha['pot_media_geracao_w']),
            'consumo'   => $toKw($linha['pot_media_consumo_w'])
        ],
        'projecao' => [
            'consumo_parcial_kwh' => $toRound($consumoParcialKwh, 3),
            'consumo_projetado_kwh' => $toRound($projecaoMesKwh, 3),
            'metodo' => 'linear_simples'
        ],
        'inversor' => [
            'conectado'  => $conectado,
            'observacao' => $conectado ? 'Inversor online' : 'Inversor offline (aguardando integracao Solis)'
        ],
        'qualidade' => [
            'qualidade_media'    => $toRound($linha['qualidade_media'], 1),
            'amostras'           => $amostras,
            'amostras_esperadas' => $amostrasEsperadas,
            'completude_pct'     => $completudePct
        ]
    ];
    
    // >>> TEMP-COBERTURA-SOLIS (remover quando SolisCloud API estiver ativa) <<<
    $cobertura = (float)($linha['cobertura_geracao_pct'] ?? 0);
    
    if ($cobertura >= 90.0) {
        $cobertura_status = 'ok';        // verde
    } elseif ($cobertura >= 50.0) {
        $cobertura_status = 'parcial';   // amarelo
    } else {
        $cobertura_status = 'critico';   // vermelho
    }
    
    $resposta['cobertura_geracao'] = [
        'pct'    => $cobertura,
        'status' => $cobertura_status,
        'aviso'  => $cobertura < 100.0
            ? 'Dados de geração parcialmente importados do Solis. Consumo pode estar subestimado.'
            : null,
    ];
    // >>> FIM TEMP-COBERTURA-SOLIS <<<

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[resumo_mes.php] PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno de banco de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[resumo_mes.php] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}

/*
Exemplo de curl:
curl -i -b "PHPSESSID=xxx" "http://monitor.aeonium.com.br.test/api/energia/resumo_mes.php?controlador_id=3"
*/
