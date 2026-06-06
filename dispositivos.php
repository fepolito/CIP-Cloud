<?php
declare(strict_types=1);

/**
 * Arquivo: dispositivos.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Disponibilizar a página de cadastro de dispositivos para testes de navegação
 * no menu de ferramentas, com carregamento explícito dos recursos visuais e comportamentais do sistema.
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
 * - 2026-03-25 20:22: criação da página base de dispositivos
 * - 2026-03-27 13:42: adequação estrutural com head completo e carregamento explícito de CSS/JS
 */

session_start();

$appTituloPagina = 'Dispositivos';
$appSubtituloPagina = 'Cadastro e gestão de dispositivos';
$appPaginaAtual = 'dispositivos';
$appIsAdmin = (bool) ($_SESSION['usuario_admin'] ?? false);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispositivos - Controlador de Injeção de Potência Elétrica</title>

    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/header.css">
</head>
<body>
    <?php require __DIR__ . '/includes/app_header.php'; ?>

    <main class="app-content">
        <section class="page-card">
            <h2>Dispositivos</h2>
            <p>Página destinada ao cadastro, associação e manutenção dos dispositivos monitorados/controlados.</p>
        </section>
    </main>

    <script src="/assets/js/app-shell.js"></script>
</body>
</html>
