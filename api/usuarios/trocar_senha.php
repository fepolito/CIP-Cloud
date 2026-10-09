<?php
/**
 * @arquivo       api/usuarios/trocar_senha.php
 * @versao        1.0.0
 * @modificado_em 2026-10-09
 * @objetivo      Endpoint que permite ao usuário autenticado definir nova senha quando
 *                forcar_troca_senha=1, removendo a flag após sucesso.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../config/database.php';

startSecureSession();

try {
    $usuarioId = $_SESSION['usuario']['id'] ?? $_SESSION['usuario_id'] ?? null;
    if (!$usuarioId) {
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $senha = (string) ($input['senha'] ?? '');

    if (strlen($senha) < 6) {
        http_response_code(422);
        echo json_encode(['sucesso' => false, 'erro' => 'Senha deve ter ao menos 6 caracteres.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        UPDATE usuarios
        SET senha_hash = :hash, forcar_troca_senha = 0, atualizado_em = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        'hash' => password_hash($senha, PASSWORD_DEFAULT),
        'id'   => (int) $usuarioId,
    ]);

    $_SESSION['forcar_troca_senha'] = 0;
    if (isset($_SESSION['usuario']) && is_array($_SESSION['usuario'])) {
        $_SESSION['usuario']['forcar_troca_senha'] = 0;
    }

    echo json_encode(['sucesso' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[trocar_senha.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno ao atualizar senha.'], JSON_UNESCAPED_UNICODE);
}
