<?php
session_start();
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}
require_once 'model.php';
$posts = blog_listar_posts($pdo);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Admin Blog - Lista de Posts</title>
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
        <a href="index.php" class="nav-link active">Blog</a>
        <a href="usuarios.php" class="nav-link">Usuários</a>
        <a href="cardapios.php" class="nav-link">Cardápios</a>
        <a href="links_bio.php" class="nav-link">Links da bio</a>
        <a href="metricas.php" class="nav-link">Métricas</a>
        <a href="logout.php" class="nav-link text-danger">Sair</a>
    </nav>
</div>
<div class="admin-main">
    <div class="container-fluid px-0">
        <h1 class="mb-4">Posts do Blog</h1>
        <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#modalNovoPost">Novo Post</button>
        <table class="table table-bordered table-striped bg-white">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Título</th>
                    <th>Categoria</th>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td><?= htmlspecialchars($post['id']) ?></td>
                    <td><?= htmlspecialchars($post['titulo']) ?></td>
                    <td><?= htmlspecialchars($post['categoria']) ?></td>
                    <td><?= htmlspecialchars($post['criado_em']) ?></td>
                    <td>
                        <button class="btn btn-primary btn-sm btn-editar" 
                            data-id="<?= $post['id'] ?>"
                            data-titulo="<?= htmlspecialchars($post['titulo'], ENT_QUOTES) ?>"
                            data-conteudo="<?= htmlspecialchars($post['conteudo'], ENT_QUOTES) ?>"
                            data-conteudo_resumido="<?= htmlspecialchars($post['conteudo_resumido'], ENT_QUOTES) ?>"
                            data-conteudo_dois="<?= htmlspecialchars($post['conteudo_dois'], ENT_QUOTES) ?>"
                            data-conteudo_tres="<?= htmlspecialchars($post['conteudo_tres'], ENT_QUOTES) ?>"
                            data-conteudo_quatro="<?= htmlspecialchars($post['conteudo_quatro'], ENT_QUOTES) ?>"
                            data-categoria="<?= htmlspecialchars($post['categoria'], ENT_QUOTES) ?>"
                            data-imagem="<?= htmlspecialchars($post['imagem'], ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalEditarPost">
                            Editar
                        </button>
                        <a href="controller.php?acao=deletar&id=<?= $post['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Modal Novo Post -->
<div class="modal fade" id="modalNovoPost" tabindex="-1" aria-labelledby="modalNovoPostLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalNovoPostLabel">Novo Post</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller.php?acao=inserir" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Título</label>
            <input type="text" name="titulo" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo</label>
            <textarea name="conteudo" class="form-control" rows="6" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo Resumido</label>
            <textarea name="conteudo_resumido" class="form-control" rows="2" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo 2</label>
            <textarea name="conteudo_dois" class="form-control" rows="4"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo 3</label>
            <textarea name="conteudo_tres" class="form-control" rows="4"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo 4</label>
            <textarea name="conteudo_quatro" class="form-control" rows="4"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Categoria</label>
            <input type="text" name="categoria" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Imagem (upload)</label>
            <input type="file" name="imagem" class="form-control">
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
<!-- Modal Editar Post -->
<div class="modal fade" id="modalEditarPost" tabindex="-1" aria-labelledby="modalEditarPostLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalEditarPostLabel">Editar Post</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller.php?acao=editar" enctype="multipart/form-data" id="formEditarPost">
        <input type="hidden" name="id" id="edit-id">
        <input type="hidden" name="imagem_atual" id="edit-imagem-atual">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Título</label>
            <input type="text" name="titulo" id="edit-titulo" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo</label>
            <textarea name="conteudo" id="edit-conteudo" class="form-control" rows="6" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo Resumido</label>
            <textarea name="conteudo_resumido" id="edit-conteudo-resumido" class="form-control" rows="2" required></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo 2</label>
            <textarea name="conteudo_dois" id="edit-conteudo-dois" class="form-control" rows="4"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo 3</label>
            <textarea name="conteudo_tres" id="edit-conteudo-tres" class="form-control" rows="4"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Conteúdo 4</label>
            <textarea name="conteudo_quatro" id="edit-conteudo-quatro" class="form-control" rows="4"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Categoria</label>
            <input type="text" name="categoria" id="edit-categoria" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Imagem (upload)</label>
            <input type="file" name="imagem" class="form-control">
            <div id="edit-imagem-preview" class="mt-2"></div>
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
// Preenche o modal de edição com os dados do post
const btnsEditar = document.querySelectorAll('.btn-editar');
btnsEditar.forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('formEditarPost').action = 'controller.php?acao=editar&id=' + this.dataset.id;
    document.getElementById('edit-id').value = this.dataset.id;
    document.getElementById('edit-titulo').value = this.dataset.titulo;
    document.getElementById('edit-conteudo').value = this.dataset.conteudo;
    document.getElementById('edit-conteudo-resumido').value = this.dataset.conteudo_resumido;
    document.getElementById('edit-conteudo-dois').value = this.dataset.conteudo_dois;
    document.getElementById('edit-conteudo-tres').value = this.dataset.conteudo_tres;
    document.getElementById('edit-conteudo-quatro').value = this.dataset.conteudo_quatro;
    document.getElementById('edit-categoria').value = this.dataset.categoria;
    document.getElementById('edit-imagem-atual').value = this.dataset.imagem;
    // Preview da imagem atual
    let preview = '';
    if (this.dataset.imagem) {
      preview = `<img src="../img/imagens/blog/${this.dataset.imagem}" alt="Imagem atual" style="max-width:120px;">`;
    }
    document.getElementById('edit-imagem-preview').innerHTML = preview;
  });
});
</script>
</body>
</html> 