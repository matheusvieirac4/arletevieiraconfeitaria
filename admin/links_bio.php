<?php
require_once __DIR__ . '/_auth.php';
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

$page_title = 'Links da Bio';
$active = 'links_bio';
$extra_css = '
        .url-box { font-size: 0.82rem; word-break: break-all; }';
require __DIR__ . '/_header.php';
?>
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
<?php require __DIR__ . '/_footer.php'; ?>
