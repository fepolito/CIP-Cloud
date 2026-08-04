<?php
/**
 * @arquivo       api/sync/limites_push.php
 * @versao        1.0.0
 * @modificado_em 2026-07-25
 * @objetivo      Firmware envia curva editada localmente (web_server do ESP).
 *                Cloud grava se local for mais novo (LWW) e recalcula hash.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/DeviceAuth.php';
require_once __DIR__ . '/../../app/services/LimitesSync.php';

try {
    $pdo = getDbConnection();
    $dev = DeviceAuth::autenticar($pdo);

    $in         = json_decode(file_get_contents('php://input') ?: '[]', true);
    $editadoUtc = $in['editado_em_local'] ?? null;
    $curva      = $in['payload_json']     ?? null;
    $hashEsp    = $in['hash']             ?? null;

    if (!is_array($curva) || $editadoUtc === null) {
        http_response_code(422); // 4xx -> firmware aborta retries (correto)
        echo json_encode(['erro' => 'Payload invalido'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Integridade: hash do servidor deve bater com o do ESP
    $hashCalc = LimitesSync::hash($curva);
    if ($hashEsp !== null && !hash_equals($hashCalc, $hashEsp)) {
        http_response_code(422);
        echo json_encode(['erro' => 'Hash divergente', 'esperado' => $hashCalc], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // LWW: so grava se local for estritamente mais novo que a cloud
    $st = $pdo->prepare(
        'SELECT versao, atualizado_em FROM tabela_limites WHERE controlador_id=:cid LIMIT 1'
    );
    $st->execute([':cid' => $dev['id']]);
    $cloud = $st->fetch(PDO::FETCH_ASSOC);

    $espTs   = strtotime($editadoUtc . ' UTC');
    $cloudTs = $cloud ? strtotime($cloud['atualizado_em'] . ' UTC') : 0;

    if ($cloud && $espTs <= $cloudTs) {
        // Cloud e igual ou mais nova -> nao sobrescreve
        echo json_encode(['sucesso' => true, 'data' => [
            'sync_status' => 'sincronizada',
            'acao'        => 'ignorado_cloud_mais_nova',
            'versao'      => (int)$cloud['versao'],
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $novaVersao = ($cloud['versao'] ?? 0) + 1;
    $payloadStr = LimitesSync::canonizar($curva);

    $up = $pdo->prepare(
        "INSERT INTO tabela_limites
            (controlador_id, versao, payload_json, hash_payload,
             editado_em_local, atualizado_em, sync_status)
         VALUES
            (:cid, :ver, :pl, :hash, :edl, UTC_TIMESTAMP(), 'sincronizada')
         ON DUPLICATE KEY UPDATE
            versao=:ver2, payload_json=:pl2, hash_payload=:hash2,
            editado_em_local=:edl2, atualizado_em=UTC_TIMESTAMP(),
            sync_status='sincronizada'"
    );
    $up->execute([
        ':cid' => $dev['id'], ':ver' => $novaVersao, ':pl' => $payloadStr,
        ':hash' => $hashCalc, ':edl' => date('Y-m-d H:i:s', $espTs),
        ':ver2' => $novaVersao, ':pl2' => $payloadStr, ':hash2' => $hashCalc,
        ':edl2' => date('Y-m-d H:i:s', $espTs),
    ]);

    echo json_encode(['sucesso' => true, 'data' => [
        'sync_status' => 'sincronizada',
        'versao'      => $novaVersao,
        'hash'        => $hashCalc,
    ]], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha de banco'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
