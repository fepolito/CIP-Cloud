<?php
/**
 * @arquivo       api/faturamento/excluir.php
 * @versao        1.0.1
 * @modificado_em 2026-08-13
 * @objetivo      Exclui uma fatura da distribuidora (faturas_distribuidora) por id,
 *                validando posse do controlador via Tenant::filtroSQL (tenant-safe).
 * @autor         Fernando / CIP Cloud Copilot
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';
use App\Helpers\Tenant;

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
    $faturaId = (int)($in['id'] ?? 0);
    if ($faturaId <= 0) {
        http_response_code(400);
        echo json_encode(['erro' => 'id obrigatorio']);
        exit;
    }

    $pdo = getDbConnection();

    // Valida que a fatura pertence a um controlador acessivel ao usuario.
    // JOIN com controladores + filtroSQL garante isolamento multi-tenant.
    $filtroSql = Tenant::filtroSQL('c');
    $sql = "SELECT f.id
              FROM faturas_distribuidora f
              INNER JOIN controladores c ON c.id = f.controlador_id
             WHERE f.id = :fid
               {$filtroSql}
             LIMIT 1";
    $params = [':fid' => $faturaId];
    Tenant::aplicarParam($params);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(404);
        echo json_encode(['erro' => 'Fatura nao acessivel para o usuario']);
        exit;
    }

    $del = $pdo->prepare('DELETE FROM faturas_distribuidora WHERE id = :fid LIMIT 1');
    $del->execute([':fid' => $faturaId]);

    echo json_encode(['sucesso' => true, 'data' => ['id' => $faturaId]], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    $resp = ['erro' => 'Erro ao excluir'];
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $resp['detalhe'] = $e->getMessage();
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
}
