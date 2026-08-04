<?php
// ============================================================
// Projeto  : CIP — Controlador de Injeção de Potência Elétrica
// Arquivo  : api/utils/gerar_hash.php
// Objetivo : Geração de hash bcrypt para cadastro inicial
// ATENÇÃO  : Apagar após uso!
// ----------------------------------------------------------
// Histórico:
//   2026-04-06  v1.0.0  Script utilitário de hash
// ============================================================

$senha = 'cip@2026';
echo password_hash($senha, PASSWORD_BCRYPT);
