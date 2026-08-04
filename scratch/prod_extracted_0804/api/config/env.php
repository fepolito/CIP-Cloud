<?php
// ============================================================
// Projeto  : CIP - Controlador de Injeção de Potência Elétrica
// Arquivo  : api/config/env.example.php
// Objetivo : Exemplo seguro do env.php (valores fake). Renomear
//            para env.php em produção e preencher os dados reais.
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'aeoniu71_monitor');
define('DB_USER', 'aeoniu71_monitor');
define('DB_PASS', '388144P@ol@');
define('DB_CHARSET', 'utf8mb4');

// ── Tarifa energia (R$/kWh) — tarifa cheia
define('TARIFA_KWH', 0.94823199);

// Fator de injeção (Lei 14.300): crédito = TARIFA_KWH × FATOR_INJECAO
define('FATOR_INJECAO', 0.76);
