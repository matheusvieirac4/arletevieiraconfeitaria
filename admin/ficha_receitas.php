<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$page_title = 'Receitas';
$active = 'ficha_receitas';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Receitas</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$fmt  = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
$reais = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');

$busca = trim((string) ($_GET['busca'] ?? ''));
$cat   = trim((string) ($_GET['categoria'] ?? ''));
$receitas = ficha_receitas_listar($pdo, $busca, $cat);
$categorias = ficha_categorias_nomes($pdo, 'receita');

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-3">Receitas <span class="text-muted fs-5">(bases, recheios, massas)</span></h1>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="ficha_receita.php" class="btn btn-success btn-sm">+ Nova receita</a>
            <a href="ficha_produtos.php" class="btn btn-outline-primary btn-sm">Produtos</a>
            <a href="ficha_categorias.php?tipo=receita" class="btn btn-outline-secondary btn-sm">Categorias</a>
            <button type="button" id="btn-pdf" class="btn btn-outline-dark btn-sm" disabled>📄 Baixar PDF das selecionadas</button>
        </div>

        <form method="get" class="row g-2 mb-3" style="max-width:760px;">
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
                            <th>Receita</th>
                            <th>Categoria</th>
                            <th class="text-end">Evapor.</th>
                            <th class="text-end">Peso final</th>
                            <th class="text-end">Custo total</th>
                            <th class="text-end">Custo/un</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$receitas): ?>
                        <tr><td colspan="8" class="text-muted text-center py-4">Nenhuma receita cadastrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($receitas as $r):
                        $c = ficha_receita_custo($pdo, (int) $r['id']);
                        $un = strtolower($r['unidade_rendimento'] ?? 'g');
                    ?>
                        <tr>
                            <td><input type="checkbox" class="form-check-input check-item" value="<?= (int) $r['id'] ?>"></td>
                            <td><a href="ficha_receita.php?id=<?= (int) $r['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($r['nome']) ?></a></td>
                            <td class="text-muted"><?= htmlspecialchars($r['categoria'] ?? '—') ?></td>
                            <td class="text-end"><?= rtrim(rtrim(number_format((float) $r['percentual_evaporacao'], 2, ',', '.'), '0'), ',') ?>%</td>
                            <td class="text-end"><?= $fmt($c['peso_final']) ?> <?= htmlspecialchars($un) ?></td>
                            <td class="text-end fw-semibold"><?= $reais($c['custo_total']) ?></td>
                            <td class="text-end"><?= $c['custo_por_g'] !== null ? 'R$ ' . number_format($c['custo_por_g'], 4, ',', '.') . '/' . htmlspecialchars($un) : '—' ?></td>
                            <td class="text-end text-nowrap">
                                <a href="ficha_receita.php?id=<?= (int) $r['id'] ?>" class="btn btn-outline-primary btn-sm">Abrir</a>
                                <a href="ficha_receita.php?duplicar=<?= (int) $r['id'] ?>" class="btn btn-outline-secondary btn-sm" title="Duplicar esta receita">Duplicar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted small mt-2"><?= count($receitas) ?> receita(s)<?= ($busca || $cat) ? ' (filtrado)' : '' ?>.</p>
<script>
// Seleção múltipla → abre o PDF com as receitas escolhidas (uma por folha).
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
        if (ids.length) { window.open('ficha_pdf.php?tipo=receita&ids=' + ids.join(','), '_blank'); }
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
