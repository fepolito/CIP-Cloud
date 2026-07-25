<?php
// ============================================================
// Arquivo:      api/limites/tabela.php
// Finalidade:   Retorna e salva a tabela de limites de potência (24h x 3 perfis).
//               Faz insert na tabela de log e gerencia a versao ativa.
// Historico:    v1.1.0
//               2026-07-25 - Adicionado potencia_max_kw (@modificado_em 2026-07-25)
// ============================================================

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';

$is_dev = defined('IS_DEV') ? IS_DEV : false;

// Autentica o usuário e inicia a sessão (emite 401 se falhar)
$usuario = authUsuario();
$_SESSION['usuario_id'] = $usuario['id'];

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['sucesso' => false, 'erro' => 'Banco de dados indisponivel', 'detalhe' => $is_dev ? $e->getMessage() : null]);
    exit;
}

// Extrai controlador_id dependendo do método HTTP
$controlador_id = 0;
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $controlador_id = filter_input(INPUT_GET, 'controlador_id', FILTER_VALIDATE_INT);
} elseif ($method === 'POST') {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['controlador_id'])) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'erro' => 'Payload invalido']);
        exit;
    }
    $controlador_id = (int)$data['controlador_id'];
} else {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Metodo nao permitido']);
    exit;
}

if (!$controlador_id || $controlador_id <= 0) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'controlador_id invalido']);
    exit;
}

// MULTI-TENANT: validar se o controlador pertence ao tenant da sessão
$allowed_controllers = \App\Helpers\Tenant::listarControladores($pdo);
$is_allowed = false;
foreach ($allowed_controllers as $c) {
    if ((int)$c['id'] === $controlador_id) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Acesso negado a este controlador']);
    exit;
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT versao, payload_json, sync_status, criada_em, aplicada_em FROM tabela_limites WHERE controlador_id = :cid AND ativa = 1 LIMIT 1");
        $stmt->execute([':cid' => $controlador_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $payloadData = json_decode($row['payload_json'], true);
            echo json_encode([
                'sucesso' => true,
                'data' => [
                    'versao' => (int)$row['versao'],
                    'payload' => $payloadData,
                    'sync_status' => $row['sync_status'],
                    'criada_em' => $row['criada_em'],
                    'aplicada_em' => $row['aplicada_em'],
                    'potencia_max_kw' => isset($payloadData['potencia_max_kw']) ? (float)$payloadData['potencia_max_kw'] : POTENCIA_MAX_KW_FALLBACK
                ]
            ]);
        } else {
            // Retornar zerado se não houver linha
            $zerado = array_fill(0, 24, 0.0);
            $payload = [
                'dias_uteis' => $zerado,
                'sabado' => $zerado,
                'domingo_feriado' => $zerado
            ];
            echo json_encode([
                'sucesso' => true,
                'data' => [
                    'versao' => 0,
                    'payload' => $payload,
                    'sync_status' => 'sincronizada',
                    'potencia_max_kw' => POTENCIA_MAX_KW_FALLBACK
                ]
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno', 'detalhe' => $is_dev ? $e->getMessage() : null]);
    }
    exit;
}

if ($method === 'POST') {
    if (!isset($data['payload']) || !is_array($data['payload'])) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'erro' => 'Payload ausente ou invalido']);
        exit;
    }
    
    $payload = $data['payload'];
    
    // DEC-009: Snapshot de potencia_max_kw
    if (!isset($payload['potencia_max_kw'])) {
        $payload['potencia_max_kw'] = POTENCIA_MAX_KW_FALLBACK;
    } elseif (!is_numeric($payload['potencia_max_kw']) || (float)$payload['potencia_max_kw'] < 0) {
        http_response_code(400);
        echo json_encode(['sucesso' => false, 'erro' => "potencia_max_kw deve ser numerico e positivo"]);
        exit;
    }
    $payload['potencia_max_kw'] = (float)$payload['potencia_max_kw'];
    
    $chaves_esperadas = ['dias_uteis', 'sabado', 'domingo_feriado'];
    
    foreach ($chaves_esperadas as $ch) {
        if (!isset($payload[$ch]) || !is_array($payload[$ch]) || count($payload[$ch]) !== 24) {
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'erro' => "A chave $ch deve ser um array de 24 numeros"]);
            exit;
        }
        foreach ($payload[$ch] as $val) {
            if (!is_numeric($val) || (float)$val < 0) {
                http_response_code(400);
                echo json_encode(['sucesso' => false, 'erro' => "Todos os valores de $ch devem ser numericos >= 0"]);
                exit;
            }
        }
    }

    try {
        $pdo->beginTransaction();

        // SELECT FOR UPDATE
        $stmtSelect = $pdo->prepare("SELECT id, versao, payload_json, origem, usuario_id, sync_status FROM tabela_limites WHERE controlador_id = :cid AND ativa = 1 FOR UPDATE");
        $stmtSelect->execute([':cid' => $controlador_id]);
        $rowAtiva = $stmtSelect->fetch(PDO::FETCH_ASSOC);

        $nova_versao = 1;
        if ($rowAtiva) {
            $nova_versao = (int)$rowAtiva['versao'] + 1;
            
            // Insert na tabela de historico copiando a antiga
            $stmtHist = $pdo->prepare("INSERT INTO tabela_limites_historico (controlador_id, versao, payload_json, origem, usuario_id, sync_status_final) VALUES (:cid, :v, :p, :o, :u, :s)");
            $stmtHist->execute([
                ':cid' => $controlador_id,
                ':v' => $rowAtiva['versao'],
                ':p' => $rowAtiva['payload_json'],
                ':o' => $rowAtiva['origem'],
                ':u' => $rowAtiva['usuario_id'],
                ':s' => $rowAtiva['sync_status']
            ]);

            // Desativa linha antiga
            $stmtUpdate = $pdo->prepare("UPDATE tabela_limites SET ativa = 0 WHERE id = :id");
            $stmtUpdate->execute([':id' => $rowAtiva['id']]);
        }

        // Insere nova linha
        $stmtInsert = $pdo->prepare("INSERT INTO tabela_limites (controlador_id, versao, payload_json, origem, usuario_id, sync_status, ativa) VALUES (:cid, :v, :p, 'cloud', :u, 'pendente_ack', 1)");
        $stmtInsert->execute([
            ':cid' => $controlador_id,
            ':v' => $nova_versao,
            ':p' => json_encode($payload),
            ':u' => (int)$_SESSION['usuario_id']
        ]);

        $pdo->commit();

        echo json_encode([
            'sucesso' => true,
            'data' => [
                'versao_nova' => $nova_versao,
                'sync_status' => 'pendente_ack'
            ]
        ]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro interno na transacao', 'detalhe' => $is_dev ? $e->getMessage() : null]);
    }
    exit;
}
