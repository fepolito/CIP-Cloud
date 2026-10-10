<?php
/**
 * ============================================================
 * Arquivo   : scripts/migrar_solis_para_controlador_inversores.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-10-07
 * Objetivo  : Migra os vínculos dos inversores Solis da tabela `inversores`
 *             para a tabela unificada `controlador_inversores` de forma idempotente.
 * Autor     : Fernando / CIP Cloud Copilot / ATGY
 *
 * Dependências de software/arquivos:
 *   - config/database.php
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado exclusivamente via linha de comando (CLI).\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = getDbConnection();

    $stmtSel = $pdo->query("
        SELECT id, controlador_id, solis_sn, station_name, ativo
        FROM inversores
        WHERE controlador_id IS NOT NULL
        ORDER BY id ASC
    ");
    $linhas = $stmtSel->fetchAll(PDO::FETCH_ASSOC);

    echo "Iniciando migração de " . count($linhas) . " inversores Solis...\n";

    $stmtUpsert = $pdo->prepare("
        INSERT INTO controlador_inversores
          (controlador_id, inversor_sn, marca, tipo_equipamento, status, inversor_ref_id, criado_em)
        VALUES
          (:controlador_id, :solis_sn, 'SOLIS', 'inversor', :status, :inversor_id, NOW())
        ON DUPLICATE KEY UPDATE
          controlador_id  = VALUES(controlador_id),
          status          = VALUES(status),
          inversor_ref_id = VALUES(inversor_ref_id)
    ");

    $processados = 0;
    foreach ($linhas as $r) {
        $status = ((int)$r['ativo'] === 1) ? 'ativo' : 'inativo';
        $stmtUpsert->execute([
            ':controlador_id' => (int)$r['controlador_id'],
            ':solis_sn'       => (string)$r['solis_sn'],
            ':status'         => $status,
            ':inversor_id'    => (int)$r['id'],
        ]);
        $processados++;
    }

    echo "Migração concluída com sucesso! Total processado: {$processados}\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Erro na migração: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
