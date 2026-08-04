/**
 * Arquivo: assets/js/upload-logo.js
 * Projeto: Controlador de Injeção de Potência Elétrica
 * Objetivo: Submeter automaticamente o formulário de upload de logomarca
 *           após a seleção do arquivo pelo usuário.
 *
 * Dependências de hardware:
 * - Navegador moderno com suporte a JavaScript
 *
 * Dependências de software/arquivos instalados:
 * - empresas.php
 *
 * Histórico de implementações:
 * - 2026-04-02: criação do handler de upload automático de logomarca
 */

(function () {
    function initUploadLogo() {
        const inputLogo = document.getElementById('logo_file');

        if (!inputLogo) {
            return;
        }

        inputLogo.addEventListener('change', function () {
            if (this.files && this.files.length > 0) {
                const form = this.closest('form');

                if (form) {
                    form.submit();
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUploadLogo);
    } else {
        initUploadLogo();
    }
})();
