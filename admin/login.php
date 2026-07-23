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
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Entrar · Painel Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@adminkit/core@3/dist/css/app.css">
    <?php require __DIR__ . '/_brand.php'; ?>
</head>
<body>
<main class="d-flex w-100">
    <div class="container d-flex flex-column">
        <div class="row vh-100">
            <div class="col-sm-10 col-md-8 col-lg-6 col-xl-5 mx-auto d-table h-100">
                <div class="d-table-cell align-middle">

                    <div class="text-center mt-4">
                        <img src="../img/logo.png" alt="Arlete Vieira Confeitaria" style="max-height: 90px;">
                        <p class="lead mt-3">Painel administrativo</p>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="m-sm-4">
                                <?php if ($erro): ?>
                                    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($erro) ?></div>
                                <?php endif; ?>
                                <form method="post">
                                    <div class="mb-3">
                                        <label class="form-label">Usuário</label>
                                        <input class="form-control form-control-lg" type="text" name="usuario" placeholder="Seu usuário" required autofocus>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Senha</label>
                                        <input class="form-control form-control-lg" type="password" name="senha" placeholder="Sua senha" required>
                                    </div>
                                    <div class="text-center mt-3">
                                        <button type="submit" class="btn btn-lg btn-primary w-100">Entrar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</main>
</body>
</html>
