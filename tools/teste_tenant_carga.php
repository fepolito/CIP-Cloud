<?php
header('Content-Type: text/plain; charset=utf-8');

$caminho = __DIR__ . '/app/helpers/Tenant.php';

echo "=== PRIMEIRAS 30 LINHAS DO ARQUIVO ===\n\n";
$linhas = file($caminho);
foreach (array_slice($linhas, 0, 30) as $i => $linha) {
    printf("%3d | %s", $i + 1, $linha);
}

echo "\n\n=== CLASSES DECLARADAS APOS REQUIRE ===\n";
$antes = get_declared_classes();
require_once $caminho;
$depois = get_declared_classes();
$novas = array_diff($depois, $antes);

if (empty($novas)) {
    echo "❌ NENHUMA classe foi declarada!\n";
} else {
    echo "✅ Classes novas:\n";
    foreach ($novas as $c) {
        echo "  - $c\n";
    }
}

echo "\n=== VERIFICACOES ===\n";
echo "class_exists('Tenant'):              " . (class_exists('Tenant') ? '✅' : '❌') . "\n";
echo "class_exists('App\\Helpers\\Tenant'): " . (class_exists('App\\Helpers\\Tenant') ? '✅' : '❌') . "\n";
