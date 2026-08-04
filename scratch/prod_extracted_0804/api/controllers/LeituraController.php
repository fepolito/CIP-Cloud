<?php
/**
 * =============================================================
 * PROJETO: Controlador de Injecao de Potencia Eletrica
 * ARQUIVO: api/controllers/LeituraController.php
 * =============================================================
 * OBJETIVO:
 *   Endpoints REST de leituras de energia (telemetria ESP32)
 *
 * HISTORICO:
 *   2026-04-08  v1.0.0  Criacao inicial
 *   2026-04-11  v1.1.0  Fix: verificaAcessoControlador alinhada
 *                        com PERFIS_GLOBAIS
 *   2026-05-14  v1.1.1  Fix: chave de classe fechando cedo demais;
 *                        removida duplicacao de verificaAcessoControlador
 * =============================================================
 */

declare(strict_types=1);

class LeituraController
{
    /** Perfis com acesso global — espelho do ControladorController */
    private const PERFIS_GLOBAIS = ['master', 'master_operador', 'administrador'];

    // ----------------------------------------------------------
    // POST /api/leituras
    // Recebe telemetria do ESP32 (lote de leituras)
    // ----------------------------------------------------------
    public function store(): void
    {
        $controlador = authControlador();
        $pdo         = Database::getInstance();
        $body        = json_decode(file_get_contents('php://input'), true);

        if (empty($body['leituras']) || !is_array($body['leituras'])) {
            respondError(422, 'Payload invalido: leituras[] obrigatorio');
        }

        $tiposValidos = ['consumo_local', 'importacao', 'geracao', 'injecao'];
        $timestamp    = $body['timestamp_medicao'] ?? date('Y-m-d H:i:s');
        $inseridos    = 0;

        $stmt = $pdo->prepare("
            INSERT INTO leituras_energia (
                controlador_id,
                tipo_leitura,
                fase,
                potencia_kw,
                corrente_a,
                tensao_v,
                frequencia_hz,
                fator_potencia,
                energia_kwh,
                timestamp_medicao,
                criado_em
            ) VALUES (
                :controlador_id,
                :tipo_leitura,
                :fase,
                :potencia_kw,
                :corrente_a,
                :tensao_v,
                :frequencia_hz,
                :fator_potencia,
                :energia_kwh,
                :timestamp_medicao,
                NOW()
            )
        ");

        $pdo->beginTransaction();

        try {
            foreach ($body['leituras'] as $leitura) {
                if (!in_array($leitura['tipo'] ?? '', $tiposValidos)) {
                    continue;
                }

                $stmt->execute([
                    ':controlador_id'    => $controlador['id'],
                    ':tipo_leitura'      => $leitura['tipo'],
                    ':fase'              => $leitura['fase']           ?? 'total',
                    ':potencia_kw'       => $leitura['potencia_kw']    ?? null,
                    ':corrente_a'        => $leitura['corrente_a']     ?? null,
                    ':tensao_v'          => $leitura['tensao_v']       ?? null,
                    ':frequencia_hz'     => $leitura['frequencia_hz']  ?? null,
                    ':fator_potencia'    => $leitura['fator_potencia'] ?? null,
                    ':energia_kwh'       => $leitura['energia_kwh']    ?? null,
                    ':timestamp_medicao' => $timestamp,
                ]);

                $inseridos++;
            }

            $upd = $pdo->prepare("
                UPDATE controladores SET
                    online            = 1,
                    ultimo_contato    = NOW(),
                    last_telemetry_at = NOW(),
                    updated_at        = NOW()
                WHERE id = ?
            ");
            $upd->execute([$controlador['id']]);

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            respondError(500, 'Erro ao gravar leituras: ' . $e->getMessage());
        }

        respondCreated([
            'controlador_id' => $controlador['id'],
            'inseridos'      => $inseridos,
            'timestamp'      => $timestamp,
        ]);
    }

    // ----------------------------------------------------------
    // GET /api/leituras/agora?controlador_id=1
    // ----------------------------------------------------------
    public function agora(): void
    {
        $usuario       = authUsuario();
        $controladorId = (int)($_GET['controlador_id'] ?? 0);
        $pdo           = Database::getInstance();

        if (!$controladorId) {
            respondError(422, 'controlador_id obrigatorio');
        }

        $this->verificaAcessoControlador($controladorId, $usuario);

        $stmt = $pdo->prepare("
            SELECT
                l1.tipo_leitura,
                l1.potencia_kw,
                l1.corrente_a,
                l1.tensao_v,
                l1.frequencia_hz,
                l1.fator_potencia,
                l1.energia_kwh,
                l1.timestamp_medicao
            FROM leituras_energia l1
            INNER JOIN (
                SELECT tipo_leitura, MAX(timestamp_medicao) AS max_ts
                FROM leituras_energia
                WHERE controlador_id = :id
                GROUP BY tipo_leitura
            ) l2 ON l1.tipo_leitura       = l2.tipo_leitura
               AND l1.timestamp_medicao   = l2.max_ts
            WHERE l1.controlador_id = :id2
        ");
        $stmt->execute([':id' => $controladorId, ':id2' => $controladorId]);
        $leituras = $stmt->fetchAll();

        $resultado = [];
        foreach ($leituras as $l) {
            $resultado[$l['tipo_leitura']] = $l;
        }

        respondOk([
            'controlador_id' => $controladorId,
            'leituras'       => $resultado,
            'atualizado_em'  => date('c'),
        ]);
    }

    // ----------------------------------------------------------
    // GET /api/leituras/historico
    // ----------------------------------------------------------
    public function historico(): void
    {
        $usuario       = authUsuario();
        $pdo           = Database::getInstance();

        $controladorId = (int)($_GET['controlador_id'] ?? 0);
        $tipo          = $_GET['tipo']      ?? null;
        $de            = $_GET['de']        ?? date('Y-m-d 00:00:00');
        $ate           = $_GET['ate']       ?? date('Y-m-d 23:59:59');
        $resolucao     = $_GET['resolucao'] ?? 'minuto';

        if (!$controladorId) {
            respondError(422, 'controlador_id obrigatorio');
        }

        $this->verificaAcessoControlador($controladorId, $usuario);

        $groupFormat = match ($resolucao) {
            'hora'  => "DATE_FORMAT(timestamp_medicao, '%Y-%m-%d %H:00:00')",
            'dia'   => "DATE_FORMAT(timestamp_medicao, '%Y-%m-%d')",
            default => "DATE_FORMAT(timestamp_medicao, '%Y-%m-%d %H:%i:00')",
        };

        $params = [
            ':id'  => $controladorId,
            ':de'  => $de,
            ':ate' => $ate,
        ];

        $whereTipo = '';
        if ($tipo) {
            $whereTipo       = 'AND tipo_leitura = :tipo';
            $params[':tipo'] = $tipo;
        }

        $stmt = $pdo->prepare("
            SELECT
                {$groupFormat}      AS periodo,
                tipo_leitura,
                AVG(potencia_kw)    AS potencia_kw_avg,
                MAX(potencia_kw)    AS potencia_kw_max,
                AVG(tensao_v)       AS tensao_v_avg,
                AVG(corrente_a)     AS corrente_a_avg,
                AVG(fator_potencia) AS fator_potencia_avg,
                AVG(frequencia_hz)  AS frequencia_hz_avg,
                MAX(energia_kwh)    AS energia_kwh,
                COUNT(*)            AS amostras
            FROM leituras_energia
            WHERE controlador_id    = :id
              AND timestamp_medicao BETWEEN :de AND :ate
              {$whereTipo}
            GROUP BY periodo, tipo_leitura
            ORDER BY periodo ASC, tipo_leitura
        ");
        $stmt->execute($params);

        respondOk([
            'controlador_id' => $controladorId,
            'de'             => $de,
            'ate'            => $ate,
            'resolucao'      => $resolucao,
            'registros'      => $stmt->fetchAll(),
        ]);
    }

    // ----------------------------------------------------------
    // GET /api/leituras/resumo?controlador_id=1&periodo=hoje|mes
    // ----------------------------------------------------------
    public function resumo(): void
    {
        $usuario       = authUsuario();
        $pdo           = Database::getInstance();

        $controladorId = (int)($_GET['controlador_id'] ?? 0);
        $periodo       = $_GET['periodo'] ?? 'hoje';

        if (!$controladorId) {
            respondError(422, 'controlador_id obrigatorio');
        }

        $this->verificaAcessoControlador($controladorId, $usuario);

        [$de, $ate] = match ($periodo) {
            'mes'   => [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')],
            default => [date('Y-m-d 00:00:00'),  date('Y-m-d 23:59:59')],
        };

        $stmt = $pdo->prepare("
            SELECT
                tipo_leitura,
                MAX(energia_kwh) AS energia_kwh_total,
                AVG(potencia_kw) AS potencia_kw_media,
                MAX(potencia_kw) AS potencia_kw_pico,
                COUNT(*)         AS amostras
            FROM leituras_energia
            WHERE controlador_id    = :id
              AND timestamp_medicao BETWEEN :de AND :ate
            GROUP BY tipo_leitura
        ");
        $stmt->execute([':id' => $controladorId, ':de' => $de, ':ate' => $ate]);

        $resumo = [];
        foreach ($stmt->fetchAll() as $row) {
            $resumo[$row['tipo_leitura']] = $row;
        }

        respondOk([
            'controlador_id' => $controladorId,
            'periodo'        => $periodo,
            'de'             => $de,
            'ate'             => $ate,
            'resumo'         => $resumo,
        ]);
    }

    // ----------------------------------------------------------
    // Helper privado: verifica acesso do usuario ao controlador
    // ----------------------------------------------------------
    private function verificaAcessoControlador(int $controladorId, array $usuario): void
    {
        // Perfis globais: acesso irrestrito
        if (in_array($usuario['perfil'], self::PERFIS_GLOBAIS, true)) {
            return;
        }

        $pdo  = Database::getInstance();
        $stmt = $pdo->prepare("
            SELECT id FROM controladores
            WHERE id = ? AND empresa_id = ?
            LIMIT 1
        ");
        $stmt->execute([$controladorId, $usuario['empresa_id'] ?? 0]);

        if (!$stmt->fetch()) {
            respondError(403, 'Acesso negado a este controlador');
        }
    }
}
