<?php
session_start();
require_once 'model_user.php';
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $user = user_buscar_por_login($pdo, $usuario);
    if ($user && password_verify($senha, $user['senha'])) {
        $_SESSION['admin_blog'] = true;
        $_SESSION['admin_nome'] = $user['nome'];
        $_SESSION['admin_id'] = $user['id'];
        header('Location: index.php');
        exit;
    } else {
        $erro = 'Usuário ou senha incorretos!';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Login - Painel Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; }
        .login-box { max-width: 350px; margin: 80px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.07); padding: 32px 24px; }
        .login-logo { display: block; margin: 0 auto 24px auto; width: 120px; }
    </style>
</head>
<body>
<div class="login-box">
    <img src="../img/logo.png" alt="Logo Arlete Vieira Confeitaria" class="login-logo">
    <h3 class="mb-4 text-center">Painel Admin</h3>
    <?php if ($erro): ?>
        <div class="alert alert-danger py-2 text-center"><?= $erro ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <label class="form-label">Usuário</label>
            <input type="text" name="usuario" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
            <label class="form-label">Senha</label>
            <input type="password" name="senha" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Entrar</button>
    </form>
</div>
</body>
</html> 