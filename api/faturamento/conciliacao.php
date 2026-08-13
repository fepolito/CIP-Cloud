<?php
/**
 * @arquivo       api/faturamento/conciliacao.php
 * @versao        1.1.0
 * @modificado_em 2026-08-13
 * @objetivo      Compara faturamento da distribuidora (faturas_distribuidora) com a telemetria CIP
 *                (telemetria_5min, MAX-MIN timezone-aware) na janela meio-dia->meio-dia e retorna o delta.
 * @autor         Fernando / CIP Cloud Copilot
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/helpers/Tenant.php';
use App\Helpers\Tenant;

require_once __DIR__ . '/../../app/services/TarifaService.php';
use app\services\TarifaService;

// Protegendo o endpoint conforme padrão (app_header já tem isso na view, mas na API fazemos manual se não usar middleware)
require_once __DIR__ . '/../../app/auth.php';
if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['erro' => 'Nao autorizado']);
    exit;
}

try {
    $pdo = getDbConnection();

    $controladorId = (int)($_GET['controlador_id'] ?? 0);
    if ($controladorId <= 0) {
        http_response_code(400);
        echo json_encode(['erro' => 'controlador_id obrigatorio']);
        exit;
    }

    // Validacao de posse unificada (mesmo mecanismo do dashboard; master faz bypass)
    $filtroSql = Tenant::filtroSQL('c');
    $sqlCtrl = "SELECT c.id, c.empresa_id, c.timezone, c.tarifa_kwh, c.fator_injecao
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
    $empresaId    = (int) $ctrl['empresa_id'];
    $tz           = $ctrl['timezone'] ?: 'America/Sao_Paulo';
    $tarifaKwh    = (float)($ctrl['tarifa_kwh']    ?? 0.9482);
    $fatorInjecao = (float)($ctrl['fator_injecao'] ?? 0.760);

    // Busca faturas do controlador (tenant-safe)
    $sqlFat = 'SELECT * FROM faturas_distribuidora
               WHERE controlador_id = :cid AND empresa_id = :eid
               ORDER BY mes_referencia DESC';
    $stmt = $pdo->prepare($sqlFat);
    $stmt->execute([':cid' => $controladorId, ':eid' => $empresaId]);
    $faturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Query de medicao CIP por janela [dia_ant 12:00 -> dia_atual 12:00] local -> UTC
    $sqlMed = "
        SELECT
            MAX(energia_importada_kwh) - MIN(energia_importada_kwh) AS cip_importada,
            MAX(energia_exportada_kwh) - MIN(energia_exportada_kwh) AS cip_injetada,
            COUNT(*) AS amostras
        FROM telemetria_5min
        WHERE controlador_id = :cid
          AND timestamp_utc >= CONVERT_TZ(:ini, :tz1, 'UTC')
          AND timestamp_utc <  CONVERT_TZ(:fim, :tz2, 'UTC')
    ";
    $stmtMed = $pdo->prepare($sqlMed);

    $resultado = [];
    foreach ($faturas as $f) {
        $ini = $f['data_leitura_ant']   . ' 12:00:00';
        $fim = $f['data_leitura_atual']  . ' 12:00:00';

        $stmtMed->execute([
            ':cid' => $controladorId,
            ':ini' => $ini,
            ':fim' => $fim,
            ':tz1' => $tz,
            ':tz2' => $tz,
        ]);
        $m = $stmtMed->fetch(PDO::FETCH_ASSOC);

        $cipImp = (float)($m['cip_importada'] ?? 0);
        $cipInj = (float)($m['cip_injetada']  ?? 0);
        $cpflImp = (float)$f['energia_importada_kwh'];
        $cpflInj = (float)$f['energia_injetada_kwh'];

        $custoImpCpfl = TarifaService::custoConsumo($cpflImp, $tarifaKwh);
        $credInjCpfl  = round($cpflInj * ($tarifaKwh * $fatorInjecao), 2);
        $custoImpCip  = TarifaService::custoConsumo($cipImp, $tarifaKwh);
        $credInjCip   = round($cipInj  * ($tarifaKwh * $fatorInjecao), 2);
        $liqCpfl      = round($custoImpCpfl - $credInjCpfl, 2);
        $liqCip       = round($custoImpCip  - $credInjCip,  2);

        $resultado[] = [
            'id'               => (int) $f['id'],
            'mes_referencia'   => $f['mes_referencia'],
            'data_leitura_ant' => $f['data_leitura_ant'],
            'data_leitura_atual' => $f['data_leitura_atual'],
            'janela_local'     => "$ini -> $fim ($tz)",
            'amostras_cip'     => (int)($m['amostras'] ?? 0),
            'importada' => [
                'cpfl'    => round($cpflImp, 2),
                'cip'     => round($cipImp, 2),
                'delta'   => round($cipImp - $cpflImp, 2),
                'delta_pct' => $cpflImp > 0 ? round(($cipImp - $cpflImp) / $cpflImp * 100, 2) : null,
            ],
            'injetada' => [
                'cpfl'    => round($cpflInj, 2),
                'cip'     => round($cipInj, 2),
                'delta'   => round($cipInj - $cpflInj, 2),
                'delta_pct' => $cpflInj > 0 ? round(($cipInj - $cpflInj) / $cpflInj * 100, 2) : null,
            ],
            'custo' => [
                'tarifa_kwh'        => $tarifaKwh,
                'fator_injecao'     => $fatorInjecao,
                'cpfl_liquido_rs'   => $liqCpfl,
                'cip_liquido_rs'    => $liqCip,
                'delta_rs'          => round($liqCip - $liqCpfl, 2),
                'delta_pct'         => $liqCpfl != 0
                    ? round(($liqCip - $liqCpfl) / abs($liqCpfl) * 100, 2) : null,
            ],
        ];
    }

    echo json_encode(['sucesso' => true, 'data' => $resultado], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha no banco de dados']
        + (($_ENV['APP_ENV'] ?? '') === 'dev' ? ['detalhe' => $e->getMessage()] : []));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno']
        + (($_ENV['APP_ENV'] ?? '') === 'dev' ? ['detalhe' => $e->getMessage()] : []));
}
