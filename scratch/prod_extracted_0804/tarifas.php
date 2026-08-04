<?php
declare(strict_types=1);

/**
 * Arquivo: tarifas.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Disponibilizar a página de tarifas para testes de navegação
 * e futura configuração de parâmetros tarifários, com carregamento explícito
 * dos recursos visuais e comportamentais do sistema.
 *
 * Dependências de hardware:
 * - Servidor com suporte a PHP 8+
 * - Estação cliente com navegador compatível com HTML5/CSS3/JavaScript
 *
 * Dependências de software/arquivos instalados:
 * - includes/app_header.php
 * - assets/css/app.css
 * - assets/css/header.css
 * - assets/js/app-shell.js
 *
 * Histórico de implementações:
 * - 2026-03-25 20:22: criação da página base de tarifas
 * - 2026-03-27 13:54: adequação estrutural com documento HTML completo e inclusão explícita de CSS/JS
 */

session_start();

$appTituloPagina = 'Tarifas';
$appSubtituloPagina = 'Parâmetros tarifários do sistema';
$appPaginaAtual = 'tarifas';
$appIsAdmin = (bool) ($_SESSION['usuario_admin'] ?? false);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tarifas - Controlador de Injeção de Potência Elétrica</title>

    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/header.css">
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content">
        <section class="page-card">
            <h2>Tarifas</h2>
            <p>Página preparada para futura gestão de regras tarifárias e parâmetros de custo de energia.</p>
        </section>
    </main>

    <script src="/assets/js/app-shell.js"></script>
</body>
</html>
