<?php
require 'config/database.php';
$pdo = getDbConnection();
$sqlPico = "SELECT MAX(GREATEST(COALESCE(potencia_geracao_w, 0), COALESCE(potencia_exportada_w, 0), COALESCE(potencia_importada_w, 0))) AS max_pico 
            FROM telemetria_5min 
            WHERE controlador_id = 3 
            AND timestamp_utc >= UTC_TIMESTAMP() - INTERVAL 90 DAY";
$stmtPico = $pdo->prepare($sqlPico);
$stmtPico->execute();
$picoRow = $stmtPico->fetch(PDO::FETCH_ASSOC);
echo "max_pico = " . print_r($picoRow['max_pico'], true) . "\n";
