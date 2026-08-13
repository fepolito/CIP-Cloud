<?php
/**
 * @arquivo       api/faturamento/salvar.php
 * @versao        1.0.1
 * @modificado_em 2026-08-13
 * @objetivo      Insere/atualiza fatura da distribuidora (cadastro manual) para conciliacao com a telemetria CIP.
 * @autor         Fernando / CIP Cloud Copilot
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';
use App\Helpers\Tenant;

// Protegendo o endpoint conforme padrão
require_once __DIR__ . '/../../app/auth.php';
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Nao autorizado']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['erro' => 'Metodo nao permitido']);
        exit;
    }

    $in = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $controladorId = (int)($in['controlador_id'] ?? 0);
    $mes           = trim((string)($in['mes_referencia'] ?? ''));       // AAAA-MM
    $dataAnt       = trim((string)($in['data_leitura_ant'] ?? ''));
    $dataAtual     = trim((string)($in['data_leitura_atual'] ?? ''));

    // Campos obrigatorios
    if ($controladorId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $mes)
        || !$dataAnt || !$dataAtual) {
        http_response_code(400);
        echo json_encode(['erro' => 'Dados obrigatorios ausentes ou invalidos']);
        exit;
    }

    $pdo = getDbConnection();

    $filtroSql = Tenant::filtroSQL('c');
    $sqlCtrl = "SELECT c.numero_instalacao, c.empresa_id
                  FROM controladores c
                 WHERE c.id = :cid
                   {$filtroSql}
                 LIMIT 1";
    $paramsCtrl = [':cid' => $controladorId];
    Tenant::aplicarParam($paramsCtrl);

    $stmt = $pdo->prepare($sqlCtrl);
    $stmt->execute($paramsCtrl);
    $ctrl = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ctrl) {
        http_response_code(404);
        echo json_encode(['erro' => 'Controlador nao acessivel para o usuario']);
        exit;
    }
    $empresaId = (int) $ctrl['empresa_id'];   // usado no INSERT (:eid)

    $diasFat = (int)((new DateTime($dataAtual))->diff(new DateTime($dataAnt))->days);

    $sql = 'INSERT INTO faturas_distribuidora
              (empresa_id, controlador_id, distribuidora, numero_instalacao,
               mes_referencia, data_leitura_ant, data_leitura_atual, dias_faturados,
               energia_importada_kwh, energia_injetada_kwh,
               leitura_ant_registro, leitura_atual_registro, observacao)
            VALUES
              (:eid, :cid, :dist, :uc, :mes, :dant, :datual, :dias,
               :imp, :inj, :rant, :ratual, :obs)
            ON DUPLICATE KEY UPDATE
               data_leitura_ant = VALUES(data_leitura_ant),
               data_leitura_atual = VALUES(data_leitura_atual),
               dias_faturados = VALUES(dias_faturados),
               energia_importada_kwh = VALUES(energia_importada_kwh),
               energia_injetada_kwh = VALUES(energia_injetada_kwh),
               leitura_ant_registro = VALUES(leitura_ant_registro),
               leitura_atual_registro = VALUES(leitura_atual_registro),
               observacao = VALUES(observacao)';

    $pdo->prepare($sql)->execute([
        ':eid'  => $empresaId,
        ':cid'  => $controladorId,
        ':dist' => (string)($in['distribuidora'] ?? 'CPFL'),
        ':uc'   => $ctrl['numero_instalacao'] ?: ($in['numero_instalacao'] ?? null),
        ':mes'  => $mes,
        ':dant' => $dataAnt,
        ':datual' => $dataAtual,
        ':dias' => $diasFat,
        ':imp'  => (float)($in['energia_importada_kwh'] ?? 0),
        ':inj'  => (float)($in['energia_injetada_kwh'] ?? 0),
        ':rant' => isset($in['leitura_ant_registro']) ? (float)$in['leitura_ant_registro'] : null,
        ':ratual' => isset($in['leitura_atual_registro']) ? (float)$in['leitura_atual_registro'] : null,
        ':obs'  => $in['observacao'] ?? null,
    ]);

    echo json_encode(['sucesso' => true, 'data' => ['mes' => $mes]], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao salvar']
        + (($_ENV['APP_ENV'] ?? '') === 'dev' ? ['detalhe' => $e->getMessage()] : []));
}
