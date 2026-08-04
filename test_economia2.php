<?php
require 'config/app.php';
require 'config/database.php';
require 'app/services/TarifaService.php';
use app\services\TarifaService;

$pdo = getDbConnection();

$tz = new DateTimeZone('America/Sao_Paulo');
$dtInicio = new DateTimeImmutable('first day of this month 00:00:00', $tz);
$dtFim    = $dtInicio->modify('first day of next month');

$inicioUtc = $dtInicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$fimUtc    = $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$sqlData = "
    SELECT 
      COALESCE(SUM(energia_geracao_kwh), 0) AS geracao_kwh,
      COALESCE(MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh), 0) AS exportada_kwh
    FROM telemetria_5min
    WHERE controlador_id = 1
      AND timestamp_utc >= :ini
      AND timestamp_utc < :fim
";
$stmtData = $pdo->prepare($sqlData);
$stmtData->execute([
    ':ini'     => $inicioUtc,
    ':fim'     => $fimUtc
]);

$data = $stmtData->fetch(PDO::FETCH_ASSOC);

$geracaoKwh   = (float)$data['geracao_kwh'];
$exportadaKwh = (float)$data['exportada_kwh'];

echo "Geracao (mes): $geracaoKwh kWh\n";
echo "Exportada (mes): $exportadaKwh kWh\n";

$eco = TarifaService::economia($geracaoKwh, $exportadaKwh);
print_r($eco);
