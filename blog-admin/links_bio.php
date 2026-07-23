<?php
session_start();
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../includes/links_bio.php';

$links = links_bio_listar();
usort($links, function ($a, $b) {
    $oa = isset($a['ordem']) ? (int) $a['ordem'] : 0;
    $ob = isset($b['ordem']) ? (int) $b['ordem'] : 0;
    return $oa < $ob ? -1 : ($oa > $ob ? 1 : 0);
});

$flash = null;
if (isset($_SESSION['links_bio_flash'])) {
    $flash = $_SESSION['links_bio_flash'];
    unset($_SESSION['links_bio_flash']);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Admin - Links da Bio</title>
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
        .admin-sidebar .logo { width: 120px; margin-bottom: 32px; }
        .admin-sidebar .nav { width: 100%; }
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
        .admin-main { margin-left: 220px; padding: 32px 24px 24px 24px; }
        .url-box { font-size: 0.82rem; word-break: break-all; }
        @media (max-width: 767px) {
            .admin-sidebar { position: static; width: 100vw; min-height: unset; flex-direction: row; justify-content: flex-start; padding: 12px 0; }
            .admin-sidebar .logo { width: 80px; margin-bottom: 0; margin-right: 16px; }
            .admin-sidebar .nav { flex-direction: row; width: auto; flex-wrap: wrap; }
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
        <a href="usuarios.php" class="nav-link">Usuarios</a>
        <a href="cardapios.php" class="nav-link">Cardapios</a>
        <a href="links_bio.php" class="nav-link active">Links da bio</a>
        <a href="metricas.php" class="nav-link">Metricas</a>
        <a href="logout.php" class="nav-link text-danger">Sair</a>
    </nav>
</div>
<div class="admin-main">
    <div class="container-fluid px-0">
        <h1 class="mb-2">Links da bio</h1>
        <p class="text-muted mb-4">
            Altere o destino e o texto dos botoes de
            <a href="https://links.arletevieiraconfeitaria.com.br/" target="_blank" rel="noopener noreferrer">links.arletevieiraconfeitaria.com.br</a>.
            O identificador (<code>slug</code>) nao muda — as metricas de cliques continuam funcionando.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#modalNovoLink">Novo link</button>

        <table class="table table-bordered table-striped bg-white">
            <thead>
                <tr>
                    <th>Ordem</th>
                    <th>Botao</th>
                    <th>Identificador</th>
                    <th>URL de destino</th>
                    <th>Status</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $item):
                $slug = isset($item['slug']) ? $item['slug'] : '';
                $clickUrl = 'https://links.arletevieiraconfeitaria.com.br/click.php?l=' . rawurlencode($slug) . '&s=bio';
            ?>
                <tr>
                    <td><?= (int) (isset($item['ordem']) ? $item['ordem'] : 0) ?></td>
                    <td><?= htmlspecialchars(isset($item['rotulo']) ? $item['rotulo'] : '') ?></td>
                    <td><code><?= htmlspecialchars($slug) ?></code></td>
                    <td class="url-box"><?= htmlspecialchars(isset($item['url']) ? $item['url'] : '') ?></td>
                    <td>
                        <?php if (!empty($item['ativo'])): ?>
                            <span class="badge bg-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Oculto</span>
                        <?php endif; ?>
                        <div class="small text-muted mt-1"><?= (isset($item['estilo']) && $item['estilo'] === 'primary') ? 'Destaque' : 'Secundario' ?></div>
                    </td>
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-primary btn-sm btn-editar-link"
                            data-slug="<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
                            data-rotulo="<?= htmlspecialchars(isset($item['rotulo']) ? $item['rotulo'] : '', ENT_QUOTES) ?>"
                            data-url="<?= htmlspecialchars(isset($item['url']) ? $item['url'] : '', ENT_QUOTES) ?>"
                            data-estilo="<?= htmlspecialchars(isset($item['estilo']) ? $item['estilo'] : 'secondary', ENT_QUOTES) ?>"
                            data-ordem="<?= (int) (isset($item['ordem']) ? $item['ordem'] : 0) ?>"
                            data-ativo="<?= !empty($item['ativo']) ? '1' : '0' ?>"
                            data-click="<?= htmlspecialchars($clickUrl, ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalEditarLink">
                            Editar
                        </button>
                        <a href="controller_links_bio.php?acao=excluir&slug=<?= urlencode($slug) ?>"
                           class="btn btn-danger btn-sm"
                           onclick="return confirm('Excluir este link da pagina da bio?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalNovoLink" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Novo link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller_links_bio.php?acao=inserir">
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Identificador (fixo, para metricas)</label>
              <input type="text" name="slug_novo" class="form-control" placeholder="cardapio_bolos" required pattern="[a-z0-9][a-z0-9_-]*">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Ordem na pagina</label>
              <input type="number" name="ordem" class="form-control" value="99" min="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Texto do botao</label>
            <input type="text" name="rotulo" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">URL de destino</label>
            <input type="url" name="url" class="form-control" placeholder="https://..." required>
          </div>
          <div class="mb-3">
            <label class="form-label">Estilo</label>
            <select name="estilo" class="form-select">
              <option value="secondary">Secundario (branco)</option>
              <option value="primary">Destaque (vermelho)</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Adicionar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalEditarLink" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="" id="formEditarLink">
        <input type="hidden" name="slug" id="edit-slug-hidden">
        <div class="modal-body">
          <p class="text-muted small">Identificador: <code id="edit-slug-preview"></code></p>
          <p class="text-muted small">Link rastreado: <span id="edit-click-preview" class="url-box"></span></p>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Texto do botao</label>
              <input type="text" name="rotulo" id="edit-rotulo" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Ordem</label>
              <input type="number" name="ordem" id="edit-ordem" class="form-control" min="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">URL de destino</label>
            <input type="url" name="url" id="edit-url" class="form-control" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Estilo</label>
              <select name="estilo" id="edit-estilo" class="form-select">
                <option value="secondary">Secundario</option>
                <option value="primary">Destaque</option>
              </select>
            </div>
            <div class="col-md-6 mb-3 d-flex align-items-end">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="ativo" id="edit-ativo" value="1">
                <label class="form-check-label" for="edit-ativo">Exibir na pagina da bio</label>
              </div>
            </div>
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
document.querySelectorAll('.btn-editar-link').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var slug = this.dataset.slug;
    document.getElementById('formEditarLink').action = 'controller_links_bio.php?acao=editar&slug=' + encodeURIComponent(slug);
    document.getElementById('edit-slug-hidden').value = slug;
    document.getElementById('edit-slug-preview').textContent = slug;
    document.getElementById('edit-click-preview').textContent = this.dataset.click;
    document.getElementById('edit-rotulo').value = this.dataset.rotulo;
    document.getElementById('edit-url').value = this.dataset.url;
    document.getElementById('edit-estilo').value = this.dataset.estilo;
    document.getElementById('edit-ordem').value = this.dataset.ordem;
    document.getElementById('edit-ativo').checked = this.dataset.ativo === '1';
  });
});
</script>
</body>
</html>
