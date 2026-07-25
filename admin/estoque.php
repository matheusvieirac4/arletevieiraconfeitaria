<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

$page_title = 'Estoque';
$active = 'estoque';
$fmt = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');

if (!estoque_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Estoque</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$busca  = trim((string) ($_GET['busca'] ?? ''));
$soMin  = isset($_GET['abaixo']);
$itens  = estoque_listar($pdo, $busca, $soMin);
$abaixo = count(estoque_lista_compra($pdo));

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1 class="mb-0">Estoque</h1>
            <div class="d-flex gap-2">
                <a href="estoque_kiosk.php" class="btn btn-dark"><i data-feather="camera" class="align-middle me-1" style="width:16px;height:16px;"></i>Modo quiosque</a>
                <a href="estoque_entrada.php" class="btn btn-outline-primary"><i data-feather="file-plus" class="align-middle me-1" style="width:16px;height:16px;"></i>Entrada (XML)</a>
                <a href="estoque_lista_compra.php" class="btn btn-outline-primary">
                    Lista de compra <?php if ($abaixo): ?><span class="badge bg-danger"><?= $abaixo ?></span><?php endif; ?>
                </a>
                <a href="estoque_item.php" class="btn btn-success">Novo item</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <form method="get" class="row g-2 mb-3" style="max-width:680px;">
            <div class="col">
                <input type="text" name="busca" class="form-control" placeholder="Buscar por nome, fornecedor ou código de barras" value="<?= htmlspecialchars($busca) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary">Buscar</button>
            </div>
            <div class="col-auto">
                <a href="estoque.php<?= $soMin ? '' : '?abaixo=1' ?>" class="btn btn-outline-<?= $soMin ? 'danger' : 'secondary' ?>">
                    <?= $soMin ? '✓ ' : '' ?>Abaixo do mínimo
                </a>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 bg-white">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Fornecedor</th>
                            <th class="text-end">Atual</th>
                            <th class="text-end">Mín.</th>
                            <th class="text-end">Ideal</th>
                            <th>Situação</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$itens): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">Nenhum item.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($itens as $it):
                        $min = $it['estoque_minimo'];
                        $abaixoMin = $min !== null && (float) $it['estoque_atual'] < (float) $min;
                    ?>
                        <tr>
                            <td>
                                <a href="estoque_item.php?id=<?= (int) $it['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($it['nome']) ?></a>
                                <?php if (empty($it['codigo_barras'])): ?><span class="badge bg-light text-muted ms-1" title="Sem código de barras">sem cód.</span><?php endif; ?>
                            </td>
                            <td class="text-muted"><?= htmlspecialchars($it['fornecedor'] ?? '—') ?></td>
                            <td class="text-end fw-semibold"><?= $fmt($it['estoque_atual']) ?></td>
                            <td class="text-end text-muted"><?= $fmt($min) ?></td>
                            <td class="text-end text-muted"><?= $fmt($it['estoque_ideal']) ?></td>
                            <td>
                                <?php if ($abaixoMin): ?>
                                    <span class="badge bg-danger">abaixo do mínimo</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-success border border-success">ok</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="estoque_item.php?id=<?= (int) $it['id'] ?>" class="btn btn-outline-primary btn-sm">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted small mt-2"><?= count($itens) ?> item(ns)<?= $busca || $soMin ? ' (filtrado)' : '' ?>.</p>
<?php require __DIR__ . '/_footer.php'; ?>
