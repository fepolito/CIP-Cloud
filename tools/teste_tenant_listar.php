<?php
/**
 * ARQUIVO TEMPORARIO DE TESTE — REMOVER APOS VALIDACAO
 *
 * Arquivo: _teste_listar_controladores.php
 * Projeto: Controlador de Injecao de Potencia Eletrica
 * Objetivo: Validar visualmente o retorno de Tenant::listarControladores().
 *
 * @versao 1.1.0
 * @data   2026-06-05
 *
 * Historico:
 *   2026-06-04  v1.0.0  Criacao inicial
 *   2026-06-05  v1.1.0  Fix critico: removido session_start() puro
 *                       (que abria sessao com nome PHPSESSID default,
 *                       ignorando o cookie CIPESID do projeto).
 *                       Agora usa startSecureSession() do app/auth.php,
 *                       padrao unico autorizado no projeto.
 */

declare(strict_types=1);

// Ordem obrigatoria: configs ANTES de qualquer chamada de sessao.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers/Tenant.php';

use App\Helpers\Tenant;

// Unica forma autorizada de iniciar sessao no projeto (nome CIPESID + cookie params seguros).
startSecureSession();

header('Content-Type: application/json; charset=utf-8');

$contexto = Tenant::contexto();

if (!$contexto['autenticado']) {
    http_response_code(401);
    echo json_encode([
        'erro'     => 'nao_autenticado',
        'mensagem' => 'Faca login em /login.php antes de acessar este teste.',
        'debug'    => [
            'session_name'   => session_name(),
            'session_id'     => session_id(),
            'usuario_id_set' => isset($_SESSION['usuario_id']),
            'cookies_recebidos' => array_keys($_COOKIE ?? []),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDbConnection();

$empresaIdGet = isset($_GET['empresa_id']) ? (int) $_GET['empresa_id'] : null;

$controladores = Tenant::listarControladores($pdo, $empresaIdGet);

$resposta = [
    'contexto'            => $contexto,
    'filtro_aplicado'     => ['empresa_id_get' => $empresaIdGet],
    'total_controladores' => count($controladores),
    'controladores'       => $controladores,
];

echo json_encode($resposta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
