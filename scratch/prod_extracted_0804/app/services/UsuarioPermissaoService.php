<?php
/**
 * ============================================================================
 * Arquivo: app/services/UsuarioPermissaoService.php
 * Projeto: CIP - Controlador de Injeção de Potência Elétrica
 * Módulo: Gestão Administrativa de Usuários
 * Objetivo:
 *   Centralizar as regras de permissão e segregação do módulo de usuários,
 *   conforme documento funcional oficial aprovado.
 *
 * Dependências de hardware:
 *   - Servidor web com suporte a PHP
 *   - Infraestrutura operacional do projeto CIP
 *   - Banco de dados da aplicação (na fase de integração)
 *
 * Dependências de software / arquivos:
 *   - public_html/usuarios.php
 *   - app/auth.php
 *   - estrutura de sessão/autenticação do projeto
 *
 * Regras funcionais implementadas:
 *   - Apenas master, master_operador e operador acessam usuarios.php
 *   - viewer não acessa o módulo administrativo
 *   - master e master_operador possuem visão global por empresa
 *   - operador atua somente na própria empresa
 *   - operador cria apenas viewer
 *   - operador edita apenas viewer da própria empresa e o próprio cadastro
 *   - interface comum não cria/edita master e master_operador
 *
 * Histórico de implementações:
 *   - v1.0.0 - 30/04/2026
 *     - Criação inicial do serviço de permissões do módulo de usuários
 *     - Implementação das regras de acesso por perfil
 *     - Implementação das regras de segregação por empresa
 *     - Implementação das regras de criação e edição por perfil
 * ============================================================================
 */

declare(strict_types=1);

final class UsuarioPermissaoService
{
    public const PERFIL_MASTER = 'master';
    public const PERFIL_MASTER_OPERADOR = 'master_operador';
    public const PERFIL_ADMINISTRADOR = 'administrador';
    public const PERFIL_OPERADOR = 'operador';
    public const PERFIL_VIEWER = 'viewer';

    /**
     * Perfis que podem acessar o módulo usuarios.php.
     */
    public static function podeAcessarModulo(string $perfil): bool
    {
        return in_array($perfil, [
            self::PERFIL_MASTER,
            self::PERFIL_MASTER_OPERADOR,
            self::PERFIL_OPERADOR,
        ], true);
    }

    /**
     * Perfis com visão global de empresas.
     */
    public static function temVisaoGlobal(string $perfil): bool
    {
        return in_array($perfil, [
            self::PERFIL_MASTER,
            self::PERFIL_MASTER_OPERADOR,
        ], true);
    }

    /**
     * Regra de resolução da empresa-alvo.
     *
     * master/master_operador:
     *   - podem selecionar empresa por parâmetro
     *
     * operador:
     *   - sempre usa a própria empresa
     */
    public static function resolverEmpresaAlvo(
        string $perfil,
        ?int $empresaIdUsuarioAutenticado,
        ?int $empresaIdSolicitada
    ): ?int {
        if (self::temVisaoGlobal($perfil)) {
            return $empresaIdSolicitada;
        }

        if ($perfil === self::PERFIL_OPERADOR) {
            return $empresaIdUsuarioAutenticado;
        }

        return null;
    }

    /**
     * Perfis que podem ser criados pela interface comum,
     * de acordo com o perfil do usuário autenticado.
     */
    public static function perfisQuePodeCriar(string $perfilAutenticado): array
    {
        if (in_array($perfilAutenticado, [
            self::PERFIL_MASTER,
            self::PERFIL_MASTER_OPERADOR,
        ], true)) {
            return [
                self::PERFIL_ADMINISTRADOR,
                self::PERFIL_OPERADOR,
                self::PERFIL_VIEWER,
            ];
        }

        if ($perfilAutenticado === self::PERFIL_OPERADOR) {
            return [
                self::PERFIL_VIEWER,
            ];
        }

        return [];
    }

    /**
     * Verifica se o usuário autenticado pode criar o perfil informado.
     */
    public static function podeCriarPerfil(
        string $perfilAutenticado,
        string $perfilNovoUsuario
    ): bool {
        return in_array(
            $perfilNovoUsuario,
            self::perfisQuePodeCriar($perfilAutenticado),
            true
        );
    }

    /**
     * Verifica se pode editar um usuário-alvo.
     *
     * Regras:
     * - master/master_operador:
     *   podem editar administrador, operador e viewer
     *   não devem editar master/master_operador pela interface comum
     *
     * - operador:
     *   pode editar viewer da própria empresa
     *   pode editar o próprio cadastro
     */
    public static function podeEditarUsuario(
        array $usuarioAutenticado,
        array $usuarioAlvo
    ): bool {
        $perfilAutenticado = (string) ($usuarioAutenticado['perfil'] ?? '');
        $idAutenticado = (int) ($usuarioAutenticado['id'] ?? 0);
        $empresaAutenticado = (int) ($usuarioAutenticado['empresa_id'] ?? 0);

        $idAlvo = (int) ($usuarioAlvo['id'] ?? 0);
        $perfilAlvo = (string) ($usuarioAlvo['perfil'] ?? '');
        $empresaAlvo = (int) ($usuarioAlvo['empresa_id'] ?? 0);

        if (in_array($perfilAutenticado, [
            self::PERFIL_MASTER,
            self::PERFIL_MASTER_OPERADOR,
        ], true)) {
            return in_array($perfilAlvo, [
                self::PERFIL_ADMINISTRADOR,
                self::PERFIL_OPERADOR,
                self::PERFIL_VIEWER,
            ], true);
        }

        if ($perfilAutenticado === self::PERFIL_OPERADOR) {
            $edicaoProprioCadastro = $idAutenticado > 0 && $idAutenticado === $idAlvo;
            if ($edicaoProprioCadastro) {
                return true;
            }

            $viewerMesmaEmpresa =
                $perfilAlvo === self::PERFIL_VIEWER &&
                $empresaAutenticado > 0 &&
                $empresaAutenticado === $empresaAlvo;

            return $viewerMesmaEmpresa;
        }

        return false;
    }

    /**
     * Verifica se o usuário autenticado pode atuar sobre a empresa alvo.
     */
    public static function podeAtuarNaEmpresa(
        string $perfilAutenticado,
        ?int $empresaIdUsuarioAutenticado,
        ?int $empresaIdAlvo
    ): bool {
        if (self::temVisaoGlobal($perfilAutenticado)) {
            return $empresaIdAlvo !== null && $empresaIdAlvo > 0;
        }

        if ($perfilAutenticado === self::PERFIL_OPERADOR) {
            return
                $empresaIdUsuarioAutenticado !== null &&
                $empresaIdAlvo !== null &&
                $empresaIdUsuarioAutenticado === $empresaIdAlvo;
        }

        return false;
    }

    /**
     * Verifica se o perfil pode alterar status do usuário-alvo.
     * Nesta fase, segue a mesma regra de edição.
     */
    public static function podeAlterarStatus(
        array $usuarioAutenticado,
        array $usuarioAlvo
    ): bool {
        return self::podeEditarUsuario($usuarioAutenticado, $usuarioAlvo);
    }

    /**
     * Verifica se o perfil pode reenviar convite.
     * Nesta fase, segue a mesma regra de edição.
     */
    public static function podeReenviarConvite(
        array $usuarioAutenticado,
        array $usuarioAlvo
    ): bool {
        return self::podeEditarUsuario($usuarioAutenticado, $usuarioAlvo);
    }
}
