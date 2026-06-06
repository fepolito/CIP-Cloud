<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== TESTE DE CAMINHO DO TENANT ===\n\n";

$caminho = __DIR__ . '/../../app/helpers/Tenant.php';
echo "Caminho calculado:\n  $caminho\n\n";

echo "Caminho real (realpath):\n  " . (realpath($caminho) ?: '❌ NAO RESOLVIDO') . "\n\n";

echo "Arquivo existe? " . (file_exists($caminho) ? '✅ SIM' : '❌ NAO') . "\n";
echo "É legível?      " . (is_readable($caminho) ? '✅ SIM' : '❌ NAO') . "\n\n";

echo "=== LISTAGEM DA PASTA app/helpers/ ===\n";
$pasta = __DIR__ . '/../../app/helpers/';
if (is_dir($pasta)) {
    foreach (scandir($pasta) as $f) {
        if ($f !== '.' && $f !== '..') {
            echo "  - $f\n";
        }
    }
} else {
    echo "❌ Pasta nao existe: $pasta\n";
}

echo "\n=== TENTANDO CARREGAR ===\n";
if (file_exists($caminho)) {
    require_once $caminho;
    echo "Require OK. Classe Tenant existe? " . (class_exists('Tenant') ? '✅ SIM' : '❌ NAO (problema no arquivo!)') . "\n";
} else {
    echo "Nem tentei, arquivo nao existe.\n";
}
