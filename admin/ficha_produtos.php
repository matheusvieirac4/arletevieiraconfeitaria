<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$page_title = 'Produtos';
$active = 'ficha_produtos';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Produtos</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$reais = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');
// Semáforo de CMV: verde <=35%, amarelo <=45%, vermelho acima. Ajuste conforme a meta.
$cmvBadge = function (?float $cmv): string {
    if ($cmv === null) { return '<span class="badge bg-light text-muted border">sem preço</span>'; }
    $cls = $cmv <= 35 ? 'bg-success' : ($cmv <= 45 ? 'bg-warning text-dark' : 'bg-danger');
    return '<span class="badge ' . $cls . '">' . number_format($cmv, 1, ',', '.') . '%</span>';
};

$busca = trim((string) ($_GET['busca'] ?? ''));
$produtos = ficha_produtos_listar($pdo, $busca);

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-3">Produtos <span class="text-muted fs-5">(vendáveis, com CMV)</span></h1>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="ficha_produto.php" class="btn btn-success btn-sm">+ Novo produto</a>
            <a href="ficha_receitas.php" class="btn btn-outline-primary btn-sm">Receitas</a>
        </div>

        <form method="get" class="row g-2 mb-3" style="max-width:600px;">
            <div class="col-12 col-md">
                <input type="text" name="busca" class="form-control" placeholder="Buscar por nome ou categoria" value="<?= htmlspecialchars($busca) ?>">
            </div>
            <div class="col-auto"><button class="btn btn-outline-secondary">Buscar</button></div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 bg-white">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th>Categoria</th>
                            <th class="text-end">Custo</th>
                            <th class="text-end">Preço venda</th>
                            <th class="text-end">Margem</th>
                            <th class="text-center">CMV</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$produtos): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">Nenhum produto cadastrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($produtos as $p):
                        $c = ficha_produto_custo($pdo, (int) $p['id']);
                    ?>
                        <tr>
                            <td><a href="ficha_produto.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($p['nome']) ?></a></td>
                            <td class="text-muted"><?= htmlspecialchars($p['categoria'] ?? '—') ?></td>
                            <td class="text-end"><?= $reais($c['custo_total']) ?></td>
                            <td class="text-end"><?= $reais($c['preco_venda']) ?></td>
                            <td class="text-end <?= ($c['margem'] !== null && $c['margem'] < 0) ? 'text-danger fw-semibold' : '' ?>"><?= $reais($c['margem']) ?></td>
                            <td class="text-center"><?= $cmvBadge($c['cmv_pct']) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="ficha_produto.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Abrir</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted small mt-2"><?= count($produtos) ?> produto(s)<?= $busca ? ' (filtrado)' : '' ?>. CMV: <span class="badge bg-success">≤35%</span> <span class="badge bg-warning text-dark">≤45%</span> <span class="badge bg-danger">&gt;45%</span></p>
<?php require __DIR__ . '/_footer.php'; ?>
