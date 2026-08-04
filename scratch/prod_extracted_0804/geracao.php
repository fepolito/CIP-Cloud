<?php
declare(strict_types=1);

/**
 * Arquivo: geracao.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Disponibilizar a página de geração para testes de navegação
 * e futura implementação dos indicadores energéticos, com carregamento explícito
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
 * - 2026-03-25 20:22: criação da página base de geração
 * - 2026-03-27 13:50: adequação estrutural com documento HTML completo e assets explícitos
 */

session_start();

$appTituloPagina = 'Geração';
$appSubtituloPagina = 'Monitoramento da geração de energia';
$appPaginaAtual = 'geracao';
$appIsAdmin = (bool) ($_SESSION['usuario_admin'] ?? false);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Geração - Controlador de Injeção de Potência Elétrica</title>

    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/header.css">
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content">
        <section class="page-card">
            <h2>Geração</h2>
            <p>Página reservada para apresentação dos dados de geração e injeção de potência elétrica.</p>
        </section>
    </main>

    <script src="/assets/js/app-shell.js"></script>
</body>
</html>
