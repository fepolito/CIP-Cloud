<?php
/**
 * @arquivo       app/services/Solis/SolisIngestor.php
 * @versao        1.4.0
 * @modificado_em 2026-10-12
 * @objetivo      Coleta inverterList (paginado) para descoberta/upsert de inversores,
 *                depois busca inverterDay por inversor para capturar a janela dos
 *                ultimos 15min (3 buckets de 5min), compensando o limite de cron de
 *                15min do hosting compartilhado. Grava telemetria segregada
 *                (telemetria_5min_inversor/_string) e delega a consolidacao de
 *                telemetria_5min ao TelemetriaConsolidador por bucket.
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

require_once __DIR__ . '/../Shared/TelemetriaConsolidador.php';

use App\Services\Shared\TelemetriaConsolidador;

final class SolisIngestor
{
    private PDO $pdo;
    private array $cfg;
    private ?array $stationMap = null;
    private TelemetriaConsolidador $consolidador;

    public function __construct(PDO $pdo, array $cfg, ?TelemetriaConsolidador $consolidador = null)
    {
        $this->pdo          = $pdo;
        $this->cfg          = $cfg;
        $this->consolidador = $consolidador ?? new TelemetriaConsolidador($pdo);
    }

    /** Assinatura HMAC-SHA1 padrao SolisCloud. */
    private function request(string $path, array $body): array
    {
        $json   = json_encode($body, JSON_UNESCAPED_SLASHES);
        $md5    = base64_encode(md5($json, true));
        $date   = gmdate('D, d M Y H:i:s \G\M\T');
        $toSign = "POST\n{$md5}\napplication/json\n{$date}\n{$path}";
        $sign   = base64_encode(hash_hmac('sha1', $toSign, $this->cfg['key_secret'], true));
        $auth   = "API {$this->cfg['key_id']}:{$sign}";

        $ch = curl_init($this->cfg['base_url'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                "Content-MD5: {$md5}",
                "Content-Type: application/json",
                "Date: {$date}",
                "Authorization: {$auth}",
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new RuntimeException('Solis cURL: ' . curl_error($ch));
        }
        curl_close($ch);
        $data = json_decode($resp, true);
        if (!is_array($data) || ($data['success'] ?? false) !== true) {
            throw new RuntimeException('Solis API erro: ' . substr($resp, 0, 300));
        }
        return $data['data'] ?? [];
    }

    /**
     * Gera os buckets de 5min (UTC) cobrindo a janela dos ultimos N minutos.
     * Substitui o antigo bucketUtc() (ponto unico) para compensar o limite de
     * cron de 15min do hosting compartilhado: cada execucao agora recupera
     * os 3 buckets de 5min do ciclo, nao apenas o instante atual.
     *
     * @return array<int,string> buckets em ordem cronologica (mais antigo -> mais recente)
     */
    private function janelaBuckets(): array
    {
        $janelaMin = (int)($this->cfg['janela_min'] ?? 15);
        $lagMin    = (int)($this->cfg['bucket_lag_min'] ?? 0);

        $fim = time() - ($lagMin * 60);
        $fim -= $fim % 300; // ultimo bucket fechado

        $buckets = [];
        $qtdBuckets = (int) ceil($janelaMin / 5);
        for ($i = $qtdBuckets - 1; $i >= 0; $i--) {
            $buckets[] = gmdate('Y-m-d H:i:s', $fim - ($i * 300));
        }
        return $buckets;
    }

    /** Executa a coleta completa (todas as paginas + janela de historico). */
    public function run(): array
    {
        $buckets = $this->janelaBuckets();
        $tsMin   = strtotime($buckets[0]  . ' UTC');
        $tsMax   = strtotime(end($buckets) . ' UTC') + 299; // fecha o ultimo bucket

        $page   = 1;
        $stats  = ['inversores' => 0, 'telemetria' => 0, 'strings' => 0, 'buckets' => count($buckets)];
        $ctrlAfetados = [];

        do {
            $data = $this->request('/v1/api/inverterList', [
                'pageNo' => $page, 'pageSize' => $this->cfg['page_size'],
            ]);
            $records = $data['page']['records'] ?? [];
            $pages   = (int)($data['page']['pages'] ?? 1);

            foreach ($records as $r) {
                $invId = $this->upsertInversor($r);
                $stats['inversores']++;

                $pontos = $this->buscarHistoricoDoDia($r, $tsMin, $tsMax);
                foreach ($pontos as $bucket => $ponto) {
                    $this->insertTelemetria($invId, $bucket, $ponto);
                    $stats['telemetria']++;
                    $stats['strings'] += $this->insertStrings($invId, $bucket, $ponto);
                }
            }
            $page++;
        } while ($page <= $pages);

        // Consolida cada bucket da janela sequencialmente (ordem cronologica
        // importa para o calculo correto do delta de energia).
        $stats['agregado'] = 0;
        foreach ($buckets as $bucket) {
            $stats['agregado'] += $this->aggregateToController($bucket);
        }

        return ['buckets_utc' => $buckets] + $stats;
    }

    /**
     * Busca /v1/api/inverterDay do inversor e filtra apenas os pontos cujo
     * dataTimestamp (epoch ms, UTC real) cai dentro da janela [tsMin, tsMax].
     * Retorna indexado por bucket UTC (Y-m-d H:i:s), 1 ponto por bucket
     * (o mais recente dentro de cada bucket, em caso de multiplas amostras).
     *
     * @return array<string, array> bucket_utc => ponto bruto da API
     */
    private function buscarHistoricoDoDia(array $r, int $tsMin, int $tsMax): array
    {
        $invId = (string)($r['inverterId'] ?? '');
        if ($invId === '') {
            return [];
        }

        try {
            // 'time' da inverterDay e a data LOCAL do inversor; usamos a data
            // UTC do fim da janela convertida (seguro o suficiente pois a API
            // retorna o dia completo e filtramos por dataTimestamp real a seguir).
            $diaRef = gmdate('Y-m-d', $tsMax);
            $data = $this->request('/v1/api/inverterDay', [
                'id'       => $invId,
                'money'    => 'BRL',
                'time'     => $diaRef,
                'timeZone' => (int)($this->cfg['timezone_offset'] ?? -3),
            ]);
        } catch (Throwable $e) {
            @error_log('[solis_ingestor] inverterDay falhou para inverterId=' . $invId . ': ' . $e->getMessage());
            return [];
        }

        $pontos = [];
        foreach ($data as $p) {
            $tsMs = (int)($p['dataTimestamp'] ?? 0);
            if ($tsMs === 0) {
                continue;
            }
            $tsSec = intdiv($tsMs, 1000); // epoch ms -> epoch s, sempre UTC real
            if ($tsSec < $tsMin || $tsSec > $tsMax) {
                continue;
            }
            $bucketSec = $tsSec - ($tsSec % 300);
            $bucket = gmdate('Y-m-d H:i:s', $bucketSec);
            // Mantem o ponto mais recente dentro do bucket (ordem da API e cronologica).
            $pontos[$bucket] = $p;
        }

        ksort($pontos);
        return $pontos;
    }

    /** Agrega inversores por controlador -> telemetria_5min via TelemetriaConsolidador. */
    private function aggregateToController(string $bucket): int
    {
        $sql = "
          SELECT ci.controlador_id AS ctrl,
                 SUM(t.potencia_ac_w)     AS pac_w,
                 SUM(t.energia_total_kwh) AS etot_kwh,
                 MAX(t.estado)            AS estado
            FROM telemetria_5min_inversor t
            JOIN inversores i ON i.id = t.inversor_id
            JOIN controlador_inversores ci ON (ci.inversor_ref_id = i.id OR ci.inversor_sn = i.solis_sn)
             AND ci.marca = 'SOLIS'
             AND ci.status = 'ativo'
            JOIN controladores c ON c.id = ci.controlador_id
           WHERE t.timestamp_utc = :ts
             AND c.status <> 'inativo'
           GROUP BY ci.controlador_id";
        $sel = $this->pdo->prepare($sql);
        $sel->execute([':ts' => $bucket]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        $n = 0;
        foreach ($rows as $r) {
            $ctrlId = (int)$r['ctrl'];
            $pacW   = (float)$r['pac_w'];
            $etot   = (float)$r['etot_kwh'];
            $delta  = $this->energiaGeracaoDelta($ctrlId, $bucket, $etot);

            $this->consolidador->gravarContribuicaoMarca($ctrlId, $bucket, 'SOLIS', $pacW, $delta);
            $this->consolidador->consolidar($ctrlId, $bucket, [
                'energia_geracao_total_kwh' => $etot,
                'status_inversor'           => (string)($r['estado'] ?? ''),
                'qualidade_dado'            => 80,
            ]);

            $upCtrl = $this->pdo->prepare("
                UPDATE controladores
                   SET last_telemetry_at = UTC_TIMESTAMP(),
                       last_seen_at = UTC_TIMESTAMP(),
                       online = 1
                 WHERE id = :id AND tipo = 'SOLIS-CLOUD'
            ");
            $upCtrl->execute([':id' => $ctrlId]);

            $n++;
        }
        return $n;
    }

    /**
     * Calcula o delta de geracao (kWh), comparando com o ultimo bucket
     * conhecido no banco ANTES do bucket atual. Como aggregateToController()
     * agora roda sequencialmente por bucket (ordem cronologica) dentro do
     * mesmo run(), o bucket N+1 naturalmente ja encontra o bucket N gravado
     * no banco (telemetria_5min), preservando a continuidade do delta.
     */
    private function energiaGeracaoDelta(int $ctrl, string $bucket, float $etotalSoma): float
    {
        $sql = "SELECT energia_geracao_total_kwh 
                  FROM telemetria_5min 
                 WHERE controlador_id = :ctrl 
                   AND timestamp_utc < :ts 
                   AND energia_geracao_total_kwh IS NOT NULL
                 ORDER BY timestamp_utc DESC LIMIT 1";
        $st = $this->pdo->prepare($sql);
        $st->execute([':ctrl' => $ctrl, ':ts' => $bucket]);
        $lastEtotal = $st->fetchColumn();

        if ($lastEtotal === false) {
            return 0.0;
        }

        $delta = $etotalSoma - (float)$lastEtotal;
        return ($delta >= 0 && $delta < 1000) ? round($delta, 4) : 0.0;
    }

    private function resolverControladorEEmpresa(string $stationId): array
    {
        if ($this->stationMap === null) {
            $this->stationMap = [];
            $stmt = $this->pdo->query("
                SELECT id, empresa_id, solis_station_id
                FROM controladores
                WHERE solis_station_id IS NOT NULL AND solis_station_id != ''
            ");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->stationMap[(string)$row['solis_station_id']] = [
                    'ctrl_id'    => (int)$row['id'],
                    'empresa_id' => (int)$row['empresa_id'],
                ];
            }
        }

        if ($stationId !== '' && isset($this->stationMap[$stationId])) {
            return [
                'ctrl_id'    => $this->stationMap[$stationId]['ctrl_id'],
                'empresa_id' => $this->stationMap[$stationId]['empresa_id'],
            ];
        }

        return [
            'ctrl_id'    => null,
            'empresa_id' => (int)$this->cfg['empresa_id'],
        ];
    }

    private function upsertInversor(array $r): int
    {
        $stationId = (string)($r['stationId'] ?? '');
        $vinculo   = $this->resolverControladorEEmpresa($stationId);

        $sql = "INSERT INTO inversores
                 (empresa_id, controlador_id, solis_sn, solis_inverter_id, collector_sn,
                  station_name, station_id, modelo, potencia_nominal_w, num_mppt)
                VALUES (:emp,:ctrl,:sn,:iid,:col,:st,:sid,:mac,:pw,:mppt)
                ON DUPLICATE KEY UPDATE
                  empresa_id=VALUES(empresa_id),
                  controlador_id=COALESCE(VALUES(controlador_id), controlador_id),
                  solis_inverter_id=VALUES(solis_inverter_id),
                  collector_sn=VALUES(collector_sn),
                  station_name=VALUES(station_name),
                  modelo=VALUES(modelo),
                  potencia_nominal_w=VALUES(potencia_nominal_w),
                  num_mppt=VALUES(num_mppt),
                  id=LAST_INSERT_ID(id)";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':emp'  => $vinculo['empresa_id'],
            ':ctrl' => $vinculo['ctrl_id'],
            ':sn'   => (string)($r['inverterSn'] ?? $r['sn']),
            ':iid'  => (string)($r['inverterId'] ?? ''),
            ':col'  => (string)($r['collectorSn'] ?? ''),
            ':st'   => (string)($r['stationName'] ?? ''),
            ':sid'  => $stationId,
            ':mac'  => (string)($r['machine'] ?? ''),
            ':pw'   => isset($r['power1']) ? (float)$r['power1'] : null,
            ':mppt' => isset($r['dcInputTypeMppt']) ? (int)$r['dcInputTypeMppt'] : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * INSERT-if-null: so grava se o bucket ainda nao foi ocupado.
     * Campos renomeados em relacao a inverterList: pac/eToday/eTotal/state
     * (inverterDay) em vez de pac1/etoday1/etotal1/state (inverterList).
     */
    private function insertTelemetria(int $invId, string $bucket, array $p): void
    {
        $sql = "INSERT INTO telemetria_5min_inversor
                 (inversor_id, timestamp_utc, potencia_ac_w,
                  energia_hoje_kwh, energia_total_kwh, estado, fonte)
                VALUES (:inv,:ts,:pac,:et,:etot,:est,'solis')
                ON DUPLICATE KEY UPDATE id=id";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':inv'  => $invId,
            ':ts'   => $bucket,
            ':pac'  => isset($p['pac'])    ? (float)$p['pac']    : null, // W
            ':et'   => isset($p['eToday']) ? (float)$p['eToday'] : null, // kWh
            ':etot' => isset($p['eTotal']) ? (float)$p['eTotal'] : null, // kWh
            ':est'  => isset($p['state'])  ? (int)$p['state']    : null,
        ]);
    }

    /** Grava apenas strings com potencia > 0 (uPvN/iPvN -> calcula pw = u*i). */
    private function insertStrings(int $invId, string $bucket, array $p): int
    {
        $sql = "INSERT INTO telemetria_5min_string
                 (inversor_id, timestamp_utc, string_num, potencia_w, fonte)
                VALUES (:inv,:ts,:num,:pw,'solis')
                ON DUPLICATE KEY UPDATE id=id";
        $st = $this->pdo->prepare($sql);
        $n = 0;
        for ($i = 1; $i <= 32; $i++) {
            $u = (float)($p["uPv{$i}"] ?? 0);
            $c = (float)($p["iPv{$i}"] ?? 0);
            $pw = $u * $c;
            if ($pw <= 0) continue;
            $st->execute([':inv'=>$invId, ':ts'=>$bucket, ':num'=>$i, ':pw'=>$pw]);
            $n++;
        }
        return $n;
    }
}
