<?php
// Guarda de autenticação do painel. Inclua no topo de toda página/controller
// do admin ANTES de qualquer saída HTML.
require_once __DIR__ . '/_session.php';
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}
