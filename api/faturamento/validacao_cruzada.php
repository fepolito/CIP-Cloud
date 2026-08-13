<?php
/**
 * @arquivo       api/faturamento/validacao_cruzada.php
 * @versao        1.0.1
 * @modificado_em 2026-08-13
 * @objetivo      Validacao cruzada de energia no periodo da fatura: compara metodo acumulador
 *                (MAX-MIN de energia_*_kwh) vs integracao de potencia (SUM potencia_w /12000)
 *                vs valor da fatura CPFL. Diagnostico read-only para RCA de divergencia.
 * @autor         Fernando / CIP Cloud Copilot
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';
use App\Helpers\Tenant;

require_once __DIR__ . '/../../app/auth.php';
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Nao autorizado']);
    exit;
}

try {
    $pdo = getDbConnection();
    $controladorId = (int)($_GET['controlador_id'] ?? 0);
    $mes           = trim((string)($_GET['mes_referencia'] ?? '')); // AAAA-MM (opcional)

    if ($controladorId <= 0) {
        http_response_code(400);
        echo json_encode(['erro' => 'controlador_id obrigatorio']);
        exit;
    }

    $filtroSql = Tenant::filtroSQL('c');
    $sqlCtrl = "SELECT c.id, c.empresa_id, c.timezone
                  FROM controladores c
                 WHERE c.id = :cid
                   {$filtroSql}
                 LIMIT 1";
    $paramsCtrl = [':cid' => $controladorId];
    Tenant::aplicarParam($paramsCtrl);

    $stmt = $pdo->prepare($sqlCtrl);
    $stmt->execute($paramsCtrl);
    $ctrl = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ctrl) {
        http_response_code(404);
        echo json_encode(['erro' => 'Controlador nao acessivel para o usuario']);
        exit;
    }
    $empresaId = (int) $ctrl['empresa_id'];
    $tz        = $ctrl['timezone'] ?: 'America/Sao_Paulo';

    // Faturas (uma ou todas)
    $sqlFat = 'SELECT mes_referencia, data_leitura_ant, data_leitura_atual,
                      energia_importada_kwh, energia_injetada_kwh
               FROM faturas_distribuidora
               WHERE controlador_id = :cid AND empresa_id = :eid';
    $params = [':cid' => $controladorId, ':eid' => $empresaId];
    if ($mes !== '') { $sqlFat .= ' AND mes_referencia = :mes'; $params[':mes'] = $mes; }
    $sqlFat .= ' ORDER BY mes_referencia DESC';
    $stmt = $pdo->prepare($sqlFat);
    $stmt->execute($params);
    $faturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Query unificada: metodo A (acumulador) + metodo B (integracao) na mesma janela
    // Integracao: SUM(potencia_w) * (5min/60min) / 1000  ==  SUM(potencia_w) / 12000
    $sql = "
        SELECT
            MAX(energia_importada_kwh) - MIN(energia_importada_kwh) AS acum_import,
            MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh) AS acum_export,
            SUM(potencia_importada_w) / 12000 AS integ_import,
            SUM(potencia_exportada_w) / 12000 AS integ_export,
            COUNT(*) AS amostras
        FROM telemetria_5min
        WHERE controlador_id = :cid
          AND timestamp_utc >= CONVERT_TZ(:ini, :tz1, 'UTC')
          AND timestamp_utc <  CONVERT_TZ(:fim, :tz2, 'UTC')
    ";
    $stmtT = $pdo->prepare($sql);

    $pct = fn($ref, $val) => ($ref > 0) ? round(($val - $ref) / $ref * 100, 2) : null;

    $out = [];
    foreach ($faturas as $f) {
        $ini = $f['data_leitura_ant']  . ' 12:00:00';
        $fim = $f['data_leitura_atual'] . ' 12:00:00';
        $stmtT->execute([':cid' => $controladorId, ':ini' => $ini, ':fim' => $fim, ':tz1' => $tz, ':tz2' => $tz]);
        $t = $stmtT->fetch(PDO::FETCH_ASSOC);

        $acumImp  = round((float)$t['acum_import'],  2);
        $acumExp  = round((float)$t['acum_export'],  2);
        $integImp = round((float)$t['integ_import'], 2);
        $integExp = round((float)$t['integ_export'], 2);
        $cpflImp  = round((float)$f['energia_importada_kwh'], 2);
        $cpflExp  = round((float)$f['energia_injetada_kwh'],  2);

        $esperado  = (int) round(((new DateTime($fim))->getTimestamp()
                     - (new DateTime($ini))->getTimestamp()) / 300);
        $cobertura = $esperado > 0 ? round($t['amostras'] / $esperado * 100, 1) : null;

        $out[] = [
            'mes_referencia' => $f['mes_referencia'],
            'janela'         => "$ini -> $fim ($tz)",
            'cobertura'      => [
                'coletadas' => (int)$t['amostras'],
                'esperadas' => $esperado,
                'pct'       => $cobertura,
            ],
            'importada' => [
                'cpfl'        => $cpflImp,
                'acumulador'  => $acumImp,
                'integracao'  => $integImp,
                'A_vs_B_kwh'  => round($acumImp - $integImp, 2),  // gap estimado
                'acum_vs_cpfl_pct'  => $pct($cpflImp, $acumImp),
                'integ_vs_cpfl_pct' => $pct($cpflImp, $integImp),
            ],
            'injetada' => [
                'cpfl'        => $cpflExp,
                'acumulador'  => $acumExp,
                'integracao'  => $integExp,
                'A_vs_B_kwh'  => round($acumExp - $integExp, 2),
                'acum_vs_cpfl_pct'  => $pct($cpflExp, $acumExp),
                'integ_vs_cpfl_pct' => $pct($cpflExp, $integExp),
            ],
        ];
    }

    echo json_encode(['sucesso' => true, 'data' => $out], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha no banco de dados']
        + (($_ENV['APP_ENV'] ?? '') === 'dev' ? ['detalhe' => $e->getMessage()] : []));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno']
        + (($_ENV['APP_ENV'] ?? '') === 'dev' ? ['detalhe' => $e->getMessage()] : []));
}
