<?php
session_start();
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'model_user.php';
$usuarios = user_listar($pdo);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Admin Blog - Usuários</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; }
        .admin-sidebar {
            width: 220px;
            min-height: 100vh;
            background: #23272b;
            color: #fff;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 32px;
            z-index: 100;
        }
        .admin-sidebar .logo {
            width: 120px;
            margin-bottom: 32px;
        }
        .admin-sidebar .nav {
            width: 100%;
        }
        .admin-sidebar .nav-link {
            color: #fff;
            font-weight: 500;
            padding: 12px 24px;
            border-radius: 6px;
            margin-bottom: 8px;
            transition: background 0.15s;
        }
        .admin-sidebar .nav-link.active, .admin-sidebar .nav-link:hover {
            background: #a51d32;
            color: #fff;
        }
        .admin-main {
            margin-left: 220px;
            padding: 32px 24px 24px 24px;
        }
        @media (max-width: 767px) {
            .admin-sidebar { position: static; width: 100vw; min-height: unset; flex-direction: row; justify-content: flex-start; padding: 12px 0; }
            .admin-sidebar .logo { width: 80px; margin-bottom: 0; margin-right: 16px; }
            .admin-sidebar .nav { flex-direction: row; width: auto; }
            .admin-sidebar .nav-link { padding: 8px 12px; margin-bottom: 0; margin-right: 8px; }
            .admin-main { margin-left: 0; padding: 16px 8px; }
        }
    </style>
</head>
<body>
<div class="admin-sidebar">
    <img src="../img/logo.png" alt="Logo Arlete Vieira Confeitaria" class="logo">
    <nav class="nav flex-column mt-2">
        <a href="index.php" class="nav-link">Blog</a>
        <a href="usuarios.php" class="nav-link active">Usuários</a>
        <a href="cardapios.php" class="nav-link">Cardápios</a>
        <a href="links_bio.php" class="nav-link">Links da bio</a>
        <a href="metricas.php" class="nav-link">Métricas</a>
        <a href="logout.php" class="nav-link text-danger">Sair</a>
    </nav>
</div>
<div class="admin-main">
    <div class="container-fluid px-0">
        <h1 class="mb-4">Usuários do Admin</h1>
        <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#modalNovoUsuario">Novo Usuário</button>
        <table class="table table-bordered table-striped bg-white">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Login</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['id']) ?></td>
                    <td><?= htmlspecialchars($user['nome']) ?></td>
                    <td><?= htmlspecialchars($user['login']) ?></td>
                    <td>
                        <button class="btn btn-primary btn-sm btn-editar-user"
                            data-id="<?= $user['id'] ?>"
                            data-nome="<?= htmlspecialchars($user['nome'], ENT_QUOTES) ?>"
                            data-login="<?= htmlspecialchars($user['login'], ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalEditarUsuario">
                            Editar
                        </button>
                        <a href="controller_user.php?acao=deletar&id=<?= $user['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Modal Novo Usuário -->
<div class="modal fade" id="modalNovoUsuario" tabindex="-1" aria-labelledby="modalNovoUsuarioLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalNovoUsuarioLabel">Novo Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller_user.php?acao=inserir">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nome</label>
            <input type="text" name="nome" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Login</label>
            <input type="text" name="login" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Senha</label>
            <input type="password" name="senha" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Salvar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- Modal Editar Usuário -->
<div class="modal fade" id="modalEditarUsuario" tabindex="-1" aria-labelledby="modalEditarUsuarioLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalEditarUsuarioLabel">Editar Usuário</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller_user.php?acao=editar" id="formEditarUsuario">
        <input type="hidden" name="id" id="edit-user-id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nome</label>
            <input type="text" name="nome" id="edit-user-nome" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Login</label>
            <input type="text" name="login" id="edit-user-login" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nova Senha (deixe em branco para não alterar)</label>
            <input type="password" name="senha" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Salvar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Preenche o modal de edição com os dados do usuário
const btnsEditarUser = document.querySelectorAll('.btn-editar-user');
btnsEditarUser.forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('formEditarUsuario').action = 'controller_user.php?acao=editar&id=' + this.dataset.id;
    document.getElementById('edit-user-id').value = this.dataset.id;
    document.getElementById('edit-user-nome').value = this.dataset.nome;
    document.getElementById('edit-user-login').value = this.dataset.login;
  });
});
</script>
</body>
</html> 