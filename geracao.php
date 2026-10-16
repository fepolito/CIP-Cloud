<?php
declare(strict_types=1);
/**
 * @arquivo       geracao.php
 * @versao        2.1.0
 * @modificado_em 2026-10-16
 * @objetivo      Página de Geração para monitoramento de energia solar (tons de laranja).
 *                Abas Dia (linha 24h, zoom, bandeira e navegação RW/FF), Mês (barras com 30 dias na base),
 *                Ano (barras com 12 meses na base), Total (barras com últimos 5 anos).
 *                Consome os endpoints api/geracao/{dia,mes,ano,total}.php.
 * @autor         Fernando / CIP Cloud Copilot
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/auth.php';
require_once __DIR__ . '/app/helpers/Tenant.php';
requireAuth();

use app\helpers\Tenant;

$pdo = getDbConnection();
$ctx = Tenant::contexto();
$filtroSql = Tenant::filtroSQL('c');

$sqlCtrls = "
    SELECT c.id, c.codigo, c.apelido, c.empresa_id,
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
$stmt->execute($params);
$controladoresAcessiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ctrlSolicitado = isset($_GET['ctrl']) ? (int)$_GET['ctrl'] : null;
$ctrlPadrao     = $_SESSION['controlador_padrao'] ?? null;

$controladorAtivo = null;
foreach ($controladoresAcessiveis as $c) {
    if ($ctrlSolicitado && (int)$c['id'] === $ctrlSolicitado) { $controladorAtivo = $c; break; }
}
if (!$controladorAtivo && $ctrlPadrao) {
    foreach ($controladoresAcessiveis as $c) {
        if ((int)$c['id'] === (int)$ctrlPadrao) { $controladorAtivo = $c; break; }
    }
}
if (!$controladorAtivo && !empty($controladoresAcessiveis)) {
    $controladorAtivo = $controladoresAcessiveis[0];
}
if ($controladorAtivo) {
    $_SESSION['controlador_padrao'] = (int) $controladorAtivo['id'];
}
$controladorId = $controladorAtivo ? (int) $controladorAtivo['id'] : 0;

$appTituloPagina    = 'Geração';
$appSubtituloPagina = 'Monitoramento da energia gerada';
$appPaginaAtual     = 'geracao';
$appIsAdmin         = (bool) ($_SESSION['usuario_admin'] ?? false);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geração - CIP Cloud</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/header.css">
    <!-- Chart.js 4 + Plugin Zoom -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
    <style>
        :root {
            --solar-orange: #f97316;
            --solar-orange-dark: #ea580c;
            --solar-orange-light: rgba(249, 115, 22, 0.15);
            --solar-orange-border: rgba(249, 115, 22, 0.35);
        }
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border-color, #1e293b);
        }
        .tab-btn {
            padding: 10px 20px;
            cursor: pointer;
            border: none;
            background: none;
            color: var(--text-muted-color, #94a3b8);
            font-weight: 600;
            font-size: 0.95rem;
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
        }
        .tab-btn:hover {
            color: #fdba74;
        }
        .tab-btn.active {
            color: var(--solar-orange);
            border-bottom-color: var(--solar-orange);
        }
        .geracao-resumo {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .geracao-resumo .card {
            background: var(--panel-bg, #11182a);
            border: 1px solid var(--solar-orange-border);
            border-radius: 10px;
            padding: 16px 22px;
            min-width: 190px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            transition: transform 0.15s ease, border-color 0.15s ease;
        }
        .geracao-resumo .card:hover {
            border-color: var(--solar-orange);
            transform: translateY(-2px);
        }
        .geracao-resumo .card .valor {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--solar-orange);
            letter-spacing: -0.5px;
        }
        .geracao-resumo .card .label {
            font-size: 0.85rem;
            color: var(--text-muted-color, #94a3b8);
            margin-top: 4px;
        }
        .periodo-controles {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .controles-nav-grupo {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--panel-bg, #11182a);
            padding: 6px 10px;
            border: 1px solid var(--border-color, #1e293b);
            border-radius: 8px;
        }
        .btn-nav {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #f8fafc;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            user-select: none;
        }
        .btn-nav:hover:not(:disabled) {
            background: var(--solar-orange);
            color: #ffffff;
            border-color: var(--solar-orange-dark);
        }
        .btn-nav:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .btn-nav.btn-hoje {
            background: var(--solar-orange-light);
            color: var(--solar-orange);
            border-color: var(--solar-orange-border);
        }
        .btn-nav.btn-hoje:hover:not(:disabled) {
            background: var(--solar-orange);
            color: #ffffff;
        }
        .btn-nav.btn-zoom {
            background: rgba(249, 115, 22, 0.12);
            color: #fb923c;
            border-color: var(--solar-orange-border);
            gap: 4px;
        }
        .btn-nav.btn-zoom:hover {
            background: var(--solar-orange);
            color: #ffffff;
        }
        .input-control {
            background: #0f172a;
            border: 1px solid rgba(255, 255, 255, 0.16);
            color: #f8fafc;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 0.9rem;
            font-weight: 500;
            outline: none;
            transition: border-color 0.15s ease;
        }
        .input-control:focus {
            border-color: var(--solar-orange);
        }
        .grafico-wrapper {
            position: relative;
            height: 440px;
            width: 100%;
        }
        .zoom-aviso {
            font-size: 0.8rem;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-total-anos {
            color: var(--solar-orange);
            font-weight: 600;
            font-size: 0.9rem;
            padding: 4px 8px;
        }
    </style>
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content">
        <section class="page-card">
            <h2>Geração de energia</h2>

            <div class="tabs">
                <button type="button" class="tab-btn active" data-periodo="dia">Dia</button>
                <button type="button" class="tab-btn" data-periodo="mes">Mês</button>
                <button type="button" class="tab-btn" data-periodo="ano">Ano</button>
                <button type="button" class="tab-btn" data-periodo="total">Total</button>
            </div>

            <div class="periodo-controles">
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <!-- Controles para DIA -->
                    <div id="controlesDia" class="controles-nav-grupo">
                        <button type="button" class="btn-nav" id="btnDiaRw" title="Retroceder 7 dias (RW)">« RW</button>
                        <button type="button" class="btn-nav" id="btnDiaAnt" title="Dia anterior (◀)">◀</button>
                        <input type="date" id="inputData" class="input-control" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>">
                        <button type="button" class="btn-nav" id="btnDiaProx" title="Próximo dia (▶)">▶</button>
                        <button type="button" class="btn-nav" id="btnDiaFf" title="Avançar 7 dias (FF)">FF »</button>
                        <button type="button" class="btn-nav btn-hoje" id="btnDiaHoje" title="Ir para o último dia / Hoje">Último dia</button>
                    </div>

                    <!-- Controles para MÊS -->
                    <div id="controlesMes" class="controles-nav-grupo" style="display:none;">
                        <button type="button" class="btn-nav" id="btnMesAnt" title="Mês anterior (◀)">◀</button>
                        <input type="month" id="inputMes" class="input-control" value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>">
                        <button type="button" class="btn-nav" id="btnMesProx" title="Próximo mês (▶)">▶</button>
                        <button type="button" class="btn-nav btn-hoje" id="btnMesAtual" title="Mês atual">Mês atual</button>
                    </div>

                    <!-- Controles para ANO -->
                    <div id="controlesAno" class="controles-nav-grupo" style="display:none;">
                        <button type="button" class="btn-nav" id="btnAnoAnt" title="Ano anterior (◀)">◀</button>
                        <select id="inputAno" class="input-control">
                            <?php for ($a = (int) date('Y'); $a >= (int) date('Y') - 6; $a--): ?>
                                <option value="<?php echo $a; ?>"><?php echo $a; ?></option>
                            <?php endfor; ?>
                        </select>
                        <button type="button" class="btn-nav" id="btnAnoProx" title="Próximo ano (▶)">▶</button>
                    </div>

                    <!-- Controles para TOTAL -->
                    <div id="controlesTotal" class="controles-nav-grupo" style="display:none;">
                        <span class="badge-total-anos">Histórico dos últimos 5 anos</span>
                    </div>
                </div>

                <!-- Controles de Zoom -->
                <div id="zoomControles" style="display: flex; gap: 8px; align-items: center;">
                    <span class="zoom-aviso" id="zoomAviso" style="display:none;">💡 Roda do mouse ou arrastar para zoom</span>
                    <button type="button" class="btn-nav btn-zoom" id="btnResetZoom" style="display:none;" title="Restaurar escala original">
                        ↺ Resetar Zoom
                    </button>
                </div>
            </div>

            <div class="geracao-resumo">
                <div class="card">
                    <div class="valor" id="resumoTotal">—</div>
                    <div class="label">Total gerado (kWh)</div>
                </div>
                <div class="card">
                    <div class="valor" id="resumoPico">—</div>
                    <div class="label" id="resumoPicoLabel">Pico instantâneo (kW)</div>
                </div>
            </div>

            <div class="grafico-wrapper">
                <canvas id="graficoGeracao"></canvas>
            </div>
        </section>
    </main>

    <script src="/assets/js/app-shell.js"></script>
    <script>
        const CONTROLADOR_ID = <?php echo json_encode($controladorId); ?>;
        let periodoAtual = 'dia';
        let chart = null;

        // Elementos de Data
        const inputData = document.getElementById('inputData');
        const inputMes  = document.getElementById('inputMes');
        const inputAno  = document.getElementById('inputAno');

        // Botões Navegação Dia
        const btnDiaRw   = document.getElementById('btnDiaRw');
        const btnDiaAnt  = document.getElementById('btnDiaAnt');
        const btnDiaProx = document.getElementById('btnDiaProx');
        const btnDiaFf   = document.getElementById('btnDiaFf');
        const btnDiaHoje = document.getElementById('btnDiaHoje');

        // Botões Navegação Mês
        const btnMesAnt   = document.getElementById('btnMesAnt');
        const btnMesProx  = document.getElementById('btnMesProx');
        const btnMesAtual = document.getElementById('btnMesAtual');

        // Botões Navegação Ano
        const btnAnoAnt  = document.getElementById('btnAnoAnt');
        const btnAnoProx = document.getElementById('btnAnoProx');

        // Zoom
        const btnResetZoom = document.getElementById('btnResetZoom');
        const zoomAviso    = document.getElementById('zoomAviso');

        function mostrarInputCorreto(periodo) {
            document.getElementById('controlesDia').style.display   = periodo === 'dia' ? 'inline-flex' : 'none';
            document.getElementById('controlesMes').style.display   = periodo === 'mes' ? 'inline-flex' : 'none';
            document.getElementById('controlesAno').style.display   = periodo === 'ano' ? 'inline-flex' : 'none';
            document.getElementById('controlesTotal').style.display = periodo === 'total' ? 'inline-flex' : 'none';

            zoomAviso.style.display = periodo === 'dia' ? 'inline-flex' : 'none';
            btnResetZoom.style.display = 'none';

            if (periodo === 'dia') atualizarLimitesDia();
            if (periodo === 'mes') atualizarLimitesMes();
            if (periodo === 'ano') atualizarLimitesAno();
        }

        function atualizarLimitesDia() {
            const hoje = new Date().toLocaleDateString('en-CA');
            const isHoje = inputData.value >= hoje;
            btnDiaProx.disabled = isHoje;
            btnDiaFf.disabled   = isHoje;
        }

        function mudarDia(deltaDias) {
            const partes = inputData.value.split('-').map(Number);
            const d = new Date(partes[0], partes[1] - 1, partes[2]);
            d.setDate(d.getDate() + deltaDias);

            const hoje = new Date();
            hoje.setHours(23, 59, 59, 999);
            if (d > hoje) {
                d.setTime(hoje.getTime());
            }

            const a   = d.getFullYear();
            const m   = String(d.getMonth() + 1).padStart(2, '0');
            const dia = String(d.getDate()).padStart(2, '0');
            inputData.value = `${a}-${m}-${dia}`;
            atualizarLimitesDia();
            carregarDados();
        }

        function atualizarLimitesMes() {
            const now = new Date();
            const mesHoje = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            const isMesAtual = inputMes.value >= mesHoje;
            btnMesProx.disabled = isMesAtual;
        }

        function mudarMes(deltaMeses) {
            const [ano, mes] = inputMes.value.split('-').map(Number);
            const d = new Date(ano, mes - 1 + deltaMeses, 1);
            const now = new Date();
            if (d.getFullYear() > now.getFullYear() || (d.getFullYear() === now.getFullYear() && d.getMonth() > now.getMonth())) {
                d.setFullYear(now.getFullYear(), now.getMonth(), 1);
            }
            const a = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            inputMes.value = `${a}-${m}`;
            atualizarLimitesMes();
            carregarDados();
        }

        function atualizarLimitesAno() {
            const anoHoje = new Date().getFullYear();
            btnAnoProx.disabled = Number(inputAno.value) >= anoHoje;
        }

        function mudarAno(delta) {
            const novoAno = Number(inputAno.value) + delta;
            const anoHoje = new Date().getFullYear();
            if (novoAno > anoHoje) return;

            if (![...inputAno.options].some(o => Number(o.value) === novoAno)) {
                const opt = new Option(String(novoAno), String(novoAno));
                if (delta > 0) {
                    inputAno.add(opt, 0);
                } else {
                    inputAno.add(opt);
                }
            }
            inputAno.value = String(novoAno);
            atualizarLimitesAno();
            carregarDados();
        }

        async function carregarDados() {
            if (!CONTROLADOR_ID) {
                document.getElementById('resumoTotal').textContent = 'Selecione um controlador';
                return;
            }

            let url = '';
            if (periodoAtual === 'dia') {
                url = `/api/geracao/dia.php?controlador_id=${CONTROLADOR_ID}&data=${inputData.value}`;
            } else if (periodoAtual === 'mes') {
                url = `/api/geracao/mes.php?controlador_id=${CONTROLADOR_ID}&mes=${inputMes.value}`;
            } else if (periodoAtual === 'ano') {
                url = `/api/geracao/ano.php?controlador_id=${CONTROLADOR_ID}&ano=${inputAno.value}`;
            } else {
                url = `/api/geracao/total.php?controlador_id=${CONTROLADOR_ID}`;
            }

            try {
                const resp = await fetch(url);
                const json = await resp.json();

                if (!json.sucesso) {
                    alert(json.erro || 'Erro ao carregar dados de geração.');
                    return;
                }

                document.getElementById('resumoTotal').textContent =
                    (json.resumo.total_geracao_kwh ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' kWh';

                if (periodoAtual === 'dia') {
                    document.getElementById('resumoPicoLabel').textContent = 'Pico instantâneo (kW)';
                    document.getElementById('resumoPico').textContent =
                        (json.resumo.pico_geracao_kw ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' kW';
                } else {
                    document.getElementById('resumoPicoLabel').textContent = 'Melhor período (kWh)';
                    document.getElementById('resumoPico').textContent =
                        (json.resumo.pico_geracao_kwh ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' kWh';
                }

                renderizarGrafico(json.labels, json.geracao, json.tipo_grafico);
            } catch (err) {
                console.error('Falha na requisição:', err);
            }
        }

        function renderizarGrafico(labels, dados, tipo) {
            const canvas = document.getElementById('graficoGeracao');
            const ctx = canvas.getContext('2d');
            if (chart) {
                chart.destroy();
                chart = null;
            }

            const isDia = periodoAtual === 'dia';
            const isMes = periodoAtual === 'mes';
            const isAno = periodoAtual === 'ano';

            // Gradiente laranja para a curva de linha
            let backgroundStyle = 'rgba(249, 115, 22, 0.85)';
            if (tipo === 'line') {
                const gradient = ctx.createLinearGradient(0, 0, 0, 380);
                gradient.addColorStop(0, 'rgba(249, 115, 22, 0.40)');
                gradient.addColorStop(0.7, 'rgba(249, 115, 22, 0.08)');
                gradient.addColorStop(1, 'rgba(249, 115, 22, 0.00)');
                backgroundStyle = gradient;
            }

            // Bandeira flutuante (tooltip customizado)
            const tooltipPlugin = {
                enabled: true,
                backgroundColor: 'rgba(15, 23, 42, 0.96)',
                titleColor: '#fb923c',
                titleFont: { size: 13, weight: 'bold', family: 'system-ui, sans-serif' },
                bodyColor: '#ffffff',
                bodyFont: { size: 14, weight: '600', family: 'system-ui, sans-serif' },
                borderColor: '#f97316',
                borderWidth: 1.5,
                cornerRadius: 8,
                padding: 12,
                boxPadding: 6,
                usePointStyle: true,
                callbacks: {
                    title: function(items) {
                        if (!items.length) return '';
                        const lbl = items[0].label;
                        if (isDia) return `🕒 Horário: ${lbl}`;
                        if (isMes) {
                            const partes = inputMes.value.split('-');
                            return `📅 Dia ${lbl}/${partes[1]}/${partes[0]}`;
                        }
                        if (isAno) return `📅 ${lbl}/${inputAno.value}`;
                        return `📅 Ano: ${lbl}`;
                    },
                    label: function(item) {
                        if (item.raw === null || item.raw === undefined) {
                            return 'Sem registro';
                        }
                        const valorFmt = Number(item.raw).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 3 });
                        const rotulo = isDia ? 'Potência' : 'Geração';
                        const unidade = isDia ? 'kW' : 'kWh';
                        return `☀️ ${rotulo}: ${valorFmt} ${unidade}`;
                    }
                }
            };

            // Zoom apenas habilitado no gráfico de Dia
            const zoomPlugin = {
                pan: {
                    enabled: isDia,
                    mode: 'x',
                    modifierKey: null,
                    onPanComplete: () => {
                        btnResetZoom.style.display = 'inline-flex';
                    }
                },
                zoom: {
                    wheel: { enabled: isDia, speed: 0.05 },
                    pinch: { enabled: isDia },
                    mode: 'x',
                    onZoomComplete: () => {
                        btnResetZoom.style.display = 'inline-flex';
                    }
                }
            };

            btnResetZoom.style.display = 'none';

            chart = new Chart(ctx, {
                type: tipo,
                data: {
                    labels: labels,
                    datasets: [{
                        label: isDia ? 'Potência Gerada (kW)' : 'Energia Gerada (kWh)',
                        data: dados,
                        backgroundColor: backgroundStyle,
                        borderColor: '#f97316',
                        borderWidth: tipo === 'line' ? 2.5 : 1,
                        fill: tipo === 'line',
                        tension: 0.25,
                        spanGaps: false,
                        pointRadius: tipo === 'line' ? 0 : undefined,
                        pointHitRadius: 10,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#ffffff',
                        pointHoverBorderColor: '#ea580c',
                        pointHoverBorderWidth: 2.5,
                        borderRadius: tipo === 'bar' ? 4 : 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: tooltipPlugin,
                        zoom: zoomPlugin,
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255, 255, 255, 0.06)' },
                            ticks: {
                                color: '#94a3b8',
                                callback: function(val) {
                                    return val.toLocaleString('pt-BR') + (isDia ? ' kW' : ' kWh');
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: isDia ? 'rgba(255, 255, 255, 0.04)' : 'transparent',
                                display: isDia
                            },
                            ticks: {
                                color: '#94a3b8',
                                maxRotation: 0,
                                autoSkip: isDia,
                                maxTicksLimit: isDia ? 25 : undefined,
                                callback: function(val, index) {
                                    const lbl = this.getLabelForValue(val);
                                    if (isDia) {
                                        // Destaca horários cheios 00:00, 02:00, 04:00... 23:00 e 23:55
                                        return (lbl.endsWith(':00') || lbl === '23:55') ? lbl : '';
                                    }
                                    return lbl;
                                },
                                font: {
                                    size: isMes ? 10 : (isAno ? 11 : 12)
                                }
                            }
                        }
                    }
                }
            });
        }

        // Navegação Dia
        btnDiaAnt.addEventListener('click', () => mudarDia(-1));
        btnDiaProx.addEventListener('click', () => mudarDia(1));
        btnDiaRw.addEventListener('click', () => mudarDia(-7));
        btnDiaFf.addEventListener('click', () => mudarDia(7));
        btnDiaHoje.addEventListener('click', () => {
            inputData.value = new Date().toLocaleDateString('en-CA');
            atualizarLimitesDia();
            carregarDados();
        });
        inputData.addEventListener('change', () => {
            atualizarLimitesDia();
            carregarDados();
        });

        // Navegação Mês
        btnMesAnt.addEventListener('click', () => mudarMes(-1));
        btnMesProx.addEventListener('click', () => mudarMes(1));
        btnMesAtual.addEventListener('click', () => {
            const now = new Date();
            inputMes.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            atualizarLimitesMes();
            carregarDados();
        });
        inputMes.addEventListener('change', () => {
            atualizarLimitesMes();
            carregarDados();
        });

        // Navegação Ano
        btnAnoAnt.addEventListener('click', () => mudarAno(-1));
        btnAnoProx.addEventListener('click', () => mudarAno(1));
        inputAno.addEventListener('change', () => {
            atualizarLimitesAno();
            carregarDados();
        });

        // Resetar Zoom
        btnResetZoom.addEventListener('click', () => {
            if (chart) {
                chart.resetZoom();
                btnResetZoom.style.display = 'none';
            }
        });

        // Double click no canvas para resetar zoom
        document.getElementById('graficoGeracao').addEventListener('dblclick', () => {
            if (chart) {
                chart.resetZoom();
                btnResetZoom.style.display = 'none';
            }
        });

        // Troca de Abas
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                periodoAtual = btn.dataset.periodo;
                mostrarInputCorreto(periodoAtual);
                carregarDados();
            });
        });

        // Inicialização
        mostrarInputCorreto('dia');
        carregarDados();
    </script>
</body>
</html>
