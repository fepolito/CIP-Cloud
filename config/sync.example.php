<?php
/**
 * Arquivo: config/sync.example.php
 * Projeto: Controlador de Injecao de Potencia Eletrica
 * Objetivo: Template de configuracao de sincronizacao prod -> teste.
 *
 * COMO USAR:
 *   1. Copie este arquivo para config/sync.php
 *   2. Gere um token forte com: php -r "echo bin2hex(random_bytes(32));"
 *   3. Cole o token gerado no campo token
 *   4. Ajuste tabelas_permitidas conforme necessidade do ambiente
 *
 * ATENCAO:
 *   - config/sync.php contem credenciais sensiveis e esta no .gitignore
 *   - NUNCA commite o arquivo real no Git
 *
 * @versao 1.0.0
 * @criado_em 2026-06-05
 */
declare(strict_types=1);

return [
    // Token forte de 64 chars - gere com: bin2hex(random_bytes(32))
    'token' => 'SUBSTITUA_POR_TOKEN_DE_64_CHARS_GERADO_LOCALMENTE',

    // Tabelas autorizadas a exportar (whitelist de seguranca)
    'tabelas_permitidas' => [
        'telemetria_5min',
        'controladores',
        'usuarios',
    ],

    // Limite de registros por request (paginacao)
    'limite_por_request' => 5000,

    // IPs autorizados (opcional - vazio = permite qualquer IP com token valido)
    'ips_permitidos' => [
        // '189.xxx.xxx.xxx',
    ],

    // Colunas a anonimizar/remover por tabela (LGPD + seguranca)
    'colunas_anonimizar' => [
        'usuarios' => [
            // senha real vira hash fixo (senha de teste padrao)
            'senha' => 'GERAR_HASH_BCRYPT_DE_SENHA_TESTE_PADRAO',
            'email' => '__ANONIMIZAR_EMAIL__',
        ],
        'controladores' => [
            // adicione colunas de token/chave aqui se existirem
        ],
    ],
];
