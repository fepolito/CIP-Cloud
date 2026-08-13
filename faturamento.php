<?php
/**
 * @arquivo       faturamento.php
 * @versao        1.1.0
 * @modificado_em 2026-08-13
 * @objetivo      Pagina de conciliacao faturamento x telemetria, integrada ao menu global.
 *                Highlight de pagina ativa + guard para tenant sem controlador selecionado.
 *                [FIX] Boilerplate HTML e CSS global inserido (app_head.php).
 * @autor         Fernando / CIP Cloud Copilot
 */
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers/Tenant.php';

$pdo = getDbConnection();
requireAuth();
use App\Helpers\Tenant;

$ctx = Tenant::contexto();
$filtroSql = Tenant::filtroSQL('c');

$sqlCtrls = "
    SELECT c.id, c.codigo, c.apelido, c.empresa_id, c.online,
           e.nome_fantasia AS empresa_nome
      FROM controladores c
      LEFT JOIN empresa e
             ON e.id = c.empresa_id
            AND e.deleted_at IS NULL
     WHERE c.status = 'ativo'
       {$filtroSql}
     ORDER BY e.nome_fantasia IS NULL, e.nome_fantasia, c.codigo
";

$params = [];
Tenant::aplicarParam($params);

$stmt = $pdo->prepare($sqlCtrls);
$stmt->execute();
$controladoresAcessiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ctrlSolicitado = filter_input(INPUT_GET, 'ctrl', FILTER_VALIDATE_INT) ?: null;
$ctrlPadrao     = isset($_SESSION['controlador_padrao']) ? (int) $_SESSION['controlador_padrao'] : null;

$controladorAtivo = null;

if ($ctrlSolicitado !== null) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int) $c['id'] === $ctrlSolicitado) { $controladorAtivo = $c; break; }
    }
}
if (!$controladorAtivo && $ctrlPadrao !== null) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int) $c['id'] === $ctrlPadrao) { $controladorAtivo = $c; break; }
    }
}
if (!$controladorAtivo && count($controladoresAcessiveis) > 0) {
    $controladorAtivo = $controladoresAcessiveis[0];
}
if ($controladorAtivo) {
    $_SESSION['controlador_padrao'] = $controladorAtivo['id'];
}

$appPaginaAtual  = 'faturamento';
$appTituloPagina = 'Faturamento';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-tema="escuro">
<head>
    <?php require __DIR__ . '/includes/app_head.php'; ?>
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content">
      <div class="container" style="padding: 20px;">
        <h1 style="margin-bottom: 20px;">Conciliação de Faturamento</h1>

        <form id="form-fatura" class="card" style="margin-bottom:20px; padding:20px; display:flex; flex-wrap:wrap; gap:10px;">
          <input type="hidden" name="controlador_id" id="fat-controlador">
          <label>Mês ref. (AAAA-MM)<br> <input type="month" name="mes_referencia" required></label>
          <label>Leitura anterior<br> <input type="date" name="data_leitura_ant" required></label>
          <label>Leitura atual<br>    <input type="date" name="data_leitura_atual" required></label>
          <label>Importada (kWh)<br>  <input type="number" step="0.0001" name="energia_importada_kwh" required></label>
          <label>Injetada (kWh)<br>   <input type="number" step="0.0001" name="energia_injetada_kwh" required></label>
          <label>Reg. anterior (opc.)<br> <input type="number" step="0.0001" name="leitura_ant_registro"></label>
          <label>Reg. atual (opc.)<br>    <input type="number" step="0.0001" name="leitura_atual_registro"></label>
          <div style="flex-basis: 100%;"><button type="submit" style="padding:8px 16px;">Salvar fatura</button></div>
        </form>

        <div class="card" style="padding:20px; overflow-x:auto;">
          <table id="tabela-conciliacao" class="tbl" style="width:100%; border-collapse:collapse; text-align:left;">
            <thead>
              <tr>
                <th rowspan="2" style="border-bottom: 1px solid var(--color-border); padding: 8px;">Mês</th>
                <th rowspan="2" style="border-bottom: 1px solid var(--color-border); padding: 8px;">Janela</th>
                <th colspan="3" style="border-bottom: 1px solid var(--color-border); padding: 8px; text-align: center;">Importada (kWh)</th>
                <th colspan="3" style="border-bottom: 1px solid var(--color-border); padding: 8px; text-align: center;">Injetada (kWh)</th>
                <th rowspan="2" style="border-bottom: 1px solid var(--color-border); padding: 8px;">Δ Custo (R$)</th>
                <th rowspan="2" style="border-bottom: 1px solid var(--color-border); padding: 8px;"></th>
              </tr>
              <tr>
                <th style="border-bottom: 1px solid var(--color-border); padding: 8px;">CPFL</th>
                <th style="border-bottom: 1px solid var(--color-border); padding: 8px;">CIP</th>
                <th style="border-bottom: 1px solid var(--color-border); padding: 8px;">Δ%</th>
                <th style="border-bottom: 1px solid var(--color-border); padding: 8px;">CPFL</th>
                <th style="border-bottom: 1px solid var(--color-border); padding: 8px;">CIP</th>
                <th style="border-bottom: 1px solid var(--color-border); padding: 8px;">Δ%</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>
    </main>

    <footer class="footer">
        CIP – Controlador de Injeção de Potência Elétrica &nbsp;|&nbsp;
        Aeonium &nbsp;|&nbsp; São Paulo, BR &nbsp;|&nbsp; v1.15.1
    </footer>

    <script>
      // Injeta o controladorAtivo para uso no window.CIP_CTRL_ID
      window.CIP_CTRL_ID = <?= json_encode($controladorAtivo ? (int)$controladorAtivo['id'] : 0) ?>;
      const CTRL = window.CIP_CTRL_ID; 

      if (!CTRL) {
        document.querySelector('.container').innerHTML =
          '<div class="card aviso" style="margin-top:20px; padding:20px;">Selecione um controlador no cabeçalho para usar a conciliação de faturamento.</div>';
      } else {
        document.getElementById('fat-controlador').value = CTRL;
        carregar();
      }

      document.querySelector('#tabela-conciliacao tbody').addEventListener('click', async (ev) => {
        const btn = ev.target.closest('.btn-del-fat');
        if (!btn) return;
        const id = btn.dataset.id;
        if (!id || !confirm('Apagar esta fatura? Esta ação não pode ser desfeita.')) return;
        const r = await fetch('/api/faturamento/excluir.php', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({ id: Number(id) })
        });
        const j = await r.json();
        if (j.sucesso) carregar();
        else alert(j.erro || 'Erro ao apagar');
      });

      document.getElementById('form-fatura').addEventListener('submit', async (e) => {
        e.preventDefault();
        const body = Object.fromEntries(new FormData(e.target).entries());
        const r = await fetch('/api/faturamento/salvar.php', {
          method: 'POST', headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(body)
        });
        const j = await r.json();
        if (j.sucesso) { carregar(); e.target.reset(); document.getElementById('fat-controlador').value = CTRL; }
        else alert(j.erro || 'Erro ao salvar');
      });

      async function carregar() {
        const r = await fetch(`/api/faturamento/conciliacao.php?controlador_id=${CTRL}`);
        const j = await r.json();
        const tb = document.querySelector('#tabela-conciliacao tbody');
        tb.innerHTML = '';
        (j.data || []).forEach(row => {
          const cor = p => Math.abs(p ?? 0) > 5 ? 'style="color:#ff5252;font-weight:600"' : '';
          tb.insertAdjacentHTML('beforeend', `
            <tr>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);">${row.mes_referencia}</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);">${row.data_leitura_ant} → ${row.data_leitura_atual}</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);">${row.importada.cpfl}</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);">${row.importada.cip}</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);" ${cor(row.importada.delta_pct)}>${row.importada.delta_pct ?? '—'}%</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);">${row.injetada.cpfl}</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);">${row.injetada.cip}</td>
              <td style="padding: 8px; border-bottom: 1px solid var(--color-border);" ${cor(row.injetada.delta_pct)}>${row.injetada.delta_pct ?? '—'}%</td>
              <td style="padding:8px; border-bottom:1px solid var(--color-border);" ${cor(row.custo?.delta_pct)}>
                ${row.custo ? 'R$ ' + row.custo.delta_rs.toLocaleString('pt-BR',{minimumFractionDigits:2}) : '—'}
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--color-border); text-align:center;">
                <button type="button" class="btn-del-fat" data-id="${row.id}"
                  title="Apagar esta linha"
                  style="background:none; border:none; cursor:pointer; color:#ff5252; font-size:16px;">🗑️</button>
              </td>
            </tr>`);
        });
      }
    </script>
    <script src="/assets/js/app-shell.js"></script>
</body>
</html>
