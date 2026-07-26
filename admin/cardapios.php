<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_cardapios.php';

$cardapios = cardapios_listar();
usort($cardapios, function ($a, $b) {
    return strcmp(isset($a['nome']) ? $a['nome'] : '', isset($b['nome']) ? $b['nome'] : '');
});

$flash = null;
if (isset($_SESSION['cardapios_flash'])) {
    $flash = $_SESSION['cardapios_flash'];
    unset($_SESSION['cardapios_flash']);
}

$page_title = 'Cardápios PDF';
$active = 'cardapios';
$extra_css = '
        .url-box { font-family: monospace; font-size: 0.85rem; word-break: break-all; }';
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-2">Cardapios (links PDF)</h1>
        <p class="text-muted mb-4">
            Cadastre cada cardapio com um nome de arquivo fixo. Depois, envie ou substitua apenas o PDF — o link publico nao muda.
        </p>


        <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#modalNovoCardapio">Novo cardapio</button>

        <?php if (count($cardapios) === 0): ?>
            <div class="alert alert-info bg-white">Nenhum cardapio cadastrado. Clique em &quot;Novo cardapio&quot; para criar o primeiro link.</div>
        <?php else: ?>
        <table class="table table-bordered table-striped bg-white">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Arquivo</th>
                    <th>Link publico</th>
                    <th>Status</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cardapios as $item):
                $arquivo = isset($item['arquivo']) ? $item['arquivo'] : '';
                $info = cardapios_info_arquivo($arquivo);
                $url = cardapios_url_publica($arquivo);
            ?>
                <tr>
                    <td><?= htmlspecialchars(isset($item['nome']) ? $item['nome'] : '') ?></td>
                    <td><code><?= htmlspecialchars($arquivo) ?></code></td>
                    <td class="url-box">
                        <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($url) ?></a>
                    </td>
                    <td>
                        <?php if ($info['existe']): ?>
                            <span class="badge bg-success">PDF publicado</span>
                            <div class="small text-muted mt-1"><?= htmlspecialchars($info['atualizado_em']) ?> · <?= number_format($info['tamanho'] / 1024, 0, ',', '.') ?> KB</div>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">Aguardando PDF</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <form method="post" action="controller_cardapios.php?acao=upload" enctype="multipart/form-data" class="d-inline">
                            <input type="hidden" name="id" value="<?= htmlspecialchars(isset($item['id']) ? $item['id'] : '') ?>">
                            <input type="file" name="pdf" accept="application/pdf,.pdf" class="form-control form-control-sm d-inline-block mb-1" style="max-width: 200px;" required>
                            <button type="submit" class="btn btn-primary btn-sm">Enviar PDF</button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary btn-sm btn-editar-nome"
                            data-id="<?= htmlspecialchars(isset($item['id']) ? $item['id'] : '') ?>"
                            data-nome="<?= htmlspecialchars(isset($item['nome']) ? $item['nome'] : '', ENT_QUOTES) ?>"
                            data-arquivo="<?= htmlspecialchars($arquivo, ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalEditarNome">
                            Renomear
                        </button>
                        <a href="controller_cardapios.php?acao=excluir&id=<?= urlencode(isset($item['id']) ? $item['id'] : '') ?>"
                           class="btn btn-danger btn-sm js-confirm"
                           data-msg="Excluir este cardápio e o PDF associado? O link deixará de funcionar.">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

<div class="modal fade" id="modalNovoCardapio" tabindex="-1" aria-labelledby="modalNovoCardapioLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalNovoCardapioLabel">Novo cardapio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller_cardapios.php?acao=inserir">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nome (exibicao)</label>
            <input type="text" name="nome" class="form-control" placeholder="Cardapio completo" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Nome do arquivo (fixo no link)</label>
            <input type="text" name="arquivo" class="form-control" placeholder="av_completo.pdf" required pattern="[a-zA-Z0-9][a-zA-Z0-9_-]*(\.pdf)?">
            <div class="form-text">So letras, numeros, _ e -. O link sera: /cardapios/<strong>seu-arquivo.pdf</strong></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Cadastrar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalEditarNome" tabindex="-1" aria-labelledby="modalEditarNomeLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalEditarNomeLabel">Renomear cardapio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller_cardapios.php?acao=editar" id="formEditarNome">
        <input type="hidden" name="id" id="edit-id">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nome (exibicao)</label>
            <input type="text" name="nome" id="edit-nome" class="form-control" required>
          </div>
          <p class="text-muted small mb-0">Arquivo: <code id="edit-arquivo-preview"></code> (nao altera o link publico)</p>
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
document.querySelectorAll('.btn-editar-nome').forEach(function(btn) {
  btn.addEventListener('click', function() {
    document.getElementById('formEditarNome').action = 'controller_cardapios.php?acao=editar&id=' + encodeURIComponent(this.dataset.id);
    document.getElementById('edit-id').value = this.dataset.id;
    document.getElementById('edit-nome').value = this.dataset.nome;
    document.getElementById('edit-arquivo-preview').textContent = this.dataset.arquivo;
  });
});
</script>
<?php require __DIR__ . '/_footer.php'; ?>
