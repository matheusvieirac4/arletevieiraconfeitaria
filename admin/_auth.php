<?php
// Guarda de autenticação do painel. Inclua no topo de toda página/controller
// do admin ANTES de qualquer saída HTML.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}
