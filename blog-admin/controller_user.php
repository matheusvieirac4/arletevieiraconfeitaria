<?php
require_once 'model_user.php';
$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : null;
if ($acao === 'inserir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    user_inserir($pdo, $_POST);
    header('Location: usuarios.php');
    exit;
}
if ($acao === 'editar' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    user_atualizar($pdo, $id, $_POST);
    header('Location: usuarios.php');
    exit;
}
if ($acao === 'deletar' && $id) {
    user_deletar($pdo, $id);
    header('Location: usuarios.php');
    exit;
} 