<?php
$tz = new DateTimeZone('America/Sao_Paulo');
$dtInicio = new DateTimeImmutable('first day of this month 00:00:00', $tz);
$dtFim    = $dtInicio->modify('first day of next month');

$inicioUtc = $dtInicio->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$fimUtc    = $dtFim->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

echo "Inicio: $inicioUtc\n";
echo "Fim: $fimUtc\n";
