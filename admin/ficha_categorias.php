<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$tipo = ($_GET['tipo'] ?? 'receita') === 'produto' ? 'produto' : 'receita';
$page_title = 'Categorias';
$active = $tipo === 'produto' ? 'ficha_produtos' : 'ficha_receitas';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Categorias</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$cats = ficha_categorias_listar($pdo, $tipo);
$outro = $tipo === 'produto' ? 'receita' : 'produto';
$voltar = $tipo === 'produto' ? 'ficha_produtos.php' : 'ficha_receitas.php';

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <div class="d-flex align-items-center gap-2 mb-3">
            <a href="<?= $voltar ?>" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
            <h1 class="mb-0 fs-3">Categorias de <?= $tipo === 'produto' ? 'produtos' : 'receitas' ?></h1>
        </div>
        <div class="mb-3">
            <a href="ficha_categorias.php?tipo=<?= $outro ?>" class="btn btn-sm btn-outline-primary">Ver categorias de <?= $outro === 'produto' ? 'produtos' : 'receitas' ?></a>
        </div>

        <div class="row g-4">
            <div class="col-12 col-md-5">
                <div class="card">
                    <div class="card-header"><strong>Nova categoria</strong></div>
                    <div class="card-body">
                        <form method="post" action="controller_ficha.php?acao=cat_salvar" class="d-flex gap-2">
                            <input type="hidden" name="tipo" value="<?= $tipo ?>">
                            <input type="text" name="nome" class="form-control" placeholder="Ex.: <?= $tipo === 'produto' ? 'Brownies' : 'Massas' ?>" required>
                            <button class="btn btn-success">Adicionar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-7">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 bg-white">
                            <thead><tr><th>Categoria</th><th class="text-end">Ações</th></tr></thead>
                            <tbody>
                            <?php if (!$cats): ?>
                                <tr><td colspan="2" class="text-muted text-center py-4">Nenhuma categoria.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($cats as $c): ?>
                                <tr>
                                    <td>
                                        <form method="post" action="controller_ficha.php?acao=cat_renomear" class="d-flex gap-2" id="cat-form-<?= (int) $c['id'] ?>">
                                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                            <input type="hidden" name="tipo" value="<?= $tipo ?>">
                                            <input type="text" name="nome" class="form-control form-control-sm" value="<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>" required>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <button type="submit" form="cat-form-<?= (int) $c['id'] ?>" class="btn btn-outline-primary btn-sm">Salvar</button>
                                        <a href="controller_ficha.php?acao=cat_excluir&id=<?= (int) $c['id'] ?>&tipo=<?= $tipo ?>" class="btn btn-outline-danger btn-sm js-confirm" data-msg="Remover a categoria &quot;<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>&quot;? As fichas que já a usam mantêm o texto.">Excluir</a>
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
