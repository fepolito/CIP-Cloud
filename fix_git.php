<?php
echo "<h3>Sincronizando Git com o GitHub...</h3><pre>";

// Descarta alterações locais (como a edição manual que fizemos)
echo shell_exec("git reset --hard HEAD 2>&1") . "\n";

// Puxa as novidades do GitHub
echo shell_exec("git pull origin main 2>&1") . "\n";

echo "</pre>";
echo "<p>✅ Sincronização concluída! Pode apagar este arquivo por segurança.</p>";
?>
