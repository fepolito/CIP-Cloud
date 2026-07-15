<?php
// ============================================================
// Projeto      : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo      : api/energia/resumo_dia.php
// Versao       : v1.1.0
// Data         : 2026-07-11
// Objetivo     : Retornar agregado do dia corrente para o dashboard
// Dependencias : config/app.php, config/database.php, app/auth.php, app/helpers/Tenant.php
// Tabelas      : controladores, telemetria_5min
// Parametros   : controlador_id (int)
// Retorno      : JSON com agregacao diaria
// Historico    :
//   2026-06-07  v1.0.0  Criacao inicial
//   2026-07-11  v1.1.0  Adiciona picos do dia (seed gauge 3 aneis) + escala_kw
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
    
    $dtInicio = new DateTimeImmutable('today', $tz);
    $dtFim    = $dtInicio->modify('+1 day');
    
    $inicioUtc = $dtInicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $fimUtc    = $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    
    $sqlData = "
        SELECT 
          COALESCE(MAX(energia_importada_kwh) - MIN(energia_importada_kwh), 0) AS importada_kwh,
          COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh,
          COALESCE(SUM(energia_geracao_kwh), 0) AS geracao_kwh,
          COALESCE(
            (MAX(energia_importada_kwh) - MIN(energia_importada_kwh)) +
            COALESCE(SUM(energia_geracao_kwh), 0) -
            (MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh)),
            0
          ) AS consumo_kwh,
          COALESCE(AVG(potencia_importada_w), 0) AS pot_media_importada_w,
          COALESCE(AVG(potencia_exportada_w), 0) AS pot_media_exportada_w,
          COALESCE(AVG(potencia_geracao_w),   0) AS pot_media_geracao_w,
          COALESCE(AVG(potencia_importada_w) - AVG(potencia_exportada_w), 0) AS pot_media_consumo_w,
          COALESCE(MAX(potencia_geracao_w),   0) AS pot_pico_geracao_w,
          COALESCE(MAX(potencia_exportada_w), 0) AS pot_pico_exportada_w,
          COALESCE(MAX(potencia_importada_w), 0) AS pot_pico_importada_w,
          COALESCE(MAX(potencia_importada_w - potencia_exportada_w), 0) AS pot_pico_consumo_w,
          COALESCE(AVG(qualidade_dado), 0) AS qualidade_media,
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
          AND timestamp_utc >= :inicio_utc
          AND timestamp_utc <  :fim_utc
    ";
    
    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute([
        ':cid' => $controladorId,
        ':inicio_utc' => $inicioUtc,
        ':fim_utc' => $fimUtc
    ]);
    $linha = $stmtData->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        $linha = [
            'importada_kwh' => 0, 'exportada_kwh' => 0, 'geracao_kwh' => 0, 'consumo_kwh' => 0,
            'pot_media_importada_w' => 0, 'pot_media_exportada_w' => 0, 'pot_media_geracao_w' => 0, 'pot_media_consumo_w' => 0,
            'pot_pico_geracao_w' => 0, 'pot_pico_exportada_w' => 0,
            'pot_pico_importada_w' => 0, 'pot_pico_consumo_w' => 0,
            'qualidade_media' => 0, 'amostras' => 0, 'amostras_sem_inversor' => 0
        ];
    }
    
    $agoraLocal = new DateTimeImmutable('now', $tz);
    $segundosHoje = $agoraLocal->getTimestamp() - $dtInicio->getTimestamp();
    $amostrasEsperadas = (int) floor(max(0, $segundosHoje) / 300);
    if ($amostrasEsperadas < 1) $amostrasEsperadas = 1;
    
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
            'tipo'         => 'dia_corrente',
            'inicio_local' => $dtInicio->format('c'),
            'fim_local'    => $dtFim->format('c'),
            'inicio_utc'   => $dtInicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'fim_utc'      => $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')
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
        'potencia_pico_dia_kw' => [
            'geracao'   => $toKw($linha['pot_pico_geracao_w']),
            'exportada' => $toKw($linha['pot_pico_exportada_w']),
            'importada' => $toKw($linha['pot_pico_importada_w']),
            'consumo'   => $toKw($linha['pot_pico_consumo_w'])
        ],
        'escala_kw' => $toFloat($controlador['potencia_pico_90d_kw']),
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
    error_log('[resumo_dia.php] PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno de banco de dados', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[resumo_dia.php] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno do servidor', 'detalhe' => $is_dev ? $e->getMessage() : null], JSON_UNESCAPED_UNICODE);
}

/*
Exemplo de curl:
curl -i -b "PHPSESSID=xxx" "http://monitor.aeonium.com.br.test/api/energia/resumo_dia.php?controlador_id=3"
*/
