<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injeção de Potência Elétrica
 * ARQUIVO: app/services/HmacAuth.php
 * =============================================================
 * OBJETIVO:
 *   Lógica isolada de autenticação HMAC-SHA256 para dispositivos
 *   IoT (ESP32). Espelha exatamente o _build_hmac() do firmware
 *   (src/aer_cloud_client.cpp).
 *
 * ALGORITMO:
 *   1. Extrai headers X-CIP-Serial, X-CIP-TS, X-CIP-Sig
 *   2. Valida janela de tempo (anti-replay)
 *   3. Recalcula HMAC-SHA256( secret, "<serial>:<ts_window>" )
 *   4. Compara com assinatura recebida via hash_equals() (timing-safe)
 *
 * HISTÓRICO:
 *   2026-05-15  v1.0.0  Criação
 * =============================================================
 */

declare(strict_types=1);

class HmacAuth
{
    /** Janela anti-replay em segundos — deve ser igual ao firmware */
    private const HMAC_WINDOW = 30;

    /** Tolerância de drift de relógio: aceita janela atual ± 1 */
    private const WINDOW_TOLERANCE = 1;

    // ----------------------------------------------------------
    // Método principal: valida a requisição e retorna o controlador
    // Lança RuntimeException com mensagem legível em caso de falha
    // ----------------------------------------------------------

    /**
     * @return array  Row da tabela controladores (id, codigo, empresa_id, status, ...)
     * @throws RuntimeException  Em caso de falha de autenticação
     */
    public static function autenticar(): array
    {
        // ── 1. Extrai headers ──────────────────────────────────────────────────
        $serial    = trim($_SERVER['HTTP_X_CIP_SERIAL'] ?? '');
        $tsWindow  = trim($_SERVER['HTTP_X_CIP_TS']     ?? '');
        $sigRecv   = trim($_SERVER['HTTP_X_CIP_SIG']    ?? '');

        if ($serial === '' || $tsWindow === '' || $sigRecv === '') {
            throw new RuntimeException('Headers de autenticação ausentes', 401);
        }

        if (!ctype_digit($tsWindow)) {
            throw new RuntimeException('X-CIP-TS inválido', 401);
        }

        // ── 2. Valida janela anti-replay ───────────────────────────────────────
        $tsWindowInt  = (int) $tsWindow;
        $nowWindow    = self::currentWindow();

        // Aceita janela atual e até WINDOW_TOLERANCE janelas anteriores
        // (compensa drift de relógio e latência de rede)
        $windowDiff = abs($nowWindow - $tsWindowInt) / self::HMAC_WINDOW;

        if ($windowDiff > self::WINDOW_TOLERANCE) {
            throw new RuntimeException(
                sprintf(
                    'Timestamp fora da janela (recebido=%d esperado≈%d diff=%d janelas)',
                    $tsWindowInt,
                    $nowWindow,
                    (int) $windowDiff
                ),
                401
            );
        }

        // ── 3. Busca controlador pelo serial (codigo) ──────────────────────────
        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id, codigo, empresa_id, status, hmac_secret, fw_version
            FROM controladores
            WHERE codigo = :codigo
              AND status = 'ativo'
            LIMIT 1
        ");
        $stmt->execute([':codigo' => $serial]);
        $controlador = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$controlador) {
            // Sem distinção entre "não encontrado" e "inativo" — evita enumeração
            throw new RuntimeException('Controlador não autorizado', 401);
        }

        if (empty($controlador['hmac_secret'])) {
            throw new RuntimeException('Controlador sem secret configurado', 403);
        }

        // ── 4. Recalcula HMAC e compara (timing-safe) ─────────────────────────
        $message  = $serial . ':' . $tsWindow;
        $sigCalc  = hash_hmac('sha256', $message, $controlador['hmac_secret']);

        if (!hash_equals($sigCalc, strtolower($sigRecv))) {
            throw new RuntimeException('Assinatura HMAC inválida', 401);
        }

        // ── 5. Limpa secret antes de retornar (não expõe ao controller) ───────
        unset($controlador['hmac_secret']);

        return $controlador;
    }

    // ----------------------------------------------------------
    // Helpers privados
    // ----------------------------------------------------------

    /** Retorna o início da janela atual (arredondado para HMAC_WINDOW) */
    private static function currentWindow(): int
    {
        return (int)(floor(time() / self::HMAC_WINDOW) * self::HMAC_WINDOW);
    }
}
