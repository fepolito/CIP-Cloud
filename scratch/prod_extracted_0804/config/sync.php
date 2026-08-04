<?php
/**
 * Configuracao de sincronizacao prod -> teste
 * IMPORTANTE: este arquivo NAO deve ser commitado no Git
 * @versao 1.0.0
 */
declare(strict_types=1);

return [
    // Token forte - gere com: bin2hex(random_bytes(32))
    'token' => 'COLE_AQUI_O_TOKEN_GERADO_DE_64_CHARS',

    // Tabelas autorizadas a exportar (whitelist)
    'tabelas_permitidas' => [
        'telemetria_5min',
        'controladores',
        'usuarios',
    ],

    // Limite de registros por request (paginacao)
    'limite_por_request' => 5000,

    // IPs autorizados (opcional - deixe vazio pra permitir qualquer um com token valido)
    // Util se voce tem IP fixo em casa/escritorio
    'ips_permitidos' => [
        // '189.xxx.xxx.xxx',
    ],

    // Colunas a ANONIMIZAR/REMOVER por tabela (seguranca)
    'colunas_anonimizar' => [
        'usuarios' => [
            // senha real vira hash fixo de 'senha123' para testes
            'senha' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'email' => '__ANONIMIZAR_EMAIL__', // sera substituido por user{id}@teste.local
        ],
        'controladores' => [
            // se houver coluna de chave/token, remove
            // 'chave_mqtt' => null,
            // 'token_acesso' => null,
        ],
    ],
];
