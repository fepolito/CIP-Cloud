<?php
/**
 * @arquivo       app/services/Solis/SolisIngestor.php
 * @versao        1.3.0
 * @modificado_em 2026-10-07
 * @objetivo      Coleta inverterList (paginado), faz upsert de inversores vinculando
 *                empresa_id e controlador_id via solis_station_id, grava telemetria
 *                segregada (telemetria_5min_inversor/_string) e delega a consolidação
 *                de telemetria_5min ao TelemetriaConsolidador a partir de controlador_inversores.
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

    /** Agrega inversores por controlador → telemetria_5min via TelemetriaConsolidador. */
    private function aggregateToController(string $bucket): int
    {
        // Soma potencia/energia dos inversores vinculados a controladores virtuais SOLIS-CLOUD
        // usando controlador_inversores.status = 'ativo' como fonte única da verdade
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

            // Grava contribuição da marca Solis e consolida em telemetria_5min
            $this->consolidador->gravarContribuicaoMarca($ctrlId, $bucket, 'SOLIS', $pacW, $delta);
            $this->consolidador->consolidar($ctrlId, $bucket, [
                'energia_geracao_total_kwh' => $etot,
                'status_inversor'           => (string)($r['estado'] ?? ''),
                'qualidade_dado'            => 80,
            ]);

            // Atualiza status online para controladores virtuais SOLIS-CLOUD
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

    /**
     * Resolve empresa_id e controlador_id a partir do station_id retornado pela API.
     */
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
