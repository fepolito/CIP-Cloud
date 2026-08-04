<?php
/**
 * Arquivo: api/dashboard/snapshot.php
 *
 * Histórico:
 *   2026-06-02  v1.0.0  Criação do endpoint (estado atual consolidado para dashboard).
 */

declare(strict_types=1);

$is_dev = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local');

ini_set('display_errors', $is_dev ? '1' : '0');
ini_set('display_startup_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

// ─── Entrada ──────────────────────────────────────────────────
$controlador_id = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro controlador_id inválido ou ausente.']);
    exit;
}

try {
    $pdo = getDbConnection();

    // ─── Busca controlador ───────────────────────────────────
    $stmtCtrl = $pdo->prepare("
        SELECT id, codigo, apelido, timezone, fw_version, status,
               online, last_seen_at, last_telemetry_at,
               controle_exportacao_ativo, modo_controle, controle_versao,
               controle_atualizado_em, controle_origem
          FROM controladores
         WHERE id = :id
         LIMIT 1
    ");
    $stmtCtrl->execute([':id' => $controlador_id]);
    $controlador = $stmtCtrl->fetch(PDO::FETCH_ASSOC);

    if (!$controlador) {
        http_response_code(404);
        echo json_encode(['erro' => "Controlador ID {$controlador_id} não encontrado."]);
        exit;
    }

    // ─── Busca ULTIMA leitura de telemetria_5min ─────────────
    $stmtTel = $pdo->prepare("
        SELECT timestamp_utc,
               potencia_importada_w, potencia_exportada_w,
               potencia_geracao_w, potencia_consumo_total_w
          FROM telemetria_5min
         WHERE controlador_id = :id
         ORDER BY timestamp_utc DESC
         LIMIT 1
    ");
    $stmtTel->execute([':id' => $controlador_id]);
    $telemetria = $stmtTel->fetch(PDO::FETCH_ASSOC);

    // ─── Helpers de conversão de tempo ────────────────────────
    $tz = new DateTimeZone($controlador['timezone'] ?: 'UTC');
    $toLocal = function($utcStr) use ($tz) {
        if (!$utcStr) return null;
        try {
            $dt = new DateTime($utcStr, new DateTimeZone('UTC'));
            $dt->setTimezone($tz);
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    };

    $agoraUtc = new DateTime('now', new DateTimeZone('UTC'));
    $segundosSemTelemetria = null;
    $frescor = 'offline';
    $ciclo = defined('TELEMETRIA_CICLO_SEGUNDOS') ? TELEMETRIA_CICLO_SEGUNDOS : 60;
    
    if (!empty($controlador['last_telemetry_at'])) {
        try {
            $lastTelDt = new DateTime($controlador['last_telemetry_at'], new DateTimeZone('UTC'));
            $segundosSemTelemetria = $agoraUtc->getTimestamp() - $lastTelDt->getTimestamp();
            
            if ($segundosSemTelemetria < ($ciclo * 1.5)) {
                $frescor = 'tempo_real';
            } elseif ($segundosSemTelemetria < ($ciclo * 3)) {
                $frescor = 'recente';
            } elseif ($segundosSemTelemetria < ($ciclo * 6)) {
                $frescor = 'atrasado';
            } else {
                $frescor = 'offline';
            }
        } catch (Exception $e) {
            // Ignora erro de formatação de data
        }
    }

    // ─── Monta dados de Controle ──────────────────────────────
    $modoControle = $controlador['modo_controle'] ?? 'desativado';
    $labelHumano = 'Controle desativado';
    if ($modoControle === 'grid_zero') {
        $labelHumano = 'Grid Zero (Injeção 0)';
    } elseif ($modoControle === 'limite_tabela') {
        $labelHumano = 'Limite por Tabela';
    }

    // ─── Monta telemetria_atual ───────────────────────────────
    $telemetriaAtual = null;
    if ($telemetria) {
        $imp = (float) $telemetria['potencia_importada_w'] / 1000;
        $exp = (float) $telemetria['potencia_exportada_w'] / 1000;
        $ger = (float) $telemetria['potencia_geracao_w'] / 1000;
        $cons = (float) $telemetria['potencia_consumo_total_w'] / 1000;
        $balanco = round($imp - $exp, 3);

        $telemetriaAtual = [
            'fonte_dado'            => 'cip',
            'timestamp_local'       => $toLocal($telemetria['timestamp_utc']),
            'potencia_importada_kw' => round($imp, 3),
            'potencia_exportada_kw' => round($exp, 3),
            'potencia_geracao_kw'   => round($ger, 3),
            'potencia_consumo_kw'   => round($cons, 3),
            'balanco_kw'            => $balanco,
        ];
    }

    // ─── Resposta ─────────────────────────────────────────────
    echo json_encode([
        'sucesso'     => true,
        'controlador' => [
            'id'                      => (int) $controlador['id'],
            'codigo'                  => $controlador['codigo'],
            'apelido'                 => $controlador['apelido'],
            'timezone'                => $controlador['timezone'],
            'fw_version'              => $controlador['fw_version'],
            'status'                  => $controlador['status'],
            'online'                  => (bool) $controlador['online'],
            'last_seen_at_local'      => $toLocal($controlador['last_seen_at']),
            'last_telemetry_at_local' => $toLocal($controlador['last_telemetry_at']),
            'frescor_telemetria'      => $frescor,
            'segundos_sem_telemetria' => $segundosSemTelemetria !== null ? max(0, $segundosSemTelemetria) : null,
            'ciclo_esperado_segundos' => $ciclo,
        ],
        'controle' => [
            'ativo'               => (bool) $controlador['controle_exportacao_ativo'],
            'modo'                => $modoControle,
            'versao'              => (int) $controlador['controle_versao'],
            'atualizado_em_local' => $toLocal($controlador['controle_atualizado_em']),
            'origem'              => $controlador['controle_origem'],
            'label_humano'        => $labelHumano,
        ],
        'telemetria_atual' => $telemetriaAtual,
        'geradoEm'         => (new DateTime('now'))->format('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[snapshot.php] PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno no banco de dados.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
}
