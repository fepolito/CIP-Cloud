<?php
$_GET['controlador_id'] = 1;
require 'C:\laragon\www\monitor.aeonium.com.br\config\database.php';
$pdo = getDbConnection();
$stmt = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, CONVERT_TZ('2026-07-12 12:00:00','UTC','America/Sao_Paulo'), CONVERT_TZ('2026-07-12 12:05:00','UTC','America/Sao_Paulo')) AS t");
$stmt->execute();
echo "CONVERT_TZ test: " . var_export($stmt->fetchColumn(), true);