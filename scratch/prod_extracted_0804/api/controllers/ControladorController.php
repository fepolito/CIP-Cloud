<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injeção de Potência Elétrica
 * ARQUIVO: app/Controllers/ControladorController.php
 * =============================================================
 * OBJETIVO:
 *   Gerenciar endpoints REST dos controladores ESP32
 *
 * DEPENDÊNCIAS DE HARDWARE:
 *   - ESP32 (heartbeat via X-API-Token)
 *   - Servidor web PHP 8.3+
 *
 * DEPENDÊNCIAS DE ARQUIVOS:
 *   - app/Database.php
 *   - app/helpers.php → authUsuario(), authControlador(),
 *                       respondOk(), respondError()
 *   - config/app.php
 *
 * HISTÓRICO:
 *   2026-04-08  v1.0.0  Criação inicial
 *   2026-04-11  v1.1.0  Fix perfis globais master/administrador
 *   2026-04-11  v1.2.0  Fix: const dentro de método → CLASS_CONST
 *                        Corrige Fatal Error PHP 8.3 em resolveOnlineStatus()
 * =============================================================
 */

declare(strict_types=1);

class ControladorController
{
    /** Perfis com acesso global (veem todos os controladores) */
    private const PERFIS_GLOBAIS = ['master', 'master_operador', 'administrador'];

    /** Segundos sem contato para considerar offline */
    private const TIMEOUT_OFFLINE = 120;

    /** Segundos sem contato para considerar em alerta */
    private const TIMEOUT_ALERTA = 300;

    // ----------------------------------------------------------
    // GET /api/controladores
    // ----------------------------------------------------------
    public function index(): void
    {
        $usuario  = authUsuario();
        $pdo      = Database::getInstance();
        $isGlobal = in_array($usuario['perfil'], self::PERFIS_GLOBAIS, true);

        if ($isGlobal) {
            $stmt = $pdo->query("
                SELECT
                    c.id,
                    c.apelido,
                    c.codigo,
                    c.cliente_nome,
                    c.local_instalacao,
                    c.ip_address,
                    c.fw_version,
                    c.status,
                    c.online,
                    c.ultimo_contato,
                    c.last_seen_at,
                    c.last_telemetry_at,
                    e.nome AS empresa_nome
                FROM controladores c
                LEFT JOIN empresa e ON e.id = c.empresa_id
                ORDER BY c.apelido
            ");
        } else {
            if (empty($usuario['empresa_id'])) {
                respondError(403, 'Usuário sem empresa vinculada');
                return;
            }

            $stmt = $pdo->prepare("
                SELECT
                    c.id,
                    c.apelido,
                    c.codigo,
                    c.cliente_nome,
                    c.local_instalacao,
                    c.ip_address,
                    c.fw_version,
                    c.status,
                    c.online,
                    c.ultimo_contato,
                    c.last_seen_at,
                    c.last_telemetry_at,
                    e.nome AS empresa_nome
                FROM controladores c
                LEFT JOIN empresa e ON e.id = c.empresa_id
                WHERE c.empresa_id = ?
                ORDER BY c.apelido
            ");
            $stmt->execute([$usuario['empresa_id']]);
        }

        $controladores = $stmt->fetchAll();

        foreach ($controladores as &$c) {
            $c['online_status'] = $this->resolveOnlineStatus(
                (bool) $c['online'],
                $c['ultimo_contato']
            );
        }
        unset($c);

        respondOk($controladores);
    }

    // ----------------------------------------------------------
    // GET /api/controladores/{id}
    // ----------------------------------------------------------
    public function show(int $id): void
    {
        $usuario  = authUsuario();
        $pdo      = Database::getInstance();
        $isGlobal = in_array($usuario['perfil'], self::PERFIS_GLOBAIS, true);

        $sql    = "
            SELECT c.*, e.nome AS empresa_nome
            FROM controladores c
            LEFT JOIN empresa e ON e.id = c.empresa_id
            WHERE c.id = :id
        ";
        $params = [':id' => $id];

        if (!$isGlobal) {
            if (empty($usuario['empresa_id'])) {
                respondError(403, 'Usuário sem empresa vinculada');
                return;
            }
            $sql .= " AND c.empresa_id = :empresa_id";
            $params[':empresa_id'] = $usuario['empresa_id'];
        }

        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $controlador = $stmt->fetch();

        if (!$controlador) {
            respondError(404, 'Controlador não encontrado');
            return;
        }

        $controlador['online_status'] = $this->resolveOnlineStatus(
            (bool) $controlador['online'],
            $controlador['ultimo_contato']
        );

        respondOk($controlador);
    }

    // ----------------------------------------------------------
    // POST /api/controladores/heartbeat
    // Header: X-API-Token: {token}
    // Body JSON: { "ip": "...", "fw_version": "...", "rssi": -65 }
    // ----------------------------------------------------------
    public function heartbeat(): void
    {
        $controlador = authControlador();
        $pdo         = Database::getInstance();
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];

        $stmt = $pdo->prepare("
            UPDATE controladores SET
                online         = 1,
                ultimo_contato = NOW(),
                last_seen_at   = NOW(),
                ip_address     = COALESCE(:ip, ip_address),
                fw_version     = COALESCE(:fw, fw_version),
                updated_at     = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':ip' => $body['ip']         ?? null,
            ':fw' => $body['fw_version'] ?? null,
            ':id' => $controlador['id'],
        ]);

        respondOk([
            'controlador_id' => $controlador['id'],
            'server_time'    => date('c'),
        ], 'Heartbeat recebido');
    }

    // ----------------------------------------------------------
    // Helper: resolve status online baseado em tempo
    // ----------------------------------------------------------
    private function resolveOnlineStatus(bool $online, ?string $ultimoContato): array
    {
        if (!$online || !$ultimoContato) {
            return ['status' => 'offline', 'cor' => '#ef4444', 'label' => 'Offline'];
        }

        $diff = time() - strtotime($ultimoContato);

        return match (true) {
            $diff <= self::TIMEOUT_OFFLINE => ['status' => 'online',  'cor' => '#22c55e', 'label' => 'Online'],
            $diff <= self::TIMEOUT_ALERTA  => ['status' => 'alerta',  'cor' => '#f59e0b', 'label' => 'Sem sinal'],
            default                        => ['status' => 'offline', 'cor' => '#ef4444', 'label' => 'Offline'],
        };
    }
}