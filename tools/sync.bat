@echo off
REM ============================================================
REM CIP Cloud — Sincronizador local (Laragon)
REM Uso: sync.bat                  -> incremental, todas tabelas
REM      sync.bat --tabela=usuarios
REM      sync.bat --reset
REM      sync.bat --dry-run
REM ============================================================

cd /d "%~dp0"

REM Ajuste o caminho do PHP do Laragon se necessario
set PHP_BIN=C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe

if not exist "%PHP_BIN%" (
    echo [ERRO] PHP nao encontrado em %PHP_BIN%
    echo Ajuste a variavel PHP_BIN no inicio do sync.bat
    pause
    exit /b 1
)

"%PHP_BIN%" sync_puxar.php %*

Rem echo.
rem pause
