<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injeção de Potência Elétrica
 * ARQUIVO: api/energia/controladores.php
 * =============================================================
 * OBJETIVO:
 *   Retorna lista de controladores ativos filtrada pelo perfil
 *   do usuário autenticado na sessão PHP.
 *
 *   Regras de visibilidade:
 *     master / master_operador → todos os controladores ativos
 *     administrador            → controladores da empresa do usuário
 *     operador / usuario       → via pivot usuario_dispositivo
 *
 * DEPENDÊNCIAS DE ARQUIVOS:
 *   - app/auth.php              → requireAuthApi(), isAuthenticated()
 *   - api/config/database.php   → classe Database (wrapper singleton)
 *   - app/services/Database.php → App\Services\Database::getConnection()
 *
 * DEPENDÊNCIAS DE HARDWARE (banco de dados):
 *   - Tabela: controladores
 *   - Tabela: usuarios
 *   - Tabela: empresa
 *   - Tabela: usuario_dispositivo
 *
 * HISTÓRICO:
 *   2026-04-10  v1.0  Criação inicial — sem filtro de perfil
 *   2026-04-11  v1.1  Filtro por perfil: master/admin/operador/usuario
 *   2026-04-11  v1.2  ENUMs corrigidos para estrutura real da tabela
 *   2026-04-11  v1.3  Corrigido: require verify.php → app/auth.php
 *                     requireAuth() → requireAuthApi() (sem redirect)
 *                     error_log movido para após declaração das variáveis
 *   2026-04-11  v1.4  Fix crítico: $pdo undefined
 *                     Adicionado $pdo = Database::getInstance() explícito
 *                     após os requires — variável global nunca existiu
 * =============================================================
 */

declare(strict_types=1);

// ── Dependências ──────────────────────────────────────────────
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../config/database.php';   // declara classe Database

header('Content-Type: application/json; charset=utf-8');

// ── Autenticação (retorna JSON 401, não redirect) ─────────────
requireAuthApi();

// ── Conexão PDO ───────────────────────────────────────────────
$pdo = Database::getInstance();   // ← FIX v1.4: linha que faltava!

// ── Dados da sessão ───────────────────────────────────────────
$usuarioId     = (int)    ($_SESSION['usuario_id']     ?? 0);
$usuarioPerfil = (string) ($_SESSION['usuario_perfil'] ?? '');

error_log('[CIP][controladores] usuario_id=' . $usuarioId . ' perfil=' . $usuarioPerfil);

if ($usuarioId === 0) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão inválida']);
    exit;
}

// ── Perfis ────────────────────────────────────────────────────
const PERFIS_MASTER = ['master', 'master_operador'];
const PERFIS_ADMIN  = ['administrador'];
const PERFIS_PIVOT  = ['operador', 'usuario'];

// ── Queries por perfil ────────────────────────────────────────
try {

    if (in_array($usuarioPerfil, PERFIS_MASTER, true)) {

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.codigo,
                c.apelido,
                c.local_instalacao,
                c.status,
                c.online,
                c.ultimo_contato,
                e.nome_fantasia AS empresa_nome
            FROM controladores c
            LEFT JOIN empresa e ON e.id = c.empresa_id
            WHERE c.status = 'ativo'
            ORDER BY e.nome_fantasia ASC, c.apelido ASC
        ");
        $stmt->execute();

    } elseif (in_array($usuarioPerfil, PERFIS_ADMIN, true)) {

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.codigo,
                c.apelido,
                c.local_instalacao,
                c.status,
                c.online,
                c.ultimo_contato,
                e.nome_fantasia AS empresa_nome
            FROM controladores c
            INNER JOIN empresa e ON e.id = c.empresa_id
            INNER JOIN usuarios u ON u.empresa_id = e.id
            WHERE c.status = 'ativo'
              AND u.id     = :usuario_id
            ORDER BY c.apelido ASC
        ");
        $stmt->execute([':usuario_id' => $usuarioId]);

    } elseif (in_array($usuarioPerfil, PERFIS_PIVOT, true)) {

        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.codigo,
                c.apelido,
                c.local_instalacao,
                c.status,
                c.online,
                c.ultimo_contato,
                e.nome_fantasia AS empresa_nome
            FROM controladores c
            LEFT JOIN empresa e ON e.id = c.empresa_id
            INNER JOIN usuario_dispositivo ud ON ud.controlador_id = c.id
            WHERE c.status      = 'ativo'
              AND ud.usuario_id = :usuario_id
            ORDER BY c.apelido ASC
        ");
        $stmt->execute([':usuario_id' => $usuarioId]);

    } else {

        echo json_encode([
            'sucesso'       => true,
            'controladores' => [],
            'aviso'         => 'Perfil sem permissão de acesso',
        ]);
        exit;
    }

    $controladores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($controladores as &$c) {
        $c['id']     = (int) $c['id'];
        $c['online'] = (int) $c['online'];
    }
    unset($c);

    echo json_encode([
        'sucesso'       => true,
        'perfil'        => $usuarioPerfil,
        'controladores' => $controladores,
    ]);

} catch (Throwable $e) {
    error_log('[CIP][controladores.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro interno']);
}