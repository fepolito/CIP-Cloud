<?php
/**
 * @arquivo       config/solis.php
 * @versao        1.0.0
 * @modificado_em 2026-08-07
 * @objetivo      Credenciais e parametros da SolisCloud API (protegido por .htaccess).
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

return [
    'base_url'    => 'https://www.soliscloud.com:13333',
    'key_id'      => getenv('SOLIS_KEY_ID')   ?: '1300386381676733177',
    'key_secret'  => getenv('SOLIS_KEY_SECRET') ?: '90da90f89959487d8e948eeded4c7a89',
    'empresa_id'  => 1,
    'bucket_lag_min' => 7,      // defasagem do cron (prioridade ESP)
    'page_size'   => 20,
    'cron_token'  => getenv('SOLIS_CRON_TOKEN') ?: 'aeonium123',
];
