<?php
/**
 * Projeto: Controlador de Injeção de Potência Elétrica (CIP)
 * Arquivo: includes/usuarios_rules.php
 * Objetivo: Centralizar regras de acesso e autorização do módulo administrativo de usuários.
 *
 * Dependências de hardware:
 * - Servidor com suporte a PHP
 * - Banco de dados MySQL/MariaDB
 *
 * Dependências de software/arquivos:
 * - Estrutura de autenticação do projeto
 * - Sessão PHP ativa
 *
 * Regras principais:
 * - master e master_operador possuem visão global
 * - operador atua apenas na própria empresa
 * - viewer não acessa o módulo usuarios.php
 *
 * Histórico:
 * - 2026-04-29: Criação inicial da base de permissões do módulo de usuários.
 */

if (!function_exists('cip_normalizar_perfil')) {
    /**
     * Normaliza o perfil recebido para comparação segura.
     */
    function cip_normalizar_perfil(?string $perfil): string
    {
        return strtolower(trim((string) $perfil));
    }
}

if (!function_exists('cip_pode_acessar_modulo_usuarios')) {
    /**
     * Verifica se um perfil pode acessar o módulo administrativo de usuários.
     */
    function cip_pode_acessar_modulo_usuarios(?string $perfil): bool
    {
        $perfil = cip_normalizar_perfil($perfil);

        return in_array($perfil, ['master', 'master_operador', 'operador'], true);
    }
}

if (!function_exists('cip_pode_listar_empresas_usuarios')) {
    /**
     * Verifica se o perfil pode visualizar a lista global de empresas.
     */
    function cip_pode_listar_empresas_usuarios(?string $perfil): bool
    {
        $perfil = cip_normalizar_perfil($perfil);

        return in_array($perfil, ['master', 'master_operador'], true);
    }
}

if (!function_exists('cip_perfis_criaveis_por')) {
    /**
     * Retorna os perfis que o usuário logado pode criar no módulo.
     */
    function cip_perfis_criaveis_por(?string $perfil): array
    {
        $perfil = cip_normalizar_perfil($perfil);

        switch ($perfil) {
            case 'master':
            case 'master_operador':
                return ['administrador', 'operador', 'viewer'];

            case 'operador':
                return ['viewer'];

            default:
                return [];
        }
    }
}

if (!function_exists('cip_perfis_editaveis_por')) {
    /**
     * Retorna os perfis que o usuário logado pode editar de forma geral.
     * Observação:
     * - operador edita viewer e o próprio usuário (essa exceção é tratada em outra função)
     * - master/master_operador não editam master/master_operador pela UI comum
     */
    function cip_perfis_editaveis_por(?string $perfil): array
    {
        $perfil = cip_normalizar_perfil($perfil);

        switch ($perfil) {
            case 'master':
            case 'master_operador':
                return ['administrador', 'operador', 'viewer'];

            case 'operador':
                return ['viewer'];

            default:
                return [];
        }
    }
}

if (!function_exists('cip_usuario_eh_global')) {
    /**
     * Define se o perfil possui visão global entre empresas.
     */
    function cip_usuario_eh_global(?string $perfil): bool
    {
        $perfil = cip_normalizar_perfil($perfil);

        return in_array($perfil, ['master', 'master_operador'], true);
    }
}

if (!function_exists('cip_resolver_empresa_permitida')) {
    /**
     * Resolve a empresa permitida para a operação atual.
     *
     * Regras:
     * - master/master_operador: pode usar a empresa solicitada
     * - operador: sempre usa a empresa vinculada ao usuário logado
     *
     * @param array $usuarioLogado Exemplo:
     * [
     *   'id' => 1,
     *   'perfil' => 'operador',
     *   'empresa_id' => 10
     * ]
     * @param mixed $empresaIdSolicitada Valor vindo da URL, POST, JSON etc.
     */
    function cip_resolver_empresa_permitida(array $usuarioLogado, $empresaIdSolicitada = null): ?int
    {
        $perfil = cip_normalizar_perfil($usuarioLogado['perfil'] ?? null);
        $empresaLogado = isset($usuarioLogado['empresa_id']) ? (int) $usuarioLogado['empresa_id'] : null;

        if (cip_usuario_eh_global($perfil)) {
            if ($empresaIdSolicitada === null || $empresaIdSolicitada === '') {
                return null;
            }

            return (int) $empresaIdSolicitada;
        }

        if ($perfil === 'operador') {
            return $empresaLogado;
        }

        return null;
    }
}

if (!function_exists('cip_usuario_pode_atuar_na_empresa')) {
    /**
     * Verifica se o usuário logado pode atuar na empresa informada.
     */
    function cip_usuario_pode_atuar_na_empresa(array $usuarioLogado, ?int $empresaId): bool
    {
        $perfil = cip_normalizar_perfil($usuarioLogado['perfil'] ?? null);
        $empresaLogado = isset($usuarioLogado['empresa_id']) ? (int) $usuarioLogado['empresa_id'] : null;

        if (cip_usuario_eh_global($perfil)) {
            return $empresaId !== null && $empresaId > 0;
        }

        if ($perfil === 'operador') {
            return $empresaLogado !== null && $empresaId === $empresaLogado;
        }

        return false;
    }
}

if (!function_exists('cip_pode_editar_usuario')) {
    /**
     * Verifica se o usuário logado pode editar o usuário-alvo.
     *
     * Regras:
     * - master/master_operador: podem editar administrador, operador, viewer
     * - operador: pode editar viewer da própria empresa
     * - operador: pode editar a si mesmo
     */
    function cip_pode_editar_usuario(array $usuarioLogado, array $usuarioAlvo): bool
    {
        $perfilLogado = cip_normalizar_perfil($usuarioLogado['perfil'] ?? null);
        $perfilAlvo = cip_normalizar_perfil($usuarioAlvo['perfil'] ?? null);

        $idLogado = isset($usuarioLogado['id']) ? (int) $usuarioLogado['id'] : 0;
        $idAlvo = isset($usuarioAlvo['id']) ? (int) $usuarioAlvo['id'] : 0;

        $empresaLogado = isset($usuarioLogado['empresa_id']) ? (int) $usuarioLogado['empresa_id'] : null;
        $empresaAlvo = isset($usuarioAlvo['empresa_id']) ? (int) $usuarioAlvo['empresa_id'] : null;

        if ($idLogado > 0 && $idAlvo > 0 && $idLogado === $idAlvo) {
            return cip_pode_acessar_modulo_usuarios($perfilLogado);
        }

        if (cip_usuario_eh_global($perfilLogado)) {
            return in_array($perfilAlvo, cip_perfis_editaveis_por($perfilLogado), true);
        }

        if ($perfilLogado === 'operador') {
            if ($empresaLogado === null || $empresaAlvo === null || $empresaLogado !== $empresaAlvo) {
                return false;
            }

            return $perfilAlvo === 'viewer';
        }

        return false;
    }
}

if (!function_exists('cip_pode_criar_perfil')) {
    /**
     * Verifica se o usuário logado pode criar um determinado perfil.
     */
    function cip_pode_criar_perfil(array $usuarioLogado, ?string $perfilNovo): bool
    {
        $perfilLogado = cip_normalizar_perfil($usuarioLogado['perfil'] ?? null);
        $perfilNovo = cip_normalizar_perfil($perfilNovo);

        return in_array($perfilNovo, cip_perfis_criaveis_por($perfilLogado), true);
    }
}

if (!function_exists('cip_obter_motivo_bloqueio_modulo_usuarios')) {
    /**
     * Retorna uma mensagem padrão para bloqueio de acesso.
     */
    function cip_obter_motivo_bloqueio_modulo_usuarios(?string $perfil): string
    {
        $perfil = cip_normalizar_perfil($perfil);

        if ($perfil === 'viewer') {
            return 'Seu perfil não possui acesso ao módulo administrativo de usuários.';
        }

        return 'Você não possui permissão para acessar este módulo.';
    }
}
