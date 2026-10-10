<?php
/**
 * ============================================================
 * Arquivo   : scripts/import_solis_plantas.php
 * Projeto   : CIP — Controlador de Injeção de Potência Elétrica
 * Versão    : 1.0.0
 * Modificado: 2026-10-06
 * Objetivo  : Importa plantas da Solis a partir da tabela inversores,
 *             criando empresas clientes (vinculadas à integradora Aeonium)
 *             e controladores virtuais do tipo SOLIS-CLOUD.
 *             Execução exclusiva via CLI e operação idempotente.
 *
 * Dependências de software/arquivos:
 *   - config/database.php
 *
 * Histórico de implementações:
 *   2026-10-06  v1.0.0  Criação inicial da rotina de importação de plantas Solis
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

    // 1. Obter plantas distintas com controlador_id pendente
    $stmtPlantas = $pdo->query("
        SELECT DISTINCT station_id, station_name
        FROM inversores
        WHERE controlador_id IS NULL
          AND station_id IS NOT NULL
          AND station_id != ''
        ORDER BY station_name ASC
    ");
    $plantas = $stmtPlantas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($plantas)) {
        echo "Nenhuma planta pendente com controlador_id IS NULL encontrada.\n";
        exit(0);
    }

    // 2. Buscar MAX atual de codigo sequencial SOLIS-VIR-XXXX para evitar colisões
    $stmtMax = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(codigo, 11) AS UNSIGNED))
        FROM controladores
        WHERE codigo LIKE 'SOLIS-VIR-%'
    ");
    $maxSeq = (int) ($stmtMax->fetchColumn() ?: 0);

    // Preparar statements reaproveitáveis
    $stmtCheck = $pdo->prepare("SELECT id, empresa_id FROM controladores WHERE solis_station_id = :sid LIMIT 1");
    $stmtInsEmp = $pdo->prepare("
        INSERT INTO empresa (nome_fantasia, tipo, empresa_pai_id, ativo)
        VALUES (:station_name, 'cliente_real', 1, 1)
    ");
    $stmtInsCtrl = $pdo->prepare("
        INSERT INTO controladores
            (codigo, apelido, tipo, empresa_id, solis_station_id, token_api_hash, status)
        VALUES
            (:codigo, :apelido, 'SOLIS-CLOUD', :empresa_id, :station_id, :token_hash, 'ativo')
    ");
    $stmtUpdInv = $pdo->prepare("
        UPDATE inversores
        SET empresa_id = :empresa_id,
            controlador_id = :ctrl_id
        WHERE station_id = :station_id
    ");

    $resultados = [];

    foreach ($plantas as $p) {
        $stationId   = (string) $p['station_id'];
        $stationName = trim((string) ($p['station_name'] ?? ''));
        if ($stationName === '') {
            $stationName = "Planta Solis {$stationId}";
        }

        // Idempotente: se solis_station_id já existir em controladores, reaproveitar e vincular inversores
        $stmtCheck->execute([':sid' => $stationId]);
        $ctrlExistente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($ctrlExistente) {
            $ctrlId    = (int) $ctrlExistente['id'];
            $empresaId = (int) $ctrlExistente['empresa_id'];

            $stmtUpdInv->execute([
                ':empresa_id' => $empresaId,
                ':ctrl_id'    => $ctrlId,
                ':station_id' => $stationId,
            ]);
            $qtdVinculados = $stmtUpdInv->rowCount();

            $stmtCod = $pdo->prepare("SELECT codigo FROM controladores WHERE id = :id");
            $stmtCod->execute([':id' => $ctrlId]);
            $codigo = (string) $stmtCod->fetchColumn();

            $resultados[] = [
                'station_name'       => $stationName,
                'empresa_id'         => $empresaId,
                'codigo_controlador' => $codigo,
                'qtd_inversores'     => $qtdVinculados,
            ];
            continue;
        }

        // a) INSERT INTO empresa (filha da raiz 1 Aeonium)
        $stmtInsEmp->execute([':station_name' => $stationName]);
        $empresaId = (int) $pdo->lastInsertId();

        // b) Gerar codigo sequencial: SOLIS-VIR-0001, 0002...
        $maxSeq++;
        $codigo = sprintf('SOLIS-VIR-%04d', $maxSeq);
        $apelido = "Solis - {$stationName}";
        if (mb_strlen($apelido) > 100) {
            $apelido = mb_substr($apelido, 0, 100);
        }
        $tokenHash = hash('sha256', "solis_virtual_{$codigo}_{$stationId}");

        // c) INSERT INTO controladores
        $stmtInsCtrl->execute([
            ':codigo'     => $codigo,
            ':apelido'    => $apelido,
            ':empresa_id' => $empresaId,
            ':station_id' => $stationId,
            ':token_hash' => $tokenHash,
        ]);
        $ctrlId = (int) $pdo->lastInsertId();

        // d) UPDATE inversores
        $stmtUpdInv->execute([
            ':empresa_id' => $empresaId,
            ':ctrl_id'    => $ctrlId,
            ':station_id' => $stationId,
        ]);
        $qtdVinculados = $stmtUpdInv->rowCount();

        $resultados[] = [
            'station_name'       => $stationName,
            'empresa_id'         => $empresaId,
            'codigo_controlador' => $codigo,
            'qtd_inversores'     => $qtdVinculados,
        ];
    }

    // Output formatado: station_name | empresa_id | codigo_controlador | qtd_inversores_vinculados
    printf("%-35s | %-10s | %-20s | %-25s\n", "station_name", "empresa_id", "codigo_controlador", "qtd_inversores_vinculados");
    echo str_repeat("-", 100) . "\n";
    foreach ($resultados as $r) {
        printf("%-35s | %-10d | %-20s | %-25d\n",
            mb_substr($r['station_name'], 0, 35),
            $r['empresa_id'],
            $r['codigo_controlador'],
            $r['qtd_inversores']
        );
    }
    echo str_repeat("-", 100) . "\n";
    echo "Total de plantas processadas: " . count($resultados) . "\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Erro na importação: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
