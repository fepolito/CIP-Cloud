<?php
/**
 * Projeto   : CIP - Controlador de Injecao de Potencia Eletrica
 * Arquivo   : includes/app_sidebar.php
 * Objetivo  : Renderizar o menu lateral global da aplicacao com estrutura
 *             compativel com o controlador JavaScript do shell visual.
 * Dependencias de hardware:
 *   - Servidor com suporte a PHP
 *   - Navegador com suporte a HTML5, CSS3 e JavaScript
 * Dependencias de arquivos:
 *   - PHP 7.4+
 *   - includes/app_header.php
 *   - assets/css/header.css
 *   - assets/js/app-shell.js
 * Historico de implementacoes:
 *   - 2026-03-24  v1.0  Criacao do menu lateral global
 *   - 2026-03-25  v1.1  Padronizacao dos identificadores JS
 *   - 2026-03-27  v1.2  Revisao estrutural para compatibilidade mobile
 *   - 2026-04-10  v1.3  Adicao do item "Energia" no menu lateral
 */

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('appUrl')) {
    function appUrl(string $path): string
    {
        return $path;
    }
}

$appPaginaAtual = $appPaginaAtual ?? 'inicio';

$sidebarItems = [
    [
        'label' => 'Início',
        'url'   => '/index.php',
        'key'   => 'inicio',
        'icon'  => '🏠',
    ],
    [
        'label' => 'Energia',
        'url'   => '/energia.php',
        'key'   => 'energia',
        'icon'  => '⚡',
    ],
    [
        'label' => 'Geração',
        'url'   => '/geracao.php',
        'key'   => 'geracao',
        'icon'  => '☀️',
    ],
    [
        'label' => 'Histórico',
        'url'   => '/historico.php',
        'key'   => 'historico',
        'icon'  => '📋',
    ],
    [
        'label' => 'Tarifas',
        'url'   => '/tarifas.php',
        'key'   => 'tarifas',
        'icon'  => '💰',
    ],
    [
        'label' => 'Limites de Potência',
        'url'   => '/limites.php',
        'key'   => 'limites',
        'icon'  => '📊',
    ],
];
?>

<aside
    class="app-sidebar"
    id="app-sidebar"
    aria-hidden="true"
>
    <div class="app-sidebar__header">
        <strong>Navegação</strong>

        <button
            type="button"
            class="app-sidebar__close"
            id="js-sidebar-close"
            aria-label="Fechar navegação"
        >
            ×
        </button>
    </div>

    <nav class="app-sidebar__nav" aria-label="Menu lateral principal">
        <?php foreach ($sidebarItems as $item): ?>
            <a
                href="<?= e(appUrl($item['url'])) ?>"
                class="app-sidebar__link<?= $appPaginaAtual === $item['key'] ? ' is-active' : '' ?>"
            >
                <span class="app-sidebar__icon" aria-hidden="true"><?= $item['icon'] ?></span>
                <?= e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<div
    class="app-sidebar-overlay"
    id="js-sidebar-overlay"
    aria-hidden="true"
></div>