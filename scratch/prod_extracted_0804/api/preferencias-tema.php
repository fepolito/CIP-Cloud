<?php
// ============================================================
// Projeto  : CIP
// Arquivo  : api/preferencias-tema.php
// Metodo   : GET (retorna tema) | POST { tema: 'claro'|'escuro'|'auto' }
// Historico:
//   2026-05-17  v1.0.0  Criacao
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/Preferencias.php';
requireAuthApi();

header('Content-Type: application/json; charset=utf-8');
$uid = (int) $_SESSION['usuario_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'sucesso' => true,
            'tema'    => Preferencias::getTema($uid),
        ]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
        $tema = (string) ($payload['tema'] ?? '');
        Preferencias::setTema($uid, $tema);
        echo json_encode(['sucesso' => true, 'tema' => $tema]);
        exit;
    }
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Metodo nao permitido']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE);
}