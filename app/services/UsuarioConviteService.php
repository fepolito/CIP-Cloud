<?php
/**
 * ============================================================================
 * Projeto    : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo    : app/services/UsuarioConviteService.php
 * Objetivo   : Centralizar a geracao e preparacao dos dados de pre-cadastro
 *              por convite do modulo administrativo de usuarios
 *
 * Dependencias de hardware:
 *   - Servidor com suporte a PHP
 *   - Infraestrutura operacional do projeto CIP
 *   - Banco de dados da aplicacao na fase de integracao
 *
 * Dependencias de software:
 *   - PHP 7.4+
 *   - app/services/UsuarioPermissaoService.php
 *
 * Regras funcionais implementadas:
 *   - cadastro inicial em modo pendente
 *   - geracao de token de convite
 *   - definicao de validade do convite
 *   - preparo dos campos minimos recomendados no documento funcional
 *
 * Historico de implementacoes:
 *   - 2026-04-30 | v1.0 | Criacao inicial do servico de convite
 * ============================================================================
 */

declare(strict_types=1);

final class UsuarioConviteService
{
    /**
     * Gera um token aleatorio seguro para convite.
     */
    public static function gerarTokenConvite(int $bytes = 32): string
    {
        if ($bytes < 16) {
            $bytes = 16;
        }

        return bin2hex(random_bytes($bytes));
    }

    /**
     * Calcula a data de expiracao do convite.
     *
     * Padrao recomendado:
     *   - 7 dias a partir da criacao
     */
    public static function gerarDataExpiracao(int $dias = 7): string
    {
        if ($dias <= 0) {
            $dias = 7;
        }

        $date = new DateTimeImmutable('now');
        return $date->modify('+' . $dias . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * Monta os dados minimos do pre-cadastro pendente.
     *
     * Campos recomendados no documento funcional:
     * - empresa_id
     * - email
     * - perfil
     * - ativo
     * - status_cadastro
     * - token_convite
     * - convite_expira_em
     * - criado_por
     * - created_at
     * - updated_at
     */
    public static function montarPreCadastro(
        int $empresaId,
        string $email,
        string $perfil,
        int $criadoPor,
        ?int $ativo = 1,
        int $diasExpiracao = 7
    ): array {
        $agora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        return [
            'empresa_id' => $empresaId,
            'email' => self::normalizarEmail($email),
            'perfil' => trim($perfil),
            'ativo' => ($ativo === 1 ? 1 : 0),
            'status_cadastro' => 'pendente',
            'token_convite' => self::gerarTokenConvite(),
            'convite_expira_em' => self::gerarDataExpiracao($diasExpiracao),
            'criado_por' => $criadoPor,
            'created_at' => $agora,
            'updated_at' => $agora,
        ];
    }

    /**
     * Normaliza o e-mail para persistencia.
     */
    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Valida e-mail em formato basico.
     */
    public static function emailValido(string $email): bool
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }
}
