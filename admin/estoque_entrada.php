<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Entrada de estoque'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Entrada de estoque</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$rev = $_SESSION['estoque_entrada'] ?? null;
// Sem revisão em andamento não há o que mostrar aqui — a entrada começa pelo
// dropdown "Entrada" na tela de estoque.
if (!$rev) { header('Location: estoque.php'); exit; }
$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);
$itensTodos = estoque_listar($pdo);

$page_title = 'Entrada de estoque';
$active = 'estoque';
$fmt = fn($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Entrada de estoque</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

            <div class="alert alert-info">
                <?php if (($rev['origem'] ?? '') === 'cupom'): ?>
                    Itens lidos do <strong>cupom</strong> pela IA. Confira o item do estoque e a quantidade — casou por nome, então revise.
                <?php else: ?>
                    Nota <?= $rev['numero'] !== '' ? 'nº ' . htmlspecialchars($rev['numero']) : '' ?>
                    <?= $rev['fornecedor'] !== '' ? '· ' . htmlspecialchars($rev['fornecedor']) : '' ?>.
                    Confira o item do estoque e a quantidade de cada linha.
                <?php endif; ?>
            </div>
            <form method="post" action="controller_estoque.php?acao=entrada_confirmar">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 bg-white">
                            <thead><tr>
                                <th>Item na nota</th>
                                <th style="min-width:280px;">Casar com o estoque</th>
                                <th class="text-end" style="width:120px;">Qtde</th>
                                <th style="width:44px;"></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($rev['linhas'] as $i => $l): ?>
                                <tr>
                                    <td>
                                        <?= htmlspecialchars($l['descricao']) ?>
                                        <div class="small text-muted">
                                            <?php if ($l['ean']): ?>cód. <?= htmlspecialchars($l['ean']) ?> · <?php endif; ?>
                                            <?= htmlspecialchars($l['unidade']) ?>
                                            <?php if ($l['match'] === 'alias'): ?><span class="badge bg-info text-dark">casou pelo histórico</span>
                                            <?php elseif ($l['match'] === 'barcode'): ?><span class="badge bg-success">casou por código</span>
                                            <?php elseif ($l['match'] === 'nome'): ?><span class="badge bg-warning text-dark">casou por nome — confira</span>
                                            <?php else: ?><span class="badge bg-secondary">sem correspondência</span><?php endif; ?>
                                        </div>
                                        <input type="hidden" name="ean[<?= $i ?>]" value="<?= htmlspecialchars($l['ean']) ?>">
                                        <input type="hidden" name="descricao[<?= $i ?>]" value="<?= htmlspecialchars($l['descricao'], ENT_QUOTES) ?>">
                                    </td>
                                    <td>
                                        <select name="item_id[<?= $i ?>]" class="form-select">
                                            <option value="novo"<?= $l['item_id'] === null ? ' selected' : '' ?>>➕ Cadastrar como novo item</option>
                                            <option value="ignorar">— Ignorar esta linha —</option>
                                            <optgroup label="Itens do estoque">
                                                <?php foreach ($itensTodos as $it): ?>
                                                    <option value="<?= (int) $it['id'] ?>" <?= (int) $l['item_id'] === (int) $it['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($it['nome']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="quantidade[<?= $i ?>]" class="form-control text-end"
                                               value="<?= $fmt($l['quantidade']) ?>" inputmode="decimal">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm" title="Remover da lista (compra pessoal)"
                                                onclick="this.closest('tr').remove()">&times;</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-success">Dar entrada</button>
                    <a href="controller_estoque.php?acao=entrada_cancelar" class="btn btn-outline-secondary">Descartar</a>
                </div>
            </form>
<?php require __DIR__ . '/_footer.php'; ?>
