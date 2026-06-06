<?php
echo "📂 Diretório atual: " . __DIR__ . "<br>";
echo "<hr>";

// Testa vários paths possíveis
$paths = [
    __DIR__ . '/app/helpers/Tenant.php',
    __DIR__ . '/app/Tenant.php',
    __DIR__ . '/helpers/Tenant.php',
    __DIR__ . '/api/helpers/Tenant.php',
    __DIR__ . '/app/helpers/tenant.php',  // 👈 minúsculo (Linux é case-sensitive!)
];

foreach ($paths as $p) {
    $existe = file_exists($p) ? '✅ EXISTE' : '❌ não existe';
    echo "$existe → $p<br>";
}
