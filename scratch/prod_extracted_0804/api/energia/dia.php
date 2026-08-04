<?php
/**
 * Arquivo: api/energia/dia.php
 * ...
 * Histórico:
 *   2026-04-11  v1.0.0  Criação — série 5min, unidade kW, consumo_total calculado
 *   2026-05-16  v1.1.0  Correção schema v2: usa potencia_geracao_w e
 *                       potencia_consumo_total_w direto. Group by bucket UTC.
 *                       Cache para dias passados. Erros condicionais a ambiente.
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
$data           = filter_input(INPUT_GET, 'data', FILTER_SANITIZE_SPECIAL_CHARS);

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro controlador_id inválido ou ausente.']);
    exit;
}

if (!$data || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetro data inválido. Use o formato YYYY-MM-DD.']);
    exit;
}

if (!checkdate(
    (int) substr($data, 5, 2),
    (int) substr($data, 8, 2),
    (int) substr($data, 0, 4)
)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Data inválida.']);
    exit;
}

try {
    $pdo = getDbConnection();

    // ─── Valida controlador ───────────────────────────────────
    $stmtCtrl = $pdo->prepare("
        SELECT id, codigo, apelido, timezone
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

    // ─── Cache: dias fechados podem ser cacheados ─────────────
    $hoje_local = (new DateTime('now', new DateTimeZone($controlador['timezone'])))->format('Y-m-d');
    if ($data < $hoje_local) {
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Cache-Control: no-cache, must-revalidate');
    }

    // ─── Consulta telemetria do dia ───────────────────────────
    // 🎯 schema v2: lê colunas dedicadas, agrupa por bucket UTC
    $stmt = $pdo->prepare("
        SELECT
            FLOOR(UNIX_TIMESTAMP(timestamp_utc) / 300) * 300 AS bucket_utc,
            DATE_FORMAT(
                CONVERT_TZ(
                    FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP(timestamp_utc) / 300) * 300),
                    'UTC', :tz
                ),
                '%H:%i'
            ) AS label,

            ROUND(AVG(potencia_importada_w)     / 1000, 3) AS importada,
            ROUND(AVG(potencia_exportada_w)     / 1000, 3) AS exportada,
            ROUND(AVG(potencia_geracao_w)       / 1000, 3) AS compensacao,
            ROUND(AVG(COALESCE(potencia_importada_w, 0) + COALESCE(potencia_geracao_w, 0) - COALESCE(potencia_exportada_w, 0)) / 1000, 3) AS consumo_total

          FROM telemetria_5min
         WHERE controlador_id = :id
           AND DATE(CONVERT_TZ(timestamp_utc, 'UTC', :tz2)) = :data

         GROUP BY bucket_utc, label
         ORDER BY bucket_utc ASC
    ");

    $stmt->execute([
        ':tz'   => $controlador['timezone'],
        ':tz2'  => $controlador['timezone'],
        ':id'   => $controlador_id,
        ':data' => $data,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Monta séries ─────────────────────────────────────────
    $labels       = [];
    $importada    = [];
    $exportada    = [];
    $compensacao  = [];
    $consumoTotal = [];

    foreach ($rows as $row) {
        $labels[]       = $row['label'];
        $importada[]    = (float) $row['importada'];
        $exportada[]    = (float) $row['exportada'];
        $compensacao[]  = (float) $row['compensacao'];
        $consumoTotal[] = (float) $row['consumo_total'];
    }

    // ─── Resumo do dia ────────────────────────────────────────
    $resumo = [
        'data'                => $data,
        'controlador_id'      => $controlador_id,
        'controlador_codigo'  => $controlador['codigo'],
        'controlador_apelido' => $controlador['apelido'],
        'timezone'            => $controlador['timezone'],
        'total_registros'     => count($rows),
        'pico_importada_kw'   => $importada    ? max($importada)    : 0,
        'pico_exportada_kw'   => $exportada    ? max($exportada)    : 0,
        'pico_compensacao_kw' => $compensacao  ? max($compensacao)  : 0,
        'pico_consumo_kw'     => $consumoTotal ? max($consumoTotal) : 0,
        'media_consumo_kw'    => $consumoTotal ? round(array_sum($consumoTotal) / count($consumoTotal), 3) : 0,
    ];

    // ─── Resposta ─────────────────────────────────────────────
    echo json_encode([
        'sucesso'       => true,
        'labels'        => $labels,
        'importada'     => $importada,
        'exportada'     => $exportada,
        'compensacao'   => $compensacao,
        'consumo_total' => $consumoTotal,
        'resumo'        => $resumo,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    error_log('[dia.php] PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'erro'    => 'Erro interno no banco de dados.',
        'detalhe' => $is_dev ? $e->getMessage() : null,
    ]);
    exit;
}
