<?php
/**
 * @arquivo       app/services/Solis/SolisIngestor.php
 * @versao        1.1.1
 * @modificado_em 2026-08-05
 * @objetivo      Coleta inverterList (paginado), faz upsert de inversores e
 *                INSERT-if-null em telemetria_5min_inversor/_string e telemetria_5min.
 *                Usa pac1/etoday1/etotal1 e separa delta (energia_geracao_kwh) do acumulado (energia_geracao_total_kwh).
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
declare(strict_types=1);

final class SolisIngestor
{
    private PDO $pdo;
    private array $cfg;

    public function __construct(PDO $pdo, array $cfg)
    {
        $this->pdo = $pdo;
        $this->cfg = $cfg;
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
            CURLOPT_SSL_VERIFYPEER => false // Added to ensure it works properly locally
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

    /** Bucket 5min UTC defasado. */
    private function bucketUtc(): string
    {
        $t = time() - ($this->cfg['bucket_lag_min'] * 60);
        $t -= $t % 300; // trava em multiplo de 5min
        return gmdate('Y-m-d H:i:s', $t);
    }

    /** Executa a coleta completa (todas as paginas). */
    public function run(): array
    {
        $bucket = $this->bucketUtc();
        $page   = 1;
        $stats  = ['inversores' => 0, 'telemetria' => 0, 'strings' => 0];

        do {
            $data = $this->request('/v1/api/inverterList', [
                'pageNo' => $page, 'pageSize' => $this->cfg['page_size'],
            ]);
            $records = $data['page']['records'] ?? [];
            $pages   = (int)($data['page']['pages'] ?? 1);

            foreach ($records as $r) {
                $invId = $this->upsertInversor($r);
                $stats['inversores']++;
                $this->insertTelemetria($invId, $bucket, $r);
                $stats['telemetria']++;
                $stats['strings'] += $this->insertStrings($invId, $bucket, $r);
            }
            $page++;
        } while ($page <= $pages);

        $stats['agregado'] = $this->aggregateToController($bucket);

        return ['bucket_utc' => $bucket] + $stats;
    }

    /** Agrega inversores por controlador → telemetria_5min (INSERT-if-null; ESP vence). */
    private function aggregateToController(string $bucket): int
    {
        // Soma potencia/energia dos inversores vinculados a cada controlador.
        $sql = "
          SELECT i.controlador_id AS ctrl,
                 SUM(t.potencia_ac_w)     AS pac_w,
                 SUM(t.energia_total_kwh) AS etot_kwh,
                 MAX(t.estado)            AS estado
            FROM telemetria_5min_inversor t
            JOIN inversores i ON i.id = t.inversor_id
           WHERE t.timestamp_utc = :ts
             AND i.controlador_id IS NOT NULL
             AND i.ativo = 1
           GROUP BY i.controlador_id";
        $sel = $this->pdo->prepare($sql);
        $sel->execute([':ts' => $bucket]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        $ins = $this->pdo->prepare("
          INSERT INTO telemetria_5min
            (controlador_id, timestamp_utc, potencia_geracao_w,
             energia_geracao_kwh, energia_geracao_total_kwh, status_inversor, geracao_origem, qualidade_dado)
          VALUES (:ctrl,:ts,:pac,:delta,:etot,:st,'api_externa',80)
          ON DUPLICATE KEY UPDATE id=id"); // ESP (se ja gravou) vence

        $n = 0;
        foreach ($rows as $r) {
            $etot = (float)$r['etot_kwh'];
            $delta = $this->energiaGeracaoDelta((int)$r['ctrl'], $bucket, $etot);
            
            $ins->execute([
                ':ctrl'  => (int)$r['ctrl'],
                ':ts'    => $bucket,
                ':pac'   => (float)$r['pac_w'],
                ':delta' => $delta,
                ':etot'  => $etot,
                ':st'    => (string)($r['estado'] ?? ''),
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * Calcula o delta de geracao (kWh) para o controlador subtraindo
     * o valor atual da energia_geracao_total_kwh (Solis) com o ultimo bucket conhecido.
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
            return 0.0;                     // 1o bucket -> 0.0 (era null)
        }

        $delta = $etotalSoma - (float)$lastEtotal;
        return ($delta >= 0 && $delta < 1000) ? round($delta, 4) : 0.0;  // teto anti-rollover
    }

    private function upsertInversor(array $r): int
    {
        $sql = "INSERT INTO inversores
                 (empresa_id, solis_sn, solis_inverter_id, collector_sn,
                  station_name, station_id, modelo, potencia_nominal_w, num_mppt)
                VALUES (:emp,:sn,:iid,:col,:st,:sid,:mac,:pw,:mppt)
                ON DUPLICATE KEY UPDATE
                  solis_inverter_id=VALUES(solis_inverter_id),
                  collector_sn=VALUES(collector_sn),
                  station_name=VALUES(station_name),
                  modelo=VALUES(modelo),
                  potencia_nominal_w=VALUES(potencia_nominal_w),
                  num_mppt=VALUES(num_mppt),
                  id=LAST_INSERT_ID(id)";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':emp' => $this->cfg['empresa_id'],
            ':sn'  => (string)($r['inverterSn'] ?? $r['sn']),
            ':iid' => (string)($r['inverterId'] ?? ''),
            ':col' => (string)($r['collectorSn'] ?? ''),
            ':st'  => (string)($r['stationName'] ?? ''),
            ':sid' => (string)($r['stationId'] ?? ''),
            ':mac' => (string)($r['machine'] ?? ''),
            ':pw'  => isset($r['power1']) ? (float)$r['power1'] : null,
            ':mppt'=> isset($r['dcInputTypeMppt']) ? (int)$r['dcInputTypeMppt'] : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /** INSERT-if-null: so grava se ESP nao ocupou o bucket. */
    private function insertTelemetria(int $invId, string $bucket, array $r): void
    {
        $sql = "INSERT INTO telemetria_5min_inversor
                 (inversor_id, timestamp_utc, potencia_ac_w,
                  energia_hoje_kwh, energia_total_kwh, estado, fonte)
                VALUES (:inv,:ts,:pac,:et,:etot,:est,'solis')
                ON DUPLICATE KEY UPDATE id=id"; // nunca sobrescreve
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':inv'  => $invId,
            ':ts'   => $bucket,
            ':pac'  => isset($r['pac1'])    ? (float)$r['pac1']    : null, // W
            ':et'   => isset($r['etoday1']) ? (float)$r['etoday1'] : null, // kWh
            ':etot' => isset($r['etotal1']) ? (float)$r['etotal1'] : null, // kWh
            ':est'  => isset($r['state'])   ? (int)$r['state']     : null,
        ]);
    }

    /** Grava apenas strings com potencia > 0 (pow1..pow32). */
    private function insertStrings(int $invId, string $bucket, array $r): int
    {
        $sql = "INSERT INTO telemetria_5min_string
                 (inversor_id, timestamp_utc, string_num, potencia_w, fonte)
                VALUES (:inv,:ts,:num,:pw,'solis')
                ON DUPLICATE KEY UPDATE id=id";
        $st = $this->pdo->prepare($sql);
        $n = 0;
        for ($i = 1; $i <= 32; $i++) {
            $v = $r["pow{$i}"] ?? 0;
            if ((float)$v <= 0) continue;
            $st->execute([':inv'=>$invId, ':ts'=>$bucket, ':num'=>$i, ':pw'=>(float)$v]);
            $n++;
        }
        return $n;
    }
}
