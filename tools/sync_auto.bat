@echo off
chcp 65001 >nul
REM ============================================================
REM CIP Cloud - Sincronizador automatico v1.2
REM Detecta automaticamente o PHP do Laragon
REM ============================================================

cd /d "%~dp0"

REM ── Detecta a pasta do PHP automaticamente ──────────────────
set PHP_BIN=
for /f "delims=" %%i in ('dir /B /AD C:\laragon\bin\php 2^>nul ^| findstr /B "php-"') do (
    if exist "C:\laragon\bin\php\%%i\php.exe" (
        set PHP_BIN=C:\laragon\bin\php\%%i\php.exe
    )
)

set LOCK_FILE=sync.lock
set LOG_AUTO=logs\sync_auto.log

if not exist "logs" mkdir "logs"

if "%PHP_BIN%"=="" (
    echo [%date% %time%] ERRO: Nenhuma instalacao de PHP encontrada em C:\laragon\bin\php >> "%LOG_AUTO%"
    exit /b 1
)

if not exist "%PHP_BIN%" (
    echo [%date% %time%] ERRO: PHP nao encontrado em %PHP_BIN% >> "%LOG_AUTO%"
    exit /b 1
)

REM ── Lock anti-concorrencia ──────────────────────────────────
if exist "%LOCK_FILE%" (
    echo [%date% %time%] AVISO: Sync ja em execucao. Pulando. >> "%LOG_AUTO%"
    exit /b 0
)

echo %date% %time% > "%LOCK_FILE%"
echo [%date% %time%] INICIO do sync automatico (PHP: %PHP_BIN%) >> "%LOG_AUTO%"

"%PHP_BIN%" sync_puxar.php >> "%LOG_AUTO%" 2>&1
set EXITCODE=%ERRORLEVEL%

echo [%date% %time%] FIM do sync automatico (exit=%EXITCODE%) >> "%LOG_AUTO%"
echo. >> "%LOG_AUTO%"

del "%LOCK_FILE%"
exit /b %EXITCODE%
