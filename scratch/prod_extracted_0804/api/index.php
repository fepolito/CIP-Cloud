<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injecao de Potencia Eletrica
 * ARQUIVO: api/index.php
 * =============================================================
 * OBJETIVO:
 *   Roteador central da API REST
 *
 * DEPENDENCIAS DE HARDWARE:
 *   - Servidor web PHP 8.3+
 *
 * DEPENDENCIAS DE ARQUIVOS:
 *   - config/app.php                          -> constantes globais
 *   - includes/config.php                     -> asset(), base configs
 *   - app/auth.php                            -> startSecureSession()
 *   - app/helpers.php                         -> respondOk(), respondError()
 *   - app/services/Database.php               -> Database::getInstance()
 *   - app/Controllers/ControladorController.php
 *   - app/Controllers/LeituraController.php
 *
 * HISTORICO:
 *   2026-04-06  v1.0.0  Criacao do roteador central
 *   2026-04-11  v1.1.0  Fix: caminhos require_once corrigidos
 *   2026-05-14  v1.2.0  Fix CRITICO: paths reais dos arquivos
 *                        - Database.php esta em app/services/
 *                        - helpers.php foi criado agora
 *                        - authControlador() implementada via token
 * =============================================================
 */

declare(strict_types=1);

// -- CORS -----------------------------------------------------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Token, X-CIP-Serial, X-CIP-TS, X-CIP-Sig');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}


// -- Bootstrap (PATHS CORRIGIDOS) -----------------------------
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../app/helpers.php';                       // <- novo
require_once __DIR__ . '/../app/services/Database.php';             // <- era app/Database.php
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../api/controllers/ControladorController.php';
require_once __DIR__ . '/../api/controllers/LeituraController.php';

// -- Sessao segura (somente para rotas que usam sessao web) ---
startSecureSession();

// -- Roteamento -----------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('#^' . rtrim(APP_BASE_PATH ?? '', '/') . '/api#', '', $uri);
$uri = rtrim($uri, '/');

$segments = explode('/', ltrim($uri, '/'));
$resource = $segments[0] ?? '';
$param    = $segments[1] ?? '';

// -- Dispatcher -----------------------------------------------
try {

    // -- /api/ping (smoke test sem auth) ----------------------
    if ($resource === 'ping') {
        respondOk([
            'pong'      => true,
            'timestamp' => date('c'),
            'env'       => defined('APP_ENV') ? APP_ENV : 'unknown',
        ]);
    }

    // -- /api/controladores -----------------------------------
    if ($resource === 'controladores') {

        $ctrl = new ControladorController();

        match (true) {
            $method === 'GET'  && $param === ''           => $ctrl->index(),
            $method === 'GET'  && is_numeric($param)      => $ctrl->show((int) $param),
            $method === 'POST' && $param === 'heartbeat'  => $ctrl->heartbeat(),
            default                                       => respondError(404, 'Rota nao encontrada'),
        };

    // -- /api/leituras ----------------------------------------
    } elseif ($resource === 'leituras') {

        $ctrl = new LeituraController();

        match (true) {
            $method === 'POST' && $param === ''           => $ctrl->store(),
            $method === 'GET'  && $param === 'agora'      => $ctrl->agora(),
            $method === 'GET'  && $param === 'historico'  => $ctrl->historico(),
            $method === 'GET'  && $param === 'resumo'     => $ctrl->resumo(),
            default                                       => respondError(404, 'Rota nao encontrada'),
        };

    // -- /api/auth/verify -------------------------------------
    } elseif ($resource === 'auth') {

        if ($param === 'verify' && $method === 'GET') {
            if (!isAuthenticated()) {
                respondError(401, 'Sessao invalida ou expirada');
            }
            respondOk([
                'usuario_id'     => $_SESSION['usuario_id']     ?? null,
                'usuario_nome'   => $_SESSION['usuario_nome']   ?? null,
                'usuario_perfil' => $_SESSION['usuario_perfil'] ?? null,
            ]);
        } else {
            respondError(404, 'Rota nao encontrada');
        }

    } else {
        respondError(404, 'Recurso nao encontrado');
    }

} catch (Throwable $e) {
    error_log(
        '[API][' . date('Y-m-d H:i:s') . '] ' .
        $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
    );

    $isProd = defined('APP_ENV') && APP_ENV === 'production';
    respondError(
        500,
        $isProd ? 'Erro interno do servidor'
                : $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
    );
}
