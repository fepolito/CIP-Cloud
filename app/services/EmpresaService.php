<?php
/**
 * ============================================================
 * Arquivo   : app/services/EmpresaService.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-09-14
 * Objetivo  : Resolver escopo hierárquico de empresas por perfil e calcular
 *             rollup de geração (dia/mês) somando a subárvore via CTE recursiva.
 *
 * Dependências de hardware:
 *   - Servidor com MySQL 8.0+ / MariaDB 10.2+
 *
 * Dependências de software/arquivos:
 *   - PHP 8.3+ (PSR-12, strict_types)
 *   - PDO MySQL
 *   - Tabela `empresa` (com coluna `empresa_pai_id`)
 *   - Tabela `controladores` (com `empresa_id` e `timezone`)
 *   - Tabela `telemetria_5min` (com `energia_geracao_kwh`)
 *
 * Histórico de implementações:
 *   2026-09-14  v1.0.0  Criação inicial da classe EmpresaService
 *                        com escopo multinível e rollup de geração (CIP-DEC-20260914-003)
 * ============================================================
 */

declare(strict_types=1);

namespace App\Services;

require_once __DIR__ . '/Database.php';

use PDO;

final class EmpresaService
{
    public function __construct(private PDO $pdo) {}

    /**
     * IDs de empresa que o usuário pode enxergar.
     * master/master_operador => todas (retorna null). Demais => própria + subárvore.
     *
     * @param string   $perfil
     * @param int|null $empresaUsuarioId
     * @return array<int>|null  null = sem filtro (todas)
     */
    public function escopoEmpresas(string $perfil, ?int $empresaUsuarioId): ?array
    {
        if (in_array($perfil, ['master', 'master_operador'], true)) {
            return null; // null = sem restrição de escopo (vê todas)
        }

        if ($empresaUsuarioId === null || $empresaUsuarioId <= 0) {
            return [];
        }

        return self::subarvoreIds($empresaUsuarioId, $this->pdo);
    }

    /**
     * Retorna o ID da raiz mais todos os IDs descendentes da subárvore (rollup).
     * Utiliza CTE recursiva compatível com MySQL 8.0+ e MariaDB 10.2+.
     *
     * @param int      $raizId
     * @param PDO|null $pdo     Instância PDO opcional (se nulo, obtém de Database::getConnection())
     * @return array<int>
     */
    public static function subarvoreIds(int $raizId, ?PDO $pdo = null): array
    {
        $db = $pdo ?? Database::getConnection();
        $sql = '
            WITH RECURSIVE arvore AS (
                SELECT id FROM empresa WHERE id = :raiz AND deleted_at IS NULL
                UNION ALL
                SELECT e.id
                FROM empresa e
                INNER JOIN arvore a ON e.empresa_pai_id = a.id
                WHERE e.deleted_at IS NULL
            )
            SELECT id FROM arvore
        ';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':raiz', $raizId, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Lista empresas visíveis conforme escopo de perfil com rollup de geração e contadores.
     *
     * @param string   $perfil
     * @param int|null $empresaUsuarioId
     * @param string   $periodo 'dia' | 'mes'
     * @return array<int, array<string, mixed>>
     */
    public function listarComGeracao(
        string $perfil,
        ?int $empresaUsuarioId,
        string $periodo = 'mes'
    ): array {
        $escopo = $this->escopoEmpresas($perfil, $empresaUsuarioId);

        $whereEscopo = '';
        $params = [];
        if ($escopo !== null) {
            if ($escopo === []) {
                return [];
            }
            $in = implode(',', array_fill(0, count($escopo), '?'));
            $whereEscopo = " AND e.id IN ($in)";
            $params = $escopo;
        }

        $sqlEmpresas = "
            SELECT e.id, e.nome_fantasia, e.tipo, e.empresa_pai_id, e.ativo
            FROM empresa e
            WHERE e.deleted_at IS NULL $whereEscopo
            ORDER BY e.empresa_pai_id IS NOT NULL, e.nome_fantasia
        ";
        $stmt = $this->pdo->prepare($sqlEmpresas);
        $stmt->execute($params);
        $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($empresas as &$emp) {
            $ids = $this->subarvoreIds((int) $emp['id']);
            $emp['geracao_kwh']   = $this->geracaoRollup($ids, $periodo);
            $emp['num_filhas']    = $this->contarFilhas((int) $emp['id']);
            $emp['num_controlad'] = $this->contarControladores($ids);
        }
        unset($emp);

        return $empresas;
    }

    /**
     * Geração agregada (MAX - MIN por controlador por dia, timezone-aware)
     * para um conjunto de empresas e toda a sua subárvore.
     *
     * NOTA CANÔNICA (telemetria_5min):
     * A coluna acumuladora de geração é `energia_geracao_kwh`, que é zerada à meia-noite
     * no fuso horário do controlador. O cálculo agrega o delta diário (MAX - MIN) por dia
     * e soma os dias do período (mês ou dia corrente).
     *
     * @param array<int> $empresaIds
     * @param string     $periodo 'dia' | 'mes'
     * @return float
     */
    public function geracaoRollup(array $empresaIds, string $periodo): float
    {
        if ($empresaIds === []) {
            return 0.0;
        }

        $in = implode(',', array_fill(0, count($empresaIds), '?'));

        // Janela no fuso horário do próprio controlador
        $condData = $periodo === 'dia'
            ? "DATE(CONVERT_TZ(t.timestamp_utc, 'UTC', c.timezone)) = DATE(CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', c.timezone))"
            : "DATE_FORMAT(CONVERT_TZ(t.timestamp_utc, 'UTC', c.timezone), '%Y-%m') = DATE_FORMAT(CONVERT_TZ(UTC_TIMESTAMP(), 'UTC', c.timezone), '%Y-%m')";

        $sql = "
            SELECT COALESCE(SUM(d.kwh), 0) AS total
            FROM (
                SELECT MAX(t.energia_geracao_kwh) - MIN(t.energia_geracao_kwh) AS kwh
                FROM telemetria_5min t
                INNER JOIN controladores c ON c.id = t.controlador_id
                WHERE c.empresa_id IN ($in)
                  AND $condData
                GROUP BY t.controlador_id, DATE(CONVERT_TZ(t.timestamp_utc, 'UTC', c.timezone))
            ) d
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($empresaIds);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Conta empresas filhas imediatas de um nó pai.
     */
    private function contarFilhas(int $paiId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM empresa WHERE empresa_pai_id = :pai AND deleted_at IS NULL'
        );
        $stmt->bindValue(':pai', $paiId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Conta o total de controladores ativos atribuídos a um conjunto de empresas.
     *
     * @param array<int> $empresaIds
     */
    private function contarControladores(array $empresaIds): int
    {
        if ($empresaIds === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($empresaIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM controladores WHERE empresa_id IN ($in)"
        );
        $stmt->execute($empresaIds);
        return (int) $stmt->fetchColumn();
    }
}
