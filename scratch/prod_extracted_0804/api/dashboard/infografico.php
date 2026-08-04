<?php
declare(strict_types=1);

/**
 * ============================================================
 * Arquivo   : api/dashboard/infografico.php
 * Projeto   : CIP — Controlador de Injecao de Potencia Eletrica
 * Objetivo  : Endpoint enxuto para alimentar o infografico SVG
 *             animado do dashboard. Retorna fluxo instantaneo
 *             e agora tambem o hodometro do dia (kWh).
 *
 * @versao        1.13.0
 * @modificado_em 2026-06-07
 * @notas         Adicionado bloco energia_dia (kWh acumulado do dia via
 *                delta de hodometro MAX-MIN). Calculo timezone-aware com
 *                tratamento de bordas (amostras insuficientes, reset).
 * ============================================================
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';

use app\helpers\Tenant;

requireAuth();

$controladorId = isset($_GET['controlador_id']) ? (int) $_GET['controlador_id'] : 0;
if ($controladorId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'mensagem' => 'Parâmetro controlador_id ausente ou inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'mensagem' => 'Banco de dados indisponível'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $filtroTenant = Tenant::filtroSQL('c');
    
    // Validar se o controlador está no escopo do usuário atual e buscar timezone
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
    $rowCtrl = $stmtCtrl->fetch(PDO::FETCH_ASSOC);
    
    if (!$rowCtrl) {
        http_response_code(403);
        echo json_encode(['success' => false, 'mensagem' => 'Acesso negado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $tz = $rowCtrl['timezone'] ?: 'America/Sao_Paulo';

    // 1. Dados instantaneos (Fluxo Atual)
    $sqlData = "
        SELECT
            t.timestamp_utc,
            CONVERT_TZ(t.timestamp_utc, '+00:00', c.timezone) AS timestamp_local,
            TIMESTAMPDIFF(SECOND, t.timestamp_utc, UTC_TIMESTAMP()) AS idade_segundos,
            t.potencia_geracao_w,
            t.potencia_importada_w,
            t.potencia_exportada_w,
            t.potencia_consumo_total_w,
            t.qualidade_dado,
            t.geracao_origem,
            t.is_exporting,
            t.direction_valid,
            t.tensao_rede_v,
            t.frequencia_rede_hz,
            t.status_inversor,
            t.temperatura_inversor_c,
            t.limite_exportacao_ativo_w,
            t.firmware_versao,
            t.inversor_read_errors
        FROM telemetria_5min t
        INNER JOIN controladores c ON c.id = t.controlador_id
        WHERE t.controlador_id = :ctrl_id
          {$filtroTenant}
        ORDER BY t.timestamp_utc DESC
        LIMIT 1
    ";

    $paramsData = [':ctrl_id' => $controladorId];
    Tenant::aplicarParam($paramsData);

    $stmtData = $pdo->prepare($sqlData);
    $stmtData->execute($paramsData);
    $row = $stmtData->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $vazio = [
            'success' => true,
            'vazio'   => true,
            'mensagem' => 'Nenhuma telemetria registrada para este controlador',
            'fluxo'   => [
                'geracao_w' => 0,
                'importada_w' => 0,
                'exportada_w' => 0,
                'consumo_total_w' => 0,
                'bateria' => [
                    'status' => 'standby',
                    'potencia_w' => null
                ]
            ],
            'status' => [
                'controlador' => ['online' => false, 'idade_seg' => null, 'label' => 'OFFLINE'],
                'inversor' => ['online' => false, 'status_raw' => null, 'read_errors' => null, 'label' => 'OFFLINE']
            ]
        ];
        echo json_encode($vazio, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. Dados acumulados do dia (Hodometros)
    $sqlEnergia = "
        SELECT
          COALESCE(MAX(energia_importada_kwh) - MIN(energia_importada_kwh), 0) AS kwh_importada_dia,
          COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS kwh_exportada_dia,
          COALESCE(MAX(energia_geracao_kwh)   - MIN(energia_geracao_kwh),   0) AS kwh_geracao_dia,
          COUNT(*) AS amostras_dia,
          SUM(CASE WHEN energia_geracao_kwh IS NULL THEN 1 ELSE 0 END) AS amostras_sem_geracao,
          MIN(timestamp_utc) AS primeira_amostra_utc,
          MAX(timestamp_utc) AS ultima_amostra_utc
        FROM telemetria_5min
        WHERE controlador_id = :ctrl
          AND DATE(CONVERT_TZ(timestamp_utc, '+00:00', :tz1)) = 
              DATE(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', :tz2))
    ";
    $stmtEnergia = $pdo->prepare($sqlEnergia);
    $stmtEnergia->execute([
        ':ctrl' => $controladorId, 
        ':tz1' => $tz,
        ':tz2' => $tz
    ]);
    $rowEnergia = $stmtEnergia->fetch(PDO::FETCH_ASSOC);

    $amostras = (int)$rowEnergia['amostras_dia'];
    $energia_dia = null;

    if ($amostras < 2) {
        $energia_dia = [
            'geracao_kwh' => null,
            'importada_kwh' => null,
            'exportada_kwh' => null,
            'consumo_total_kwh' => null,
            'amostras' => $amostras,
            'geracao_dia_parcial' => false,
            'primeira_amostra_local' => null,
            'ultima_amostra_local' => null,
            'calculo_metodo' => 'delta_hodometro',
            'aviso' => 'aguardando_dados_suficientes'
        ];
    } else {
        $kwhImp = (float)$rowEnergia['kwh_importada_dia'];
        $kwhExp = (float)$rowEnergia['kwh_exportada_dia'];
        $kwhGer = (float)$rowEnergia['kwh_geracao_dia'];
        $amostrasSemGer = (int)$rowEnergia['amostras_sem_geracao'];

        $aviso = null;
        if ($kwhImp < 0 || $kwhExp < 0 || $kwhGer < 0) {
            $aviso = 'possivel_reset_medidor';
            error_log("[infografico.php] Possivel reset de medidor no ctrl {$controladorId}");
            $kwhImp = max(0, $kwhImp);
            $kwhExp = max(0, $kwhExp);
            $kwhGer = max(0, $kwhGer);
        }

        $geracaoKwh = null;
        $geracaoParcial = false;
        if ($amostrasSemGer < $amostras) {
            $geracaoKwh = $kwhGer;
            if ($amostrasSemGer > 0) {
                $geracaoParcial = true;
            }
        }

        $consumoTotal = $kwhImp + ($geracaoKwh ?? 0) - $kwhExp;

        $dtPrimeira = $rowEnergia['primeira_amostra_utc'] ? new DateTimeImmutable($rowEnergia['primeira_amostra_utc'], new DateTimeZone('UTC')) : null;
        $dtUltima   = $rowEnergia['ultima_amostra_utc']   ? new DateTimeImmutable($rowEnergia['ultima_amostra_utc'], new DateTimeZone('UTC'))   : null;
        
        $primeiraLocal = $dtPrimeira ? $dtPrimeira->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s') : null;
        $ultimaLocal   = $dtUltima   ? $dtUltima->setTimezone(new DateTimeZone($tz))->format('Y-m-d H:i:s') : null;

        $energia_dia = [
            'geracao_kwh' => $geracaoKwh,
            'importada_kwh' => $kwhImp,
            'exportada_kwh' => $kwhExp,
            'consumo_total_kwh' => $consumoTotal,
            'amostras' => $amostras,
            'geracao_dia_parcial' => $geracaoParcial,
            'primeira_amostra_local' => $primeiraLocal,
            'ultima_amostra_local' => $ultimaLocal,
            'calculo_metodo' => 'delta_hodometro'
        ];
        if ($aviso) {
            $energia_dia['aviso'] = $aviso;
        }
    }

    $toF = fn($v) => $v !== null ? (float)$v : null;
    $toI = fn($v) => $v !== null ? (int)$v : null;
    $toB = fn($v) => $v !== null ? (bool)$v : null;

    $tsUtc = $row['timestamp_utc'] ? (new DateTimeImmutable($row['timestamp_utc'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : null;

    $idade_seg = isset($row['idade_segundos']) ? (int) $row['idade_segundos'] : null;

    if ($idade_seg === null && !empty($row['timestamp_utc'])) {
        // Fallback caso CONVERT_TZ no MySQL retorne NULL no Windows
        $ts_db = new DateTimeImmutable($row['timestamp_utc'], new DateTimeZone('UTC'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $idade_seg = $now->getTimestamp() - $ts_db->getTimestamp();
    }

    $controlador_online = ($idade_seg !== null && $idade_seg <= 900);
    $st_inv = strtolower(trim((string)($row['status_inversor'] ?? '')));
    $inversor_online = ($st_inv !== '' && !in_array($st_inv, ['offline', 'erro', 'falha', 'desconectado'], true));

    $response = [
        'success' => true,
        'timestamp_utc'   => $tsUtc,
        'timestamp_local' => $row['timestamp_local'],
        'idade_segundos'  => $idade_seg,
        'status' => [
            'controlador' => [
                'online'      => $controlador_online,
                'idade_seg'   => $idade_seg,
                'label'       => $controlador_online ? 'ONLINE' : 'OFFLINE',
            ],
            'inversor' => [
                'online'      => $inversor_online,
                'status_raw'  => $row['status_inversor'] ?? null,
                'read_errors' => isset($row['inversor_read_errors']) ? (int)$row['inversor_read_errors'] : null,
                'label'       => $inversor_online ? 'ONLINE' : 'OFFLINE',
            ],
        ],
        'fluxo' => [
            'geracao_w'       => $toF($row['potencia_geracao_w']),
            'importada_w'     => $toF($row['potencia_importada_w']),
            'exportada_w'     => $toF($row['potencia_exportada_w']),
            'consumo_total_w' => $toF($row['potencia_consumo_total_w']),
            'bateria' => [
                'status'     => 'standby',
                'potencia_w' => null
            ]
        ],
        'energia_dia' => $energia_dia,
        'qualidade' => [
            'score'           => $toI($row['qualidade_dado']),
            'origem_geracao'  => $row['geracao_origem'],
            'is_exporting'    => $toB($row['is_exporting']),
            'direction_valid' => $toB($row['direction_valid'])
        ],
        'rede' => [
            'tensao_v'      => $toF($row['tensao_rede_v']),
            'frequencia_hz' => $toF($row['frequencia_rede_hz'])
        ],
        'inversor' => [
            'status'          => $row['status_inversor'],
            'temperatura_c'   => $toF($row['temperatura_inversor_c']),
            'limite_export_w' => $toF($row['limite_exportacao_ativo_w'])
        ],
        'meta' => [
            'firmware_versao' => $row['firmware_versao'],
            'fonte_dado'      => 'cip'
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[infografico.php] PDOException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'mensagem' => 'Erro interno de banco de dados'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[infografico.php] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'mensagem' => 'Erro interno do servidor'], JSON_UNESCAPED_UNICODE);
}
