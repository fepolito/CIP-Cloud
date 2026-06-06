<?php
// ============================================================
// Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
// Arquivo   : api/auth/login.php
// Objetivo  : Autentica usuario e gera token de sessao
// Metodo    : POST
// Body      : { "email": "...", "senha": "..." }
// Resposta  : { success, token, expires_at, usuario: {
//               id, nome, email, perfil, papel_global,
//               empresa_id, is_master } }
// Historico :
//   2026-04-07  v1.0.0  Implementacao inicial
//   2026-04-07  v1.1.0  Migrado para tabela oficial `usuarios`
//   2026-05-17  v1.2.0  Multi-tenant: retorna papel_global e is_master
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError(405, 'Metodo nao permitido');
}

// -- Lê e valida body JSON ------------------------------------
$body = json_decode(file_get_contents('php://input'), true);

if (!isset($body['email'], $body['senha'])) {
    respondError(400, 'Email e senha sao obrigatorios');
}

$email = trim((string) $body['email']);
$senha = trim((string) $body['senha']);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respondError(400, 'Email invalido');
}

if (strlen($senha) < 6) {
    respondError(400, 'Senha muito curta');
}

// -- Busca usuario ativo no banco -----------------------------
$pdo  = Database::getInstance();
$stmt = $pdo->prepare("
    SELECT id, nome, email, senha_hash,
           perfil, papel_global, empresa_id
    FROM usuarios
    WHERE email = ?
      AND ativo = 1
    LIMIT 1
");
$stmt->execute([$email]);
$usuario = $stmt->fetch();

// -- Valida senha (bcrypt) ------------------------------------
if (!$usuario || !password_verify($senha, $usuario['senha_hash'])) {
    // 🛡️ Mensagem genérica — não revela se foi email ou senha
    respondError(401, 'Credenciais invalidas');
}

// -- 👑 Checagem de consistência multi-tenant -----------------
$papelGlobal = $usuario['papel_global'] ?? null;
$ehMaster    = in_array($papelGlobal, ['master', 'master_operador'], true);

// 🚨 Usuário comum SEM empresa = inconsistência grave
if (!$ehMaster && empty($usuario['empresa_id'])) {
    respondError(403, 'Usuario sem empresa vinculada. Contate o administrador.');
}

// -- Invalida tokens anteriores do usuario --------------------
$pdo->prepare("
    UPDATE cip_tokens
    SET ativo = 0
    WHERE usuario_id = ?
      AND ativo = 1
")->execute([$usuario['id']]);

// -- Gera novo token seguro -----------------------------------
$token      = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+8 hours'));

$stmt = $pdo->prepare("
    INSERT INTO cip_tokens (usuario_id, token, expires_at, ativo)
    VALUES (?, ?, ?, 1)
");
$stmt->execute([$usuario['id'], $token, $expires_at]);

// -- Retorna token e dados do usuario -------------------------
respondOk([
    'token'      => $token,
    'expires_at' => $expires_at,
    'usuario'    => [
        'id'           => (int) $usuario['id'],
        'nome'         => $usuario['nome'],
        'email'        => $usuario['email'],
        'perfil'       => $usuario['perfil'],
        'papel_global' => $papelGlobal,                       // 👑 NOVO
        'empresa_id'   => $usuario['empresa_id']
                              ? (int) $usuario['empresa_id']
                              : null,
        'is_master'    => $ehMaster,                          // 👑 NOVO (flag pronta)
    ],
]);
