<?php
/**
 * tools/sync_puxar.php
 *
 * Sincronizador local — puxa dados do PROD para o BD DEV (Laragon)
 *
 * Uso:
 *   php sync_puxar.php                        # todas as tabelas, incremental
 *   php sync_puxar.php --tabela=controladores # apenas uma tabela
 *   php sync_puxar.php --reset                # reseta cursor e baixa tudo
 *   php sync_puxar.php --dry-run              # baixa mas nao grava no BD
 *   php sync_puxar.php --verbose              # mostra cada registro
 *
 * Requer: PHP 8.2+ CLI + extensoes pdo_mysql, curl
 *
 * @versao 1.0.0
 * @autor  CIP Cloud Copilot + Fernando
 * @criado_em 2026-06-01
 */
declare(strict_types=1);

// ── Garante execucao via CLI ────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script roda apenas via linha de comando.');
}

// ── Carrega config local + config do BD DEV ─────────────────────
$rootDir = realpath(__DIR__ . '/..');
$sync    = require __DIR__ . '/sync_config.php';

// Conexao BD local — usa o mesmo wrapper do projeto
require_once $rootDir . '/api/config/database.php';

// ── Parse de flags ──────────────────────────────────────────────
$flags = parseArgs($argv);

// ── Logger ──────────────────────────────────────────────────────
if (!is_dir($sync['log_dir'])) {
    mkdir($sync['log_dir'], 0755, true);
}
$logFile = $sync['log_dir'] . '/sync_' . date('Y-m-d') . '.log';

function logar(string $msg, bool $console = true): void
{
    global $logFile, $sync;
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    file_put_contents($logFile, $linha . PHP_EOL, FILE_APPEND | LOCK_EX);
    if ($console && $sync['log_console']) {
        echo $linha . PHP_EOL;
    }
}

// ── Estado (cursores persistidos) ───────────────────────────────
$stateFile = __DIR__ . '/sync_state.json';
$estado    = carregarEstado($stateFile);

if (!empty($flags['reset'])) {
    logar('⚠️  --reset informado: zerando todos os cursores.');
    $estado = [];
}

// ── Conecta no BD DEV ───────────────────────────────────────────
try {
    $pdo = Database::getInstance();
    logar('✅ Conectado no BD DEV (Laragon).');
} catch (Throwable $e) {
    logar('❌ Falha ao conectar BD DEV: ' . $e->getMessage());
    exit(1);
}

// ── Decide quais tabelas processar ──────────────────────────────
$tabelasParaProcessar = empty($flags['tabela'])
    ? array_keys($sync['tabelas'])
    : [$flags['tabela']];

foreach ($tabelasParaProcessar as $tabela) {
    if (!isset($sync['tabelas'][$tabela])) {
        logar("❌ Tabela '$tabela' nao configurada em sync_config.php");
        continue;
    }

    logar('');
    logar("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    logar("📥 SINCRONIZANDO: $tabela");
    logar("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

    sincronizarTabela($tabela, $sync, $estado, $pdo, $flags);

    // Salva estado apos cada tabela (resiliencia)
    salvarEstado($stateFile, $estado);
}

logar('');
logar('🎉 Sincronizacao concluida.');
logar('Estado salvo em: ' . $stateFile);

// =====================================================================
// FUNCOES
// =====================================================================

/**
 * Sincroniza uma tabela em loop ate esgotar.
 */
function sincronizarTabela(
    string $tabela,
    array  $sync,
    array  &$estado,
    PDO    $pdo,
    array  $flags
): void {
    $cfg     = $sync['tabelas'][$tabela];
    $cursor  = $estado[$tabela] ?? $cfg['cursor_inicial'];
    $paramK  = $cfg['param_cursor'];

    $totalBaixado = 0;
    $totalInserido = 0;
    $totalAtualizado = 0;
    $totalIgnorado = 0;
    $pagina = 0;

    while (true) {
        $pagina++;
        $valorCursor = $cursor[$paramK] ?? '';
        logar("📄 Pagina $pagina | cursor: $paramK = $valorCursor");

        // ── Chama endpoint ──
        $resposta = chamarEndpoint($tabela, $cursor, $sync);
        if ($resposta === null) {
            logar("❌ Falha na pagina $pagina. Abortando '$tabela'.");
            return;
        }

        $qtd = $resposta['qtd'] ?? 0;
        $registros = $resposta['registros'] ?? [];
        $totalBaixado += $qtd;

        if ($qtd === 0) {
            logar("✅ Nada novo. Tabela '$tabela' ja esta sincronizada.");
            break;
        }

        // ── Grava no BD ──
        if (empty($flags['dry-run'])) {
            $r = gravarLote($pdo, $tabela, $registros, $cfg, $flags);
            $totalInserido   += $r['inserido'];
            $totalAtualizado += $r['atualizado'];
            $totalIgnorado   += $r['ignorado'];
            logar(sprintf(
                '   💾 Lote: %d baixados | %d inseridos | %d atualizados | %d ignorados',
                $qtd, $r['inserido'], $r['atualizado'], $r['ignorado']
            ));
        } else {
            logar("   🔍 DRY-RUN: $qtd registros (nao gravados)");
        }

        // ── Atualiza cursor ──
        if (!empty($resposta['cursor_proximo'])) {
            $cursor = $resposta['cursor_proximo'];
            $estado[$tabela] = $cursor;
        }

        // ── Fim do paging? ──
        if (empty($resposta['tem_mais'])) {
            logar("✅ Fim do paging para '$tabela'.");
            break;
        }

        // ── Throttle leve pra nao martelar o PROD ──
        usleep(200_000); // 200ms
    }

    logar(sprintf(
        '📊 RESUMO %s: %d baixados | %d inseridos | %d atualizados | %d ignorados',
        $tabela, $totalBaixado, $totalInserido, $totalAtualizado, $totalIgnorado
    ));
}

/**
 * Chama o endpoint /api/sync/exportar.php
 */
function chamarEndpoint(string $tabela, array $cursor, array $sync): ?array
{
    $url = $sync['url_base'] . '?tabela=' . urlencode($tabela);
    foreach ($cursor as $k => $v) {
        $url .= '&' . $k . '=' . urlencode((string)$v);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $sync['timeout_seg'],
        CURLOPT_HTTPHEADER     => ['X-Sync-Token: ' . $sync['token']],
        CURLOPT_USERAGENT      => 'CIP-Sync-Client/1.0',
    ]);

    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        logar("   ❌ cURL erro: $err");
        return null;
    }

    if ($http !== 200) {
        logar("   ❌ HTTP $http | body: " . substr((string)$body, 0, 300));
        return null;
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json) || ($json['status'] ?? '') !== 'ok') {
        logar('   ❌ Resposta invalida: ' . substr((string)$body, 0, 300));
        return null;
    }

    return $json;
}

/**
 * Grava lote no BD conforme estrategia (upsert | insert_ignore)
 */
function gravarLote(PDO $pdo, string $tabela, array $registros, array $cfg, array $flags): array
{
    $contador = ['inserido' => 0, 'atualizado' => 0, 'ignorado' => 0];

    if (empty($registros)) {
        return $contador;
    }

    $colunas = array_keys($registros[0]);
    $colunasEscapadas = array_map(fn($c) => '`' . $c . '`', $colunas);
    $placeholders = ':' . implode(', :', $colunas);
    $tabelaEsc = '`' . $tabela . '`';

    if ($cfg['estrategia'] === 'insert_ignore') {
        $sql = "INSERT IGNORE INTO {$tabelaEsc} (" 
             . implode(',', $colunasEscapadas) 
             . ") VALUES ({$placeholders})";

    } elseif ($cfg['estrategia'] === 'upsert') {
        $colsUpdate = $cfg['colunas_update']
            ?? array_diff($colunas, [$cfg['pk']]);

        $setParts = [];
        foreach ($colsUpdate as $c) {
            $setParts[] = "`$c` = VALUES(`$c`)";
        }
        $sql = "INSERT INTO {$tabelaEsc} ("
             . implode(',', $colunasEscapadas)
             . ") VALUES ({$placeholders}) "
             . "ON DUPLICATE KEY UPDATE " . implode(', ', $setParts);

    } else {
        logar("   ❌ Estrategia desconhecida: {$cfg['estrategia']}");
        return $contador;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);

        foreach ($registros as $reg) {
            // Bind seguro
            foreach ($colunas as $c) {
                $valor = $reg[$c] ?? null;
                $stmt->bindValue(':' . $c, $valor, paramTypeFor($valor));
            }
            $stmt->execute();

            // rowCount no MySQL com ON DUPLICATE:
            //   0 = sem mudanca | 1 = INSERT | 2 = UPDATE
            $rc = $stmt->rowCount();
            if ($cfg['estrategia'] === 'upsert') {
                if ($rc === 1)      $contador['inserido']++;
                elseif ($rc === 2)  $contador['atualizado']++;
                else                $contador['ignorado']++;
            } else { // insert_ignore
                if ($rc === 1) $contador['inserido']++;
                else           $contador['ignorado']++;
            }

            if (!empty($flags['verbose'])) {
                logar('      → ' . $cfg['pk'] . '=' . ($reg[$cfg['pk']] ?? '?') . " (rc=$rc)", false);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        logar('   ❌ Erro no lote: ' . $e->getMessage());
        throw $e;
    }

    return $contador;
}

function paramTypeFor($valor): int
{
    if (is_null($valor)) return PDO::PARAM_NULL;
    if (is_bool($valor)) return PDO::PARAM_BOOL;
    if (is_int($valor))  return PDO::PARAM_INT;
    return PDO::PARAM_STR;
}

function carregarEstado(string $arquivo): array
{
    if (!is_file($arquivo)) return [];
    $j = json_decode((string)file_get_contents($arquivo), true);
    return is_array($j) ? $j : [];
}

function salvarEstado(string $arquivo, array $estado): void
{
    file_put_contents(
        $arquivo,
        json_encode($estado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function parseArgs(array $argv): array
{
    $flags = [];
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--')) {
            $par = substr($a, 2);
            if (str_contains($par, '=')) {
                [$k, $v] = explode('=', $par, 2);
                $flags[$k] = $v;
            } else {
                $flags[$par] = true;
            }
        }
    }
    return $flags;
}
