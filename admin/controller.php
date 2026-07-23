<?php
require_once 'model.php';

$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (($acao === 'inserir' || $acao === 'editar') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomeArquivo = isset($_POST['imagem_atual']) ? $_POST['imagem_atual'] : null;
    if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION);
        $nomeArquivo = uniqid('blog_') . '.' . $ext;
        move_uploaded_file($_FILES['imagem']['tmp_name'], __DIR__ . '/../img/imagens/blog/' . $nomeArquivo);
    }
    $dados = $_POST;
    $dados['imagem'] = $nomeArquivo;
    // Garante que os campos extras existam
    foreach(['conteudo_dois','conteudo_tres','conteudo_quatro'] as $campo) {
        if (!isset($dados[$campo])) $dados[$campo] = '';
    }
    if ($acao === 'inserir') {
        blog_inserir_post($pdo, $dados);
    } elseif ($acao === 'editar' && $id) {
        blog_atualizar_post($pdo, $id, $dados);
    }
    header('Location: index.php');
    exit;
}
if ($acao === 'deletar' && $id) {
    blog_deletar_post($pdo, $id);
    header('Location: index.php');
    exit;
} 