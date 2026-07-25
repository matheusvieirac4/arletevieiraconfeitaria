<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Auditoria'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Auditoria de estoque</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$busca = trim((string) ($_GET['busca'] ?? ''));
$itens = estoque_listar($pdo, $busca);

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$page_title = 'Auditoria';
$active = 'estoque';
$fmt = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="mb-0">Auditoria de estoque</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>
        <p class="text-muted">Contagem física. Digite a quantidade real só nos itens que contar — os deixados <strong>em branco não são alterados</strong>. Ao salvar, cada item preenchido recebe um ajuste no histórico.</p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <form method="get" class="row g-2 mb-3" style="max-width:560px;">
            <div class="col"><input type="text" name="busca" class="form-control" placeholder="Filtrar por nome/fornecedor (ex.: prateleira)" value="<?= htmlspecialchars($busca) ?>"></div>
            <div class="col-auto"><button class="btn btn-outline-secondary">Filtrar</button></div>
        </form>

        <form method="post" action="controller_estoque.php?acao=auditoria" id="form-auditoria">
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 bg-white">
                        <thead><tr>
                            <th>Item</th>
                            <th>Fornecedor</th>
                            <th class="text-end">Sistema</th>
                            <th class="text-end" style="width:150px;">Contagem real</th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$itens): ?>
                            <tr><td colspan="4" class="text-muted text-center py-4">Nenhum item.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($itens as $it): ?>
                            <tr>
                                <td><?= htmlspecialchars($it['nome']) ?></td>
                                <td class="text-muted"><?= htmlspecialchars($it['fornecedor'] ?? '—') ?></td>
                                <td class="text-end text-muted"><?= $fmt($it['estoque_atual']) ?></td>
                                <td>
                                    <input type="text" name="contagem[<?= (int) $it['id'] ?>]" class="form-control form-control-sm text-end"
                                           placeholder="—" inputmode="decimal" autocomplete="off">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($itens): ?>
                <div class="mt-3">
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modal-auditoria">Salvar contagem</button>
                </div>
            <?php endif; ?>
        </form>

        <div class="modal fade" id="modal-auditoria" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Salvar contagem</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        Só os itens <strong>preenchidos</strong> serão ajustados; os deixados em branco não mudam.
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-success" id="btn-conf-auditoria">Salvar contagem</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('btn-conf-auditoria')?.addEventListener('click', function () {
            document.getElementById('form-auditoria').submit();
        });
        </script>
<?php require __DIR__ . '/_footer.php'; ?>
