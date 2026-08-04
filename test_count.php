<?php
require 'config/database.php';
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT COUNT(*) as c, MAX(timestamp_utc) as m FROM telemetria_5min WHERE controlador_id=1");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
