<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_user.php';
$usuarios = user_listar($pdo);

$page_title = 'Usuários';
$active = 'usuarios';
require __DIR__ . '/_header.php';
?>
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
<?php require __DIR__ . '/_footer.php'; ?>