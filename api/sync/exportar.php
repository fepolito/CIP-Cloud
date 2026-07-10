<?php
/**
 * Endpoint de exportacao incremental para sincronizar BD de teste
 *
 * Uso:
 *   GET /api/sync/exportar.php?tabela=telemetria_5min&desde_id=12345
 *   GET /api/sync/exportar.php?tabela=controladores&desde_ts=2026-05-31T12:00:00Z
 *
 * Header obrigatorio: X-Sync-Token: <token>
 *
 * Resposta JSON:
 *   { status, tabela, modo, qtd, tem_mais, cursor_proximo, registros }
 *
 * @versao 1.2.0
 * @autor  CIP Cloud Copilot + Fernando
 * @criado_em     2026-05-31
 * @modificado_em 2026-06-01
 *
 * Changelog:
 * - 1.0.0: versao inicial
 * - 1.1.0: whitelist associativa + 3 tipos de cursor + anonimizacao configuravel
 * - 1.2.0: alinhado com padrao do projeto -> usa api/config/database.php
 *          (Database::getInstance) em vez de helper inexistente Db
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// =====================================================
// 1. Carrega config de sync (fora do docroot)
// =====================================================
$configPath = __DIR__ . '/../../../config/sync.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['status' => 'erro', 'mensagem' => 'config ausente']);
    exit;
}
$config = require $configPath;

// =====================================================
// 2. Autenticacao por token (timing-safe)
// =====================================================
$tokenRecebido = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? '';
if (!hash_equals((string)$config['token'], (string)$tokenRecebido)) {
    http_response_code(401);
    echo json_encode(['status' => 'erro', 'mensagem' => 'token invalido']);
    error_log('[sync] token invalido de ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit;
}

// =====================================================
// 3. Autenticacao opcional por IP
// =====================================================
$ipsPermitidos = $config['config']['ips_permitidos'] ?? [];
if (!empty($ipsPermitidos)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, $ipsPermitidos, true)) {
        http_response_code(403);
        echo json_encode(['status' => 'erro', 'mensagem' => 'ip nao autorizado']);
        exit;
    }
}

// =====================================================
// 4. Valida tabela contra whitelist (formato associativo)
// =====================================================
$tabela = (string)($_GET['tabela'] ?? '');
$tabelasPermitidas = $config['tabelas_permitidas'] ?? [];

if (!isset($tabelasPermitidas[$tabela])) {
    http_response_code(403);
    echo json_encode(['status' => 'erro', 'mensagem' => 'tabela nao permitida']);
    exit;
}

$metaTabela        = $tabelasPermitidas[$tabela];
$colunaIncremental = $metaTabela['coluna_incremental'] ?? 'id';
$tipoIncremental   = $metaTabela['tipo_incremental']   ?? 'autoincrement';
$limite            = (int)($metaTabela['limite_por_request'] ?? 1000);
$regrasAnonimizar  = $metaTabela['anonimizar'] ?? [];

// Sanity check no limite
$limite = max(1, min(10000, $limite));

// =====================================================
// 5. Conecta no BD via wrapper da camada API
// =====================================================
// api/sync/exportar.php  ->  api/config/database.php  (../config/)
require_once __DIR__ . '/../config/database.php';

try {
    $pdo = Database::getInstance();

    // =====================================================
    // 6. Monta query incremental conforme tipo
    // =====================================================
    $colEscapada = '`' . str_replace('`', '', $colunaIncremental) . '`';
    $tabEscapada = '`' . str_replace('`', '', $tabela) . '`';

    if ($tipoIncremental === 'autoincrement') {
        $desdeId = (int)($_GET['desde_id'] ?? 0);
        $sql = "SELECT * FROM {$tabEscapada}
                WHERE {$colEscapada} > :desde
                ORDER BY {$colEscapada} ASC
                LIMIT :lim";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':desde', $desdeId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',   $limite,  PDO::PARAM_INT);
        $modo = 'por_id';

    } elseif ($tipoIncremental === 'timestamp' || $tipoIncremental === 'datetime') {
        $desdeTs = (string)($_GET['desde_ts'] ?? '1970-01-01 00:00:00');
        $desdeTs = str_replace(['T', 'Z'], [' ', ''], $desdeTs);
        $desdeTs = trim($desdeTs);

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desdeTs)) {
            http_response_code(400);
            echo json_encode(['status' => 'erro', 'mensagem' => 'desde_ts invalido']);
            exit;
        }

        $sql = "SELECT * FROM {$tabEscapada}
                WHERE {$colEscapada} > :desde
                ORDER BY {$colEscapada} ASC, id ASC
                LIMIT :lim";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':desde', $desdeTs, PDO::PARAM_STR);
        $stmt->bindValue(':lim',   $limite,  PDO::PARAM_INT);
        $modo = 'por_timestamp';

    } else {
        http_response_code(500);
        echo json_encode(['status' => 'erro', 'mensagem' => 'tipo_incremental invalido']);
        exit;
    }

    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // =====================================================
    // 7. Anonimiza colunas sensiveis
    // =====================================================
    if (!empty($regrasAnonimizar)) {
        $hashSenha = (string)($config['senha_dev_hash'] ?? '');

        foreach ($registros as &$reg) {
            foreach ($regrasAnonimizar as $coluna => $regra) {
                if (!array_key_exists($coluna, $reg)) {
                    continue;
                }
                switch ($regra) {
                    case 'email_fake':
                        $idRef = $reg['id'] ?? 'x';
                        $reg[$coluna] = 'user' . $idRef . '@teste.local';
                        break;

                    case 'hash_fixo':
                        $reg[$coluna] = $hashSenha;
                        break;

                    case 'mascara':
                        $orig = (string)$reg[$coluna];
                        $reg[$coluna] = str_repeat('X', strlen($orig));
                        break;

                    default:
                        $reg[$coluna] = $regra;
                }
            }
        }
        unset($reg);
    }

    // =====================================================
    // 8. Cursor proximo
    // =====================================================
    $cursorProximo = null;
    if (count($registros) > 0) {
        $ultimo = end($registros);
        if ($modo === 'por_id') {
            $cursorProximo = ['desde_id' => (int)$ultimo[$colunaIncremental]];
        } else {
            $cursorProximo = ['desde_ts' => (string)$ultimo[$colunaIncremental]];
        }
        reset($registros);
    }

    // =====================================================
    // 9. Log de auditoria
    // =====================================================
    if (!empty($config['config']['log_acessos'])) {
        error_log(sprintf(
            '[sync] tabela=%s modo=%s qtd=%d ip=%s',
            $tabela,
            $modo,
            count($registros),
            $_SERVER['REMOTE_ADDR'] ?? '?'
        ));
    }

    // =====================================================
    // 10. Resposta
    // =====================================================
    echo json_encode([
        'status'         => 'ok',
        'tabela'         => $tabela,
        'modo'           => $modo,
        'qtd'            => count($registros),
        'tem_mais'       => count($registros) === $limite,
        'cursor_proximo' => $cursorProximo,
        'registros'      => $registros,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    error_log('[sync] erro: ' . $e->getMessage());

    $ambiente = $config['config']['ambiente'] ?? 'producao';
    $resposta = ['status' => 'erro', 'mensagem' => 'erro interno'];
    if ($ambiente === 'desenvolvimento') {
        $resposta['debug'] = [
            'msg'     => $e->getMessage(),
            'arquivo' => basename($e->getFile()),
            'linha'   => $e->getLine(),
        ];
    }
    echo json_encode($resposta);
}
