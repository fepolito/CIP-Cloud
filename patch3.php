<?php
$code = file_get_contents('C:/laragon/www/monitor.aeonium.com.br/dashboard.php');
$search1 = '    function aplicarDados(p) {
      if (!p || !p.success) return;

      // Caso "vazio"';
$replace1 = '    function aplicarDados(p) {
      if (!p || !p.success) return;
      try {
      // Caso "vazio"';

$search2 = '    }

    /* --------- Fetch principal --------- */';
$replace2 = '      } catch (e) {
        console.error('."'".'[CIP] aplicarDados EXPLODIU ->'."'".', e);
        throw e;
      }
    }

    /* --------- Fetch principal --------- */';

$code = str_replace($search1, $replace1, $code);
$code = str_replace($search2, $replace2, $code);
file_put_contents('C:/laragon/www/monitor.aeonium.com.br/dashboard.php', $code);
