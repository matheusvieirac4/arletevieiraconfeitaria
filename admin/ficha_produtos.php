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
// Badge da margem de contribuição: quanto maior, melhor (verde ≥30, amarelo ≥15).
$margemBadge = function (?float $m): string {
    if ($m === null) { return '<span class="badge bg-light text-muted border">—</span>'; }
    $cls = $m < 0 ? 'bg-danger' : ($m >= 30 ? 'bg-success' : ($m >= 15 ? 'bg-warning text-dark' : 'bg-danger'));
    return '<span class="badge ' . $cls . '">' . number_format($m, 1, ',', '.') . '%</span>';
};

$busca = trim((string) ($_GET['busca'] ?? ''));
$cat   = trim((string) ($_GET['categoria'] ?? ''));
$produtos = ficha_produtos_listar($pdo, $busca, $cat);
$categorias = ficha_categorias_nomes($pdo, 'produto');

// Ordenação (as colunas de valor são calculadas, então ordenamos em PHP).
$colsOrd = ['nome', 'categoria', 'custo', 'preco', 'cmv', 'margem'];
$ordem = in_array($_GET['ordem'] ?? '', $colsOrd, true) ? $_GET['ordem'] : 'nome';
$dir   = (strtolower($_GET['dir'] ?? '') === 'desc') ? 'desc' : 'asc';

// Pré-calcula a precificação de cada produto e ordena.
$rows = [];
foreach ($produtos as $p) {
    $rows[] = ['p' => $p, 'c' => ficha_precificar($pdo, (int) $p['id'])];
}
$valorOrd = function (array $row, string $col) {
    $p = $row['p']; $c = $row['c'];
    switch ($col) {
        case 'categoria': return mb_strtolower((string) ($p['categoria'] ?? ''), 'UTF-8');
        case 'custo':     return $c['custo_prato'];
        case 'preco':     return $c['preco_direta'];
        case 'cmv':       return $c['cmv_direta_pct'];
        case 'margem':    return $c['margem_direta_pct'];
        default:          return mb_strtolower((string) $p['nome'], 'UTF-8');
    }
};
usort($rows, function ($a, $b) use ($valorOrd, $ordem, $dir) {
    $va = $valorOrd($a, $ordem); $vb = $valorOrd($b, $ordem);
    // Sem valor (null) vai sempre para o fim, independente da direção.
    if ($va === null && $vb === null) { return 0; }
    if ($va === null) { return 1; }
    if ($vb === null) { return -1; }
    $cmp = is_string($va) ? strnatcasecmp($va, $vb) : ($va <=> $vb);
    return $dir === 'desc' ? -$cmp : $cmp;
});

// Link de ordenação de uma coluna (alterna direção, mostra a seta).
$linkOrdem = function (string $col, string $rot) use ($ordem, $dir, $busca, $cat) {
    $novaDir = ($ordem === $col && $dir === 'asc') ? 'desc' : 'asc';
    $seta = $ordem === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $qs = http_build_query(array_filter(['busca' => $busca, 'categoria' => $cat, 'ordem' => $col, 'dir' => $novaDir]));
    return '<a href="ficha_produtos.php?' . htmlspecialchars($qs) . '" class="text-decoration-none text-reset">' . htmlspecialchars($rot) . $seta . '</a>';
};

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-3">Produtos <span class="text-muted fs-5">(vendáveis, com precificação)</span></h1>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="ficha_produto.php" class="btn btn-success btn-sm">+ Novo produto</a>
            <a href="ficha_receitas.php" class="btn btn-outline-primary btn-sm">Receitas</a>
            <a href="ficha_categorias.php?tipo=produto" class="btn btn-outline-secondary btn-sm">Categorias</a>
            <button type="button" id="btn-pdf" class="btn btn-outline-dark btn-sm" disabled>📄 Baixar PDF dos selecionados</button>
        </div>

        <form method="get" class="row g-2 mb-3" style="max-width:760px;">
            <input type="hidden" name="ordem" value="<?= htmlspecialchars($ordem) ?>">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
            <div class="col-12 col-md">
                <input type="text" name="busca" class="form-control" placeholder="Buscar por nome" value="<?= htmlspecialchars($busca) ?>">
            </div>
            <div class="col-auto">
                <select name="categoria" class="form-select" onchange="this.form.submit()">
                    <option value="">Todas as categorias</option>
                    <?php foreach ($categorias as $cn): ?>
                        <option value="<?= htmlspecialchars($cn, ENT_QUOTES) ?>" <?= $cat === $cn ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-outline-secondary">Buscar</button></div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 bg-white">
                    <thead>
                        <tr>
                            <th style="width:34px"><input type="checkbox" id="check-all" class="form-check-input"></th>
                            <th><?= $linkOrdem('nome', 'Produto') ?></th>
                            <th><?= $linkOrdem('categoria', 'Categoria') ?></th>
                            <th class="text-end"><?= $linkOrdem('custo', 'Custo') ?></th>
                            <th class="text-end"><?= $linkOrdem('preco', 'Preço Direta') ?></th>
                            <th class="text-center"><?= $linkOrdem('cmv', 'CMV') ?></th>
                            <th class="text-center"><?= $linkOrdem('margem', 'Margem contrib.') ?></th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="8" class="text-muted text-center py-4">Nenhum produto cadastrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): $p = $row['p']; $c = $row['c']; ?>
                        <tr>
                            <td><input type="checkbox" class="form-check-input check-item" value="<?= (int) $p['id'] ?>"></td>
                            <td><a href="ficha_produto.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($p['nome']) ?></a></td>
                            <td class="text-muted"><?= htmlspecialchars($p['categoria'] ?? '—') ?></td>
                            <td class="text-end"><?= $reais($c['custo_prato']) ?></td>
                            <td class="text-end fw-semibold"><?= $reais($c['preco_direta']) ?></td>
                            <td class="text-center"><?= $cmvBadge($c['cmv_direta_pct']) ?></td>
                            <td class="text-center"><?= $margemBadge($c['margem_direta_pct']) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="ficha_produto.php?id=<?= (int) $p['id'] ?>" class="btn btn-outline-primary btn-sm">Abrir</a>
                                <a href="ficha_produto.php?duplicar=<?= (int) $p['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Duplicar este produto">Duplicar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted small mt-2"><?= count($rows) ?> produto(s)<?= ($busca || $cat) ? ' (filtrado)' : '' ?>. CMV: <span class="badge bg-success">≤35%</span> <span class="badge bg-warning text-dark">≤45%</span> <span class="badge bg-danger">&gt;45%</span></p>
<script>
// Seleção múltipla → abre o PDF com os produtos escolhidos (um por folha).
(function () {
    const all = document.getElementById('check-all');
    const btn = document.getElementById('btn-pdf');
    const marcadas = () => Array.from(document.querySelectorAll('.check-item:checked')).map(c => c.value);
    const atualiza = () => { btn.disabled = marcadas().length === 0; };
    all.addEventListener('change', () => {
        document.querySelectorAll('.check-item').forEach(c => { c.checked = all.checked; });
        atualiza();
    });
    document.querySelectorAll('.check-item').forEach(c => c.addEventListener('change', atualiza));
    btn.addEventListener('click', () => {
        const ids = marcadas();
        if (ids.length) { window.open('ficha_pdf.php?tipo=produto&ids=' + ids.join(','), '_blank'); }
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
