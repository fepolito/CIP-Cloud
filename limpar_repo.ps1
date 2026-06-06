# ============================================================
# Script: limpar_repo.ps1
# Projeto: Controlador de Injecao de Potencia Eletrica (CIP)
# Versao: 1.0.2 (abandona here-strings - usa array de linhas)
# ============================================================

$ErrorActionPreference = "Stop"

function Write-Etapa { param([string]$msg) Write-Host "`n=== $msg ===" -ForegroundColor Cyan }
function Write-Ok    { param([string]$msg) Write-Host "  [OK] $msg" -ForegroundColor Green }
function Write-Skip  { param([string]$msg) Write-Host "  [--] $msg" -ForegroundColor DarkGray }
function Write-Warn  { param([string]$msg) Write-Host "  [!!] $msg" -ForegroundColor Yellow }

$dirEsperado = "monitor.aeonium.com.br"
if ((Get-Location).Path -notlike "*$dirEsperado*") {
    Write-Host "ERRO: rode este script DENTRO de $dirEsperado" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "############################################################" -ForegroundColor Magenta
Write-Host "#  LIMPEZA DO REPOSITORIO CIP CLOUD - v1.0.2                #" -ForegroundColor Magenta
Write-Host "#  Diretorio: $(Get-Location)" -ForegroundColor Magenta
Write-Host "############################################################" -ForegroundColor Magenta

# ============================================================
# ETAPA 1 - Criar pasta tools/ e README
# ============================================================
Write-Etapa "ETAPA 1/6 - Criar pasta tools/ e README"

if (-not (Test-Path "tools")) {
    New-Item -ItemType Directory -Path "tools" | Out-Null
    Write-Ok "Pasta tools/ criada"
} else {
    Write-Skip "Pasta tools/ ja existe"
}

# README como array de linhas (zero ambiguidade pro parser)
$readmeTools = @(
    "# tools/",
    "",
    "Scripts de diagnostico e teste do projeto CIP Cloud.",
    "",
    "**NAO** sao usados em producao. Servem para debug local, validacao",
    "de helpers e smoke tests apos deploy.",
    "",
    "## Inventario",
    "",
    "| Arquivo | Funcao |",
    "|---------|--------|",
    "| ``teste_tenant_carga.php``  | Valida carga da classe App\Helpers\Tenant (autoload manual) |",
    "| ``teste_tenant_path.php``   | Valida path do arquivo Tenant.php (case-sensitivity Windows vs Linux) |",
    "| ``teste_tenant_listar.php`` | Smoke test do metodo Tenant::listarControladores() retornando JSON |",
    "",
    "## Como usar",
    "",
    "Acesse via browser autenticado:",
    "",
    "- https://monitor.aeonium.com.br/tools/teste_tenant_carga.php",
    "- https://monitor.aeonium.com.br/tools/teste_tenant_path.php",
    "- https://monitor.aeonium.com.br/tools/teste_tenant_listar.php",
    "",
    "## Seguranca",
    "",
    "> **ATENCAO:** estes scripts expoem dados internos.",
    "> Em producao, proteger via .htaccess ou middleware de papel_global=master."
)

Set-Content -Path "tools\README.md" -Value $readmeTools -Encoding UTF8
Write-Ok "tools/README.md criado"

# ============================================================
# ETAPA 2 - Mover e renomear testes do Tenant
# ============================================================
Write-Etapa "ETAPA 2/6 - Mover testes do Tenant para tools/"

$movimentacoes = @(
    @{ De = "teste_tenant.php";                Para = "tools\teste_tenant_carga.php" },
    @{ De = "teste_tenant_2.php";              Para = "tools\teste_tenant_path.php" },
    @{ De = "_teste_listar_controladores.php"; Para = "tools\teste_tenant_listar.php" }
)

foreach ($mov in $movimentacoes) {
    if (Test-Path $mov.De) {
        Move-Item -Path $mov.De -Destination $mov.Para
        Write-Ok "$($mov.De) -> $($mov.Para)"
    } else {
        Write-Skip "$($mov.De) nao encontrado"
    }
}

# ============================================================
# ETAPA 3 - Criar config/sync.example.php
# ============================================================
Write-Etapa "ETAPA 3/6 - Criar config/sync.example.php"

if (-not (Test-Path "config")) {
    New-Item -ItemType Directory -Path "config" | Out-Null
    Write-Ok "Pasta config/ criada"
}

# PHP como array de linhas - imune ao parser do PowerShell
$syncExample = @(
    '<?php',
    '/**',
    ' * Arquivo: config/sync.example.php',
    ' * Projeto: Controlador de Injecao de Potencia Eletrica',
    ' * Objetivo: Template de configuracao de sincronizacao prod -> teste.',
    ' *',
    ' * COMO USAR:',
    ' *   1. Copie este arquivo para config/sync.php',
    ' *   2. Gere um token forte com: php -r "echo bin2hex(random_bytes(32));"',
    ' *   3. Cole o token gerado no campo token',
    ' *   4. Ajuste tabelas_permitidas conforme necessidade do ambiente',
    ' *',
    ' * ATENCAO:',
    ' *   - config/sync.php contem credenciais sensiveis e esta no .gitignore',
    ' *   - NUNCA commite o arquivo real no Git',
    ' *',
    ' * @versao 1.0.0',
    ' * @criado_em 2026-06-05',
    ' */',
    'declare(strict_types=1);',
    '',
    'return [',
    '    // Token forte de 64 chars - gere com: bin2hex(random_bytes(32))',
    "    'token' => 'SUBSTITUA_POR_TOKEN_DE_64_CHARS_GERADO_LOCALMENTE',",
    '',
    '    // Tabelas autorizadas a exportar (whitelist de seguranca)',
    "    'tabelas_permitidas' => [",
    "        'telemetria_5min',",
    "        'controladores',",
    "        'usuarios',",
    '    ],',
    '',
    '    // Limite de registros por request (paginacao)',
    "    'limite_por_request' => 5000,",
    '',
    '    // IPs autorizados (opcional - vazio = permite qualquer IP com token valido)',
    "    'ips_permitidos' => [",
    "        // '189.xxx.xxx.xxx',",
    '    ],',
    '',
    '    // Colunas a anonimizar/remover por tabela (LGPD + seguranca)',
    "    'colunas_anonimizar' => [",
    "        'usuarios' => [",
    '            // senha real vira hash fixo (senha de teste padrao)',
    "            'senha' => 'GERAR_HASH_BCRYPT_DE_SENHA_TESTE_PADRAO',",
    "            'email' => '__ANONIMIZAR_EMAIL__',",
    '        ],',
    "        'controladores' => [",
    '            // adicione colunas de token/chave aqui se existirem',
    '        ],',
    '    ],',
    '];'
)

Set-Content -Path "config\sync.example.php" -Value $syncExample -Encoding UTF8
Write-Ok "config/sync.example.php criado"

# ============================================================
# ETAPA 4 - Apagar arquivos de lixo confirmado
# ============================================================
Write-Etapa "ETAPA 4/6 - Apagar arquivos de lixo confirmado"

$paraApagar = @(
    "_diag_mysql.php",
    "_phpinfo.php",
    "_teste_config.php",
    "_versao.php",
    "scratch.php",
    "where.php",
    "debug_session.php",
    "debug_session.md",
    "gerar_hash.php",
    "install.php",
    "teste-mes.php",
    "gitignore",
    "ht",
    "error_log"
)

foreach ($item in $paraApagar) {
    if (Test-Path $item) {
        Remove-Item -Path $item -Force
        Write-Ok "Apagado: $item"
    } else {
        Write-Skip "Nao encontrado: $item"
    }
}

if (Test-Path "public") {
    Remove-Item -Path "public" -Recurse -Force
    Write-Ok "Apagada: public/ (recursivo)"
} else {
    Write-Skip "Nao encontrado: public/"
}

# ============================================================
# ETAPA 5 - Atualizar .gitignore (sem duplicar)
# ============================================================
Write-Etapa "ETAPA 5/6 - Atualizar .gitignore"

if (-not (Test-Path ".gitignore")) {
    Write-Warn ".gitignore nao existe - criando do zero"
    Set-Content -Path ".gitignore" -Value "" -Encoding UTF8
}

$conteudoAtual = Get-Content ".gitignore" -Raw
if ($null -eq $conteudoAtual) { $conteudoAtual = "" }

$novasRegras = @(
    @{ Regra = "/config/sync.php";  Comentario = "# Config de sync prod->teste (contem token sensivel)" },
    @{ Regra = "php.ini";           Comentario = "# Config local do PHP (Laragon)" },
    @{ Regra = ".user.ini";         Comentario = "# Config local do PHP por diretorio" },
    @{ Regra = "/_backups/";        Comentario = "# Backups locais (nao versionar)" },
    @{ Regra = "/uploads/";         Comentario = "# Uploads de usuarios (dados de cliente)" },
    @{ Regra = "/storage/";         Comentario = "# Storage de runtime (cache, sessoes, etc)" },
    @{ Regra = "*.log";             Comentario = "# Logs de aplicacao" }
)

$linhasNovas = @()
$linhasNovas += ""
$linhasNovas += "# === Adicionado em 2026-06-05 (limpeza inicial) ==="
$adicionou = $false

foreach ($r in $novasRegras) {
    if ($conteudoAtual -notmatch [regex]::Escape($r.Regra)) {
        $linhasNovas += $r.Comentario
        $linhasNovas += $r.Regra
        $linhasNovas += ""
        Write-Ok "Adicionado ao .gitignore: $($r.Regra)"
        $adicionou = $true
    } else {
        Write-Skip "Ja existe no .gitignore: $($r.Regra)"
    }
}

if ($adicionou) {
    Add-Content -Path ".gitignore" -Value $linhasNovas -Encoding UTF8
    Write-Ok ".gitignore atualizado"
} else {
    Write-Skip "Nada novo para adicionar ao .gitignore"
}

# ============================================================
# ETAPA 6 - Mostrar git status final
# ============================================================
Write-Etapa "ETAPA 6/6 - Status final do Git"

Write-Host ""
Write-Host "############################################################" -ForegroundColor Magenta
Write-Host "#  RESULTADO - git status --short                           #" -ForegroundColor Magenta
Write-Host "############################################################" -ForegroundColor Magenta
Write-Host ""

git status --short

Write-Host ""
Write-Host "############################################################" -ForegroundColor Green
Write-Host "#  LIMPEZA CONCLUIDA                                        #" -ForegroundColor Green
Write-Host "############################################################" -ForegroundColor Green
Write-Host ""
Write-Host "PROXIMOS PASSOS:" -ForegroundColor Yellow
Write-Host "  1. Revise o git status acima"
Write-Host "  2. Se OK:  git add ."
Write-Host "  3. Confira: git status"
Write-Host "  4. Commit: git commit -m 'chore: limpeza inicial e organizacao do repositorio'"
Write-Host "  5. Push:   git push origin master"
Write-Host ""
