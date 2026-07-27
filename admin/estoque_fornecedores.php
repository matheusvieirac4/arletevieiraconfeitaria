<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Fornecedores'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Fornecedores</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$forns = estoque_fornecedores_listar($pdo);
$cont = [];
foreach ($pdo->query("SELECT fornecedor, COUNT(*) n FROM estoque_itens WHERE ativo = 1 AND fornecedor IS NOT NULL AND fornecedor <> '' GROUP BY fornecedor")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cont[$r['fornecedor']] = (int) $r['n'];
}

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$page_title = 'Fornecedores';
$active = 'estoque';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Fornecedores</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>
        <p class="text-muted">No cadastro do item, o campo <strong>Fornecedor</strong> é um select desta lista. <strong>Renomear</strong> aqui atualiza automaticamente os itens que usavam o nome antigo.</p>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header fw-semibold">Novo fornecedor</div>
                    <div class="card-body">
                        <form method="post" action="controller_estoque.php?acao=forn_salvar" class="d-flex gap-2">
                            <input type="text" name="nome" class="form-control" placeholder="Nome do fornecedor" required>
                            <button class="btn btn-primary">Adicionar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 bg-white">
                            <thead><tr><th>Fornecedor</th><th class="text-center">Itens</th><th class="text-end">Ações</th></tr></thead>
                            <tbody>
                            <?php if (!$forns): ?>
                                <tr><td colspan="3" class="text-muted text-center py-4">Nenhum fornecedor cadastrado.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($forns as $f): ?>
                                <tr>
                                    <td>
                                        <form method="post" action="controller_estoque.php?acao=forn_salvar" class="d-flex gap-2" style="max-width:340px;">
                                            <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                            <input type="text" name="nome" class="form-control form-control-sm" value="<?= htmlspecialchars($f['nome']) ?>" required>
                                            <button class="btn btn-outline-primary btn-sm">Salvar</button>
                                        </form>
                                    </td>
                                    <td class="text-center"><?= $cont[$f['nome']] ?? 0 ?></td>
                                    <td class="text-end">
                                        <a href="controller_estoque.php?acao=forn_excluir&id=<?= (int) $f['id'] ?>" class="btn btn-outline-danger btn-sm js-confirm"
                                           data-msg="Remover o fornecedor &quot;<?= htmlspecialchars($f['nome'], ENT_QUOTES) ?>&quot; da lista? Os itens continuam com esse nome; ele só some do select.">Remover</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
