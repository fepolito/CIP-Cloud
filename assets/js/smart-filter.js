/**
 * @arquivo       assets/js/smart-filter.js
 * @versao        1.0.0
 * @modificado_em 2026-10-08
 * @objetivo      Filtro client-side multi-campo, insensível a acento/caixa, com
 *                debounce e contador dinâmico, reutilizável em listas extensas
 *                (controladores, empresas, usuários).
 * @autor         Fernando / CIP Cloud Copilot / ATGY
 */
function removerAcentos(texto) {
    return texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

function attachSmartFilter(inputId, itemSelector, contadorId) {
    const input = document.getElementById(inputId);
    const contador = document.getElementById(contadorId);
    if (!input) return;

    let timer = null;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            const termo = removerAcentos(input.value.trim().toLowerCase());
            const itens = document.querySelectorAll(itemSelector);
            let visiveis = 0;

            itens.forEach(function (item) {
                const base = removerAcentos((item.dataset.busca || '').toLowerCase());
                const match = termo === '' || base.includes(termo);
                item.style.display = match ? '' : 'none';
                if (match) visiveis++;
            });

            if (contador) {
                contador.textContent = termo === ''
                    ? ''
                    : `${visiveis} de ${itens.length} resultado${itens.length !== 1 ? 's' : ''}`;
            }
        }, 150);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    attachSmartFilter('filtroInstalacoes', '.controlador-card', 'filtroContador');
});
