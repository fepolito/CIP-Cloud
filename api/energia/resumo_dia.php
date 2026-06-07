<?php
/**
 * Historico:
 *   2026-06-05  v1.0.0  Criacao
 *   2026-06-06  v1.1.0  Correcao: consumo_total agora e calculado em PHP.
 *                       Motivo: firmware nao popula energia_ativa_total_kwh
 *                       (sempre 0.0000). Workaround documentado, com flag
 *                       CALCULAR_CONSUMO_NO_PHP para reversao futura quando
 *                       firmware for corrigido (ver docs/CONTRATO_API.md).
 */
declare(strict_types=1);

// ─── Flag de comportamento ────────────────────────────────────────
// TRUE  → cloud calcula consumo_total via formula canonica (estado atual)
// FALSE → cloud le energia_ativa_total_kwh direto do banco (quando firmware corrigir)
const CALCULAR_CONSUMO_NO_PHP = true;

$is_dev = ($_SERVER['SERVER_NAME'] ?? '') === 'localhost'
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.local')
       || str_contains($_SERVER['HTTP_HOST'] ?? '', '.test');

ini_set('display_errors', $is_dev ? '1' : '0');
ini_set('display_startup_errors', $is_dev ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';

// Entrada
$controlador_id = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
$data = filter_input(INPUT_GET, 'data', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro controlador_id inválido ou ausente.', 'detalhe' => null]);
    exit;
}

if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro data inválido. Use o formato YYYY-MM-DD.', 'detalhe' => null]);
    exit;
}

$parts = explode('-', $data);
if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Data inválida.', 'detalhe' => null]);
    exit;
}

try {
    $pdo = getDbConnection();

    // Validar controlador
    $stmtCtrl = $pdo->prepare("SELECT id, codigo, apelido, timezone FROM controladores WHERE id = :id LIMIT 1");
    $stmtCtrl->execute([':id' => $controlador_id]);
    $controlador = $stmtCtrl->fetch(PDO::FETCH_ASSOC);

    if (!$controlador) {
        http_response_code(404);
        echo json_encode(['erro' => "Controlador ID {$controlador_id} não encontrado.", 'detalhe' => null]);
        exit;
    }

    $timezone = $controlador['timezone'] ?? 'America/Sao_Paulo';

    // Headers de cache
    $dtHoje = new DateTime('now', new DateTimeZone($timezone));
    $hojeLocal = $dtHoje->format('Y-m-d');
    if ($data < $hojeLocal) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache, must-revalidate');
    }

    // Consulta de agregados
    $sql = "
        SELECT 
            COALESCE(MAX(energia_geracao_kwh) - MIN(energia_geracao_kwh), 0) AS geracao_kwh,
            COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh,
            COALESCE(MAX(energia_importada_kwh) - MIN(energia_importada_kwh), 0) AS importada_kwh,
            COALESCE(MAX(energia_ativa_total_kwh) - MIN(energia_ativa_total_kwh), 0) AS consumo_total_kwh_firmware,
            
            COALESCE(MAX(potencia_geracao_w) / 1000, 0) AS pico_geracao_kw,
            COALESCE(MAX(potencia_exportada_w) / 1000, 0) AS pico_exportada_kw,
            COALESCE(MAX(potencia_importada_w) / 1000, 0) AS pico_importada_kw,
            COALESCE(MAX(potencia_consumo_total_w) / 1000, 0) AS pico_consumo_kw,
            
            COUNT(*) AS total_registros
        FROM telemetria_5min
        WHERE controlador_id = :id
          AND DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz)) = :data
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $controlador_id,
        ':tz' => $timezone,
        ':data' => $data
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $geracao   = (float) $row['geracao_kwh'];
    $exportada = (float) $row['exportada_kwh'];
    $importada = (float) $row['importada_kwh'];
    $consumo_firmware = (float) $row['consumo_total_kwh_firmware'];

    // autoconsumo = max(0, geracao - exportada)
    $autoconsumo = $geracao - $exportada;
    if ($autoconsumo < 0) {
        error_log(sprintf(
            '[resumo_dia.php] Anomalia: autoconsumo negativo | ctrl=%d | data=%s | geracao=%.4f | exportada=%.4f',
            $controlador_id, $data, $geracao, $exportada
        ));
        $autoconsumo = 0.0;
    }

    // consumo_total: calculado em PHP OU lido do firmware (controlado por flag)
    if (CALCULAR_CONSUMO_NO_PHP) {
        $consumo_total = $autoconsumo + $importada;
        $fonte_consumo = 'cloud_calculado';
    } else {
        $consumo_total = $consumo_firmware;
        $fonte_consumo = 'firmware';
    }

    $total_registros = (int) $row['total_registros'];
    $esperado_registros = 288;
    $cobertura_pct = round(($total_registros / $esperado_registros) * 100, 1);

    $resposta = [
        "sucesso" => true,
        "data" => $data,
        "controlador_id" => $controlador_id,
        "controlador_codigo" => $controlador['codigo'],
        "controlador_apelido" => $controlador['apelido'],
        "timezone" => $timezone,
        "energia_kwh" => [
            "geracao" => round($geracao, 4),
            "exportada" => round($exportada, 4),
            "importada" => round($importada, 4),
            "consumo_total" => round($consumo_total, 4),
            "autoconsumo" => round($autoconsumo, 4)
        ],
        "potencia_pico_kw" => [
            "geracao" => round((float)$row['pico_geracao_kw'], 3),
            "exportada" => round((float)$row['pico_exportada_kw'], 3),
            "importada" => round((float)$row['pico_importada_kw'], 3),
            "consumo" => round((float)$row['pico_consumo_kw'], 3)
        ],
        "qualidade" => [
            "total_registros" => $total_registros,
            "esperado_registros" => $esperado_registros,
            "cobertura_pct" => $cobertura_pct,
            "fonte_consumo" => $fonte_consumo
        ]
    ];

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[resumo_dia.php] PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno no banco de dados.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
} catch (Throwable $e) {
    error_log('[resumo_dia.php] Erro: ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno do servidor.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
}

/*
Exemplo de curl:
curl -i "http://monitor.aeonium.com.br.test/api/energia/resumo_dia.php?controlador_id=3&data=2026-06-05"
*/
