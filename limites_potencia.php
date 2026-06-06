<?php
declare(strict_types=1);

/**
 * Arquivo: limites_potencia.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Disponibilizar a página de limites de potência para testes de navegação
 * e futura parametrização operacional, com carregamento explícito dos recursos visuais do sistema.
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
 * - 2026-03-25 20:22: criação da página base de limites de potência
 * - 2026-03-27 13:46: adequação estrutural com carregamento explícito de CSS e JavaScript
 */

session_start();

$appTituloPagina = 'Limites de Potência';
$appSubtituloPagina = 'Parametrização dos limites operacionais';
$appPaginaAtual = 'limites_potencia';
$appIsAdmin = (bool) ($_SESSION['usuario_admin'] ?? false);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Limites de Potência - Controlador de Injeção de Potência Elétrica</title>

    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/header.css">
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content">
        <section class="page-card">
            <h2>Limites de Potência</h2>
            <p>Área destinada à configuração dos limites máximos, mínimos e regras de atuação do controlador.</p>
        </section>
    </main>

    <script src="/assets/js/app-shell.js"></script>
</body>
</html>
