<?php
require 'api/config/env.php';
require 'app/services/TarifaService.php';
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
$sql = "SELECT COALESCE(SUM(energia_geracao_kwh), 0) AS geracao_kwh, COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh FROM telemetria_5min WHERE controlador_id = 3 AND timestamp_utc >= '2026-07-18 03:00:00' AND timestamp_utc < '2026-07-19 03:00:00'";
$stmt = $pdo->query($sql);
$data = $stmt->fetch(PDO::FETCH_ASSOC);
$geracaoKwh = (float)$data['geracao_kwh'];
$exportadaKwh = (float)$data['exportada_kwh'];
$eco = \app\services\TarifaService::economia($geracaoKwh, $exportadaKwh);
echo json_encode(['sucesso' => true, 'data' => $eco], JSON_PRETTY_PRINT);

