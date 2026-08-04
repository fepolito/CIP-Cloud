<?php
/**
 * Arquivo: projeto_admin.php
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Centralizar informações administrativas do projeto, incluindo
 * resumo técnico, premissas, pendências e histórico de implementações.
 *
 * Dependências de hardware:
 * - Servidor com suporte a PHP 8+
 * - Estação cliente com navegador compatível com HTML5/CSS3/JavaScript
 *
 * Dependências de software/arquivos instalados:
 * - includes/app_header.php
 *
 * Histórico de implementações:
 * - 2026-03-25 20:22: criação da página administrativa do projeto
 */

session_start();

$appIsAdmin = $_SESSION['usuario_admin'] ?? false;

if (!$appIsAdmin) {
    header('Location: /dashboard.php');
    exit;
}

$appTituloPagina = 'Projeto';
$appSubtituloPagina = 'Resumo técnico e administração interna';
$appPaginaAtual = 'projeto_admin';

require __DIR__ . '/includes/app_header.php';
?>

<main class="app-content">
    <section class="page-card">
        <h2>Projeto</h2>
        <p>Área administrativa reservada para documentação interna e acompanhamento técnico.</p>
    </section>

    <section class="page-card">
        <h3>Premissas</h3>
        <ul>
            <li>Utilizar cabeçalho compartilhado em todas as páginas.</li>
            <li>Separar navegação principal de funções administrativas.</li>
            <li>Manter a documentação do projeto fora da home operacional.</li>
        </ul>
    </section>

    <section class="page-card">
        <h3>Pendências</h3>
        <ul>
            <li>Consolidar fonte única de CSS do cabeçalho.</li>
            <li>Validar comportamento dos menus em todas as rotas.</li>
            <li>Implantar conteúdo funcional em cada módulo.</li>
        </ul>
    </section>

    <section class="page-card">
        <h3>Histórico de Implementações</h3>
        <p>Registrar aqui a evolução técnica das próximas etapas do projeto.</p>
    </section>
</main>
