<?php
/**
 * Arquivo: app/helpers/Tenant.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Helper de contexto multi-empresa (multi-tenant).
 *
 * Regra de negócio:
 *   - Usuário com papel_global preenchido (master, master_operador)
 *     vê dados de TODAS as empresas (sem filtro aplicado).
 *   - Usuário com papel_global = NULL e empresa_id preenchido
 *     vê APENAS dados da própria empresa.
 *   - Sessão inválida → filtro fail-safe "AND 1=0" (zero linhas).
 *
 * Histórico:
 *   2026-06-05  v1.2.1  Fix: listarControladores() corrigido para modelo
 *                       multi-tenant real (usuario->usuario_empresa->empresa
 *                       ->controladores). Removida ref a controlador_usuario
 *                       (tabela inexistente). Master faz bypass do filtro.
 *   2026-06-05  v1.2.0  Fix: listarControladores() ajustado ao schema real
 *                       da tabela controladores. Trocado c.nome -> c.apelido
 *                       (alias 'nome' mantido p/ compat) e c.deleted_at IS NULL
 *                       -> c.status <> 'inativo'. Tabela nao possui soft-delete.
 *   2026-06-05  v1.1.1  Fix: require_once de App\Config\Constantes
 *                       (projeto nao usa autoloader Composer, classes
 *                       namespaced precisam ser carregadas explicitamente).
 *   2026-06-04  v1.1.0  Adicionado listarControladores() com LEFT JOIN
 *                       agregado em telemetria_5min, UTC_TIMESTAMP() para
 *                       comparacao timezone-safe e cast defensivo do timeout
 *   2026-05-17  v1.0.0  Criação do helper Tenant
 */

declare(strict_types=1);

namespace App\Helpers;

// Carrega manualmente a classe Constantes (projeto sem autoloader PSR-4).
require_once __DIR__ . '/../config/Constantes.php';

use App\Config\Constantes;

final class Tenant
{
    /** Nome do parâmetro PDO injetado pelo filtro. */
    public const PARAM_EMPRESA = 'ctx_empresa_id';

    private function __construct() {}

    /**
     * Retorna o contexto do usuário logado a partir da sessão.
     */
    public static function contexto(): array
    {
        $autenticado = !empty($_SESSION['usuario_id'] ?? null);

        return [
            'id'           => (int)    ($_SESSION['usuario_id']     ?? 0),
            'nome'         => (string) ($_SESSION['usuario_nome']   ?? ''),
            'email'        => (string) ($_SESSION['usuario_email']  ?? ''),
            'perfil'       => (string) ($_SESSION['usuario_perfil'] ?? ''),
            'papel_global' => $_SESSION['papel_global'] ?? null,
            'empresa_id'   => isset($_SESSION['empresa_id']) && $_SESSION['empresa_id'] !== null
                ? (int) $_SESSION['empresa_id']
                : null,
            'autenticado'  => $autenticado,
        ];
    }

    /**
     * Diz se o usuário logado tem papel global (master/master_operador).
     */
    public static function ehGlobal(): bool
    {
        $papel = $_SESSION['papel_global'] ?? null;
        return $papel !== null && $papel !== '';
    }

    /**
     * Retorna o empresa_id do usuário logado, ou null se for global.
     */
    public static function empresaId(): ?int
    {
        if (self::ehGlobal()) {
            return null;
        }
        $id = $_SESSION['empresa_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    /**
     * Constrói fragmento SQL para filtrar por empresa.
     *
     * - Usuário global    → retorna '' (sem filtro)
     * - Usuário escopado  → retorna 'AND <alias>empresa_id = :ctx_empresa_id'
     * - Sessão inválida   → retorna 'AND 1=0' (fail-safe: zero linhas)
     */
    public static function filtroSQL(string $alias = ''): string
    {
        // 🛡️ Fail-safe: sem sessão válida, bloqueia tudo
        if (empty($_SESSION['usuario_id'] ?? null)) {
            error_log(
                '[Tenant][' . date('Y-m-d H:i:s') . '] ' .
                'filtroSQL() chamado sem sessão autenticada. ' .
                'Aplicando fail-safe AND 1=0. ' .
                'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '?') .
                ' | URI: ' . ($_SERVER['REQUEST_URI'] ?? '?')
            );
            return 'AND 1=0';
        }

        // 👑 Usuário global: sem filtro
        if (self::ehGlobal()) {
            return '';
        }

        // 👤 Usuário escopado: filtra pela empresa
        $empresaId = self::empresaId();

        if ($empresaId === null || $empresaId <= 0) {
            error_log(
                '[Tenant][' . date('Y-m-d H:i:s') . '] ' .
                'Usuário #' . ($_SESSION['usuario_id'] ?? '?') .
                ' sem papel_global e sem empresa_id válida. ' .
                'Aplicando fail-safe AND 1=0.'
            );
            return 'AND 1=0';
        }

        $prefixo = $alias !== '' ? $alias . '.' : '';
        return 'AND ' . $prefixo . 'empresa_id = :' . self::PARAM_EMPRESA;
    }

    /**
     * Injeta o parâmetro :ctx_empresa_id no array de bindings, se necessário.
     */
    public static function aplicarParam(array &$params): void
    {
        if (empty($_SESSION['usuario_id'] ?? null)) {
            return;
        }

        if (self::ehGlobal()) {
            return;
        }

        $empresaId = self::empresaId();
        if ($empresaId === null || $empresaId <= 0) {
            return;
        }

        $params[self::PARAM_EMPRESA] = $empresaId;
    }

    /**
     * Atalho: retorna o empresa_id a ser gravado em INSERTs.
     */
    public static function empresaIdParaGravar(?int $empresaIdInformada = null): int
    {
        if (self::ehGlobal()) {
            if ($empresaIdInformada === null || $empresaIdInformada <= 0) {
                throw new \RuntimeException(
                    'Usuário global precisa informar empresa_id ao gravar.'
                );
            }
            return $empresaIdInformada;
        }

        $empresaId = self::empresaId();
        if ($empresaId === null || $empresaId <= 0) {
            throw new \RuntimeException(
                'Contexto de empresa não definido para o usuário logado.'
            );
        }
        return $empresaId;
    }
    
        /**
     * Lista empresas que o usuario logado pode acessar.
     *
     * - Master / master_operador: todas as empresas ativas (nao deletadas)
     * - Usuario escopado: apenas a propria empresa
     * - Sem sessao: array vazio (fail-safe)
     *
     * Retorna campo 'nome' (alias de nome_fantasia) p/ compatibilidade
     * com codigo cliente que espera essa chave.
     *
     * @param  \PDO $pdo  Conexao ativa
     * @return array<int, array{id:int, nome:string, nome_fantasia:string, razao_social:?string}>
     */
    public static function listarEmpresas(\PDO $pdo): array
    {
        // 🛡️ Fail-safe
        if (empty($_SESSION['usuario_id'] ?? null)) {
            return [];
        }

        // 👑 Global: lista todas as ativas e nao deletadas
        if (self::ehGlobal()) {
            $stmt = $pdo->query("
                SELECT id,
                       nome_fantasia AS nome,
                       nome_fantasia,
                       razao_social
                  FROM empresa
                 WHERE ativo = 1
                   AND deleted_at IS NULL
                 ORDER BY nome_fantasia
            ");
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // 👤 Escopado: so a propria
        $empresaId = self::empresaId();
        if ($empresaId === null || $empresaId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT id,
                   nome_fantasia AS nome,
                   nome_fantasia,
                   razao_social
              FROM empresa
             WHERE id = :id
               AND ativo = 1
               AND deleted_at IS NULL
        ");
        $stmt->execute(['id' => $empresaId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Lista controladores acessiveis ao usuario logado.
     *
     * Modelo multi-tenant real:
     *   Usuario -> usuario_empresa -> empresa -> controladores.empresa_id
     *
     * Regras:
     * - master / master_operador: ve TODOS os controladores nao-inativos
     *   (bypass do filtro tenant).
     * - Demais perfis: ve apenas controladores de empresas onde o usuario
     *   possui vinculo ATIVO e nao deletado em usuario_empresa, e cuja
     *   empresa esteja ativa e nao deletada.
     * - Usuario nao encontrado ou inativo: array vazio (fail-safe).
     * - Sem sessao: array vazio (fail-safe).
     *
     * Schema notes:
     * - Tabela 'controladores' NAO possui coluna deleted_at; usa
     *   coluna 'status' (enum) para filtrar. Excluimos apenas 'inativo'
     *   p/ que o dashboard exiba tambem controladores em manutencao/erro.
     * - Campo de exibicao do controlador eh 'apelido' (varchar 100),
     *   exposto no retorno como 'nome' p/ compatibilidade com codigo cliente.
     *
     * @param  \PDO     $pdo         Conexao ativa
     * @param  int|null $empresaId   Reservado para uso futuro
     * @return array
     *
     * @versao 1.2.1
     * @modificado_em 2026-06-05
     */
    public static function listarControladores(\PDO $pdo, ?int $empresaId = null): array
    {
        // 🛡️ Fail-safe: sem sessão válida, retorna array vazio
        if (empty($_SESSION['usuario_id'] ?? null)) {
            return [];
        }

        $usuarioId = (int) $_SESSION['usuario_id'];

        // Passo 1: Buscar o perfil do usuario ativo e nao deletado
        $stmtPerfil = $pdo->prepare("
            SELECT perfil
              FROM usuarios
             WHERE id = :usuario_id
               AND ativo = 1
               AND deleted_at IS NULL
             LIMIT 1
        ");
        $stmtPerfil->bindValue(':usuario_id', $usuarioId, \PDO::PARAM_INT);
        $stmtPerfil->execute();
        $perfil = $stmtPerfil->fetchColumn();

        // Usuario nao encontrado, inativo ou deletado -> fail-safe
        if ($perfil === false) {
            return [];
        }

        // Passo 2: Decidir query com base no perfil
        $ehMaster = in_array($perfil, ['master', 'master_operador'], true);

        if ($ehMaster) {
            // 👑 QUERY_MASTER: ve todos os controladores nao-inativos
            $sql = "
                SELECT 
                    c.id,
                    c.codigo,
                    c.apelido AS nome,
                    c.tipo,
                    c.local_instalacao,
                    c.timezone,
                    c.empresa_id,
                    c.status,
                    c.online,
                    c.fw_version,
                    c.last_seen_at,
                    c.ultimo_contato,
                    c.modo_controle,
                    c.controle_exportacao_ativo,
                    c.controle_versao,
                    c.controle_atualizado_em,
                    c.controle_origem,
                    c.created_at
                FROM controladores c
                WHERE c.status <> 'inativo'
                ORDER BY c.apelido ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        } else {
            // 👤 QUERY_TENANT: filtra via usuario_empresa -> empresa -> controladores
            $sql = "
                SELECT 
                    c.id,
                    c.codigo,
                    c.apelido AS nome,
                    c.tipo,
                    c.local_instalacao,
                    c.timezone,
                    c.empresa_id,
                    c.status,
                    c.online,
                    c.fw_version,
                    c.last_seen_at,
                    c.ultimo_contato,
                    c.modo_controle,
                    c.controle_exportacao_ativo,
                    c.controle_versao,
                    c.controle_atualizado_em,
                    c.controle_origem,
                    c.created_at
                FROM controladores c
                INNER JOIN empresa e 
                    ON e.id = c.empresa_id
                    AND e.ativo = 1
                    AND e.deleted_at IS NULL
                INNER JOIN usuario_empresa ue 
                    ON ue.empresa_id = e.id
                    AND ue.ativo = 1
                    AND ue.deleted_at IS NULL
                WHERE ue.usuario_id = :usuario_id
                  AND c.status <> 'inativo'
                ORDER BY c.apelido ASC
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':usuario_id', $usuarioId, \PDO::PARAM_INT);
            $stmt->execute();
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
