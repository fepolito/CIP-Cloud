<?php
/**
 *
 * tools/sync_config.php
 * Configuracao do sincronizador local (Laragon DEV)
 *
 * NAO commitar este arquivo se o token for sensivel.
 * Recomendado: adicionar tools/sync_config.php ao .gitignore
 * e versionar apenas sync_config.exemplo.php
 *
 * @versao 1.0.0
 * @autor  CIP Cloud Copilot + Fernando
 * @criado_em 2026-06-01
 */
declare(strict_types=1);

return [
    // ── Origem (PROD) ────────────────────────────────────────────
    'url_base'    => 'https://monitor.aeonium.com.br/api/sync/exportar.php',
    'token'       => '9a1da2d76d8a9591679b01a72bd0c760dd810c60594f9160f0520b2a033a0fb8',
    'timeout_seg' => 60,

    // ── Tabelas e estrategia de upsert ───────────────────────────
    // ordem: pais antes de filhos (FK)
    'tabelas' => [

        'controladores' => [
            'cursor_inicial'  => ['desde_ts' => '1970-01-01T00:00:00Z'],
            'param_cursor'    => 'desde_ts',
            'estrategia'      => 'upsert',        // ON DUPLICATE KEY UPDATE
            'pk'              => 'id',
            'colunas_update'  => null,            // null = todas exceto PK
        ],

        'usuarios' => [
            'cursor_inicial'  => ['desde_ts' => '1970-01-01T00:00:00Z'],
            'param_cursor'    => 'desde_ts',
            'estrategia'      => 'upsert',
            'pk'              => 'id',
            'colunas_update'  => null,
        ],

        'telemetria_5min' => [
            'cursor_inicial'  => ['desde_id' => 0],
            'param_cursor'    => 'desde_id',
            'estrategia'      => 'insert_ignore', // append-only
            'pk'              => 'id',
            'colunas_update'  => null,            // ignorado em insert_ignore
        ],
    ],

    // ── Logs ─────────────────────────────────────────────────────
    'log_dir'     => __DIR__ . '/logs',
    'log_console' => true,
];
