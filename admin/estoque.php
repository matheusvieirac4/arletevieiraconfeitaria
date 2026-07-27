<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

$page_title = 'Estoque';
$active = 'estoque';
$fmt = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
// Valor cru para edição inline (sem separador de milhar; decimal com vírgula).
$raw = fn($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 3, ',', ''), '0'), ',');

if (!estoque_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Estoque</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$busca  = trim((string) ($_GET['busca'] ?? ''));
$soMin  = isset($_GET['abaixo']);
$forn   = trim((string) ($_GET['fornecedor'] ?? ''));
$ordem  = in_array($_GET['ordem'] ?? '', ['nome', 'fornecedor'], true) ? $_GET['ordem'] : 'nome';
$dir    = (strtolower($_GET['dir'] ?? '') === 'desc') ? 'desc' : 'asc';
$itens  = estoque_listar($pdo, $busca, $soMin, $ordem, $dir, $forn);
$fornecedores = estoque_fornecedores($pdo);
$abaixo = count(estoque_lista_compra($pdo));

// Monta o link de ordenação de uma coluna, alternando a direção e mostrando a seta.
$linkOrdem = function (string $col, string $rotulo) use ($ordem, $dir, $busca, $soMin, $forn) {
    $novaDir = ($ordem === $col && $dir === 'asc') ? 'desc' : 'asc';
    $seta = $ordem === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $qs = http_build_query(array_filter([
        'busca' => $busca, 'fornecedor' => $forn, 'abaixo' => $soMin ? 1 : null, 'ordem' => $col, 'dir' => $novaDir,
    ]));
    return '<a href="estoque.php?' . htmlspecialchars($qs) . '" class="text-decoration-none text-reset">' . htmlspecialchars($rotulo) . $seta . '</a>';
};

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-3">Estoque</h1>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="estoque_item.php" class="btn btn-success btn-sm">+ Novo item</a>
            <div class="dropdown">
                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i data-feather="file-plus" class="align-middle me-1" style="width:15px;height:15px;"></i>Entrada
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" id="ent-xml">📄 XML da nota</a></li>
                    <li><a class="dropdown-item" href="estoque_cupom_foto.php">📷 Foto do cupom</a></li>
                </ul>
            </div>
            <a href="estoque_lista_compra.php" class="btn btn-outline-primary btn-sm">
                Lista de compra <?php if ($abaixo): ?><span class="badge bg-danger"><?= $abaixo ?></span><?php endif; ?>
            </a>
            <a href="estoque_auditoria.php" class="btn btn-outline-primary btn-sm"><i data-feather="clipboard" class="align-middle me-1" style="width:15px;height:15px;"></i>Auditoria</a>
            <a href="estoque_kiosk.php" class="btn btn-dark btn-sm"><i data-feather="camera" class="align-middle me-1" style="width:15px;height:15px;"></i>Quiosque</a>
            <a href="estoque_colaboradores.php" class="btn btn-outline-secondary btn-sm"><i data-feather="users" class="align-middle me-1" style="width:15px;height:15px;"></i>Colaboradores</a>
            <form id="form-ent-xml" method="post" action="controller_estoque.php?acao=entrada_xml" enctype="multipart/form-data" class="d-none">
                <input type="file" name="xml" id="file-xml" accept=".xml,text/xml,application/xml">
            </form>
        </div>


        <?php
        $qsAbaixo = http_build_query(array_filter(['busca' => $busca, 'fornecedor' => $forn, 'abaixo' => $soMin ? null : 1]));
        ?>
        <form method="get" class="row g-2 mb-3" style="max-width:900px;">
            <?php if ($soMin): ?><input type="hidden" name="abaixo" value="1"><?php endif; ?>
            <div class="col-12 col-md">
                <input type="text" name="busca" class="form-control" placeholder="Buscar por nome, fornecedor ou código" value="<?= htmlspecialchars($busca) ?>">
            </div>
            <div class="col-auto">
                <select name="fornecedor" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos os fornecedores</option>
                    <?php foreach ($fornecedores as $f): ?>
                        <option value="<?= htmlspecialchars($f, ENT_QUOTES) ?>" <?= $forn === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-secondary">Buscar</button>
            </div>
            <div class="col-auto">
                <a href="estoque.php<?= $qsAbaixo ? '?' . htmlspecialchars($qsAbaixo) : '' ?>" class="btn btn-outline-<?= $soMin ? 'danger' : 'secondary' ?>">
                    <?= $soMin ? '✓ ' : '' ?>Abaixo do mínimo
                </a>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 bg-white">
                    <thead>
                        <tr>
                            <th><?= $linkOrdem('nome', 'Item') ?></th>
                            <th><?= $linkOrdem('fornecedor', 'Fornecedor') ?></th>
                            <th>Medida</th>
                            <th class="text-end">Preço/un</th>
                            <th class="text-end">Atual</th>
                            <th class="text-end">Mín.</th>
                            <th class="text-end">Ideal</th>
                            <th>Situação</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$itens): ?>
                        <tr><td colspan="9" class="text-muted text-center py-4">Nenhum item.</td></tr>
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
                            <td class="small">
                                <?php
                                $un = $it['unidade_medida'] ?? 'UN';
                                $cont = $it['conteudo'];
                                echo ($un === 'UN' && ($cont === null || (float) $cont <= 1))
                                    ? 'UN'
                                    : htmlspecialchars($fmt($cont) . ' ' . $un);
                                if ($pb = estoque_preco_por_base($it)): ?>
                                    <div class="text-muted">R$ <?= number_format($pb['valor'], 4, ',', '.') ?>/<?= $pb['rotulo'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">R$&nbsp;<input type="text" class="inline-edit" style="width:78px" inputmode="decimal" placeholder="—"
                                       data-id="<?= (int) $it['id'] ?>" data-campo="preco" value="<?= $it['preco'] !== null ? number_format((float) $it['preco'], 2, ',', '') : '' ?>"></td>
                            <td class="text-end fw-semibold"><?= $fmt($it['estoque_atual']) ?></td>
                            <td class="text-end"><input type="text" class="inline-edit" style="width:58px" inputmode="decimal" placeholder="—"
                                       data-id="<?= (int) $it['id'] ?>" data-campo="estoque_minimo" value="<?= $raw($min) ?>"></td>
                            <td class="text-end"><input type="text" class="inline-edit" style="width:58px" inputmode="decimal" placeholder="—"
                                       data-id="<?= (int) $it['id'] ?>" data-campo="estoque_ideal" value="<?= $raw($it['estoque_ideal']) ?>"></td>
                            <td data-situacao="<?= (int) $it['id'] ?>">
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
        <p class="text-muted small mt-2">
            <?= count($itens) ?> item(ns)<?= $busca || $soMin || $forn ? ' (filtrado)' : '' ?>.
            &nbsp;·&nbsp;
            <a href="controller_estoque.php?acao=detectar_medida" class="text-decoration-none js-confirm"
               data-msg="Ler as descrições e preencher Unidade/Conteúdo (5 KG, 1 L, 200ML...) nos itens?">
                Detectar unidade/conteúdo pelas descrições
            </a>
        </p>
<script>
// Cada opção do dropdown de Entrada abre o seletor de arquivo e já envia.
(function () {
    const liga = (btnId, fileId, formId) => {
        const btn = document.getElementById(btnId), file = document.getElementById(fileId), form = document.getElementById(formId);
        if (!btn || !file || !form) { return; }
        btn.addEventListener('click', e => { e.preventDefault(); file.click(); });
        file.addEventListener('change', () => { if (file.files.length) { form.submit(); } });
    };
    liga('ent-xml', 'file-xml', 'form-ent-xml');
})();
</script>

<style>
    .inline-edit { border:1px solid transparent; background:transparent; text-align:right; border-radius:6px;
                   padding:2px 6px; font:inherit; color:inherit; transition:background .2s, border-color .15s; }
    .inline-edit:hover { border-color:#dde1e6; }
    .inline-edit:focus { border-color:#2ec07a; background:#fff; outline:none; box-shadow:0 0 0 2px rgba(46,192,122,.15); }
    .inline-edit.saved { background:#eafaf1; border-color:#c7ecd7; }
    .inline-edit.err   { border-color:#dc3545; background:#fdeaea; }
</style>
<script>
// Edição inline de preço/mínimo/ideal: salva ao sair do campo (blur) ou no Enter.
(function () {
    document.querySelectorAll('.inline-edit').forEach(function (inp) {
        inp.dataset.orig = inp.value;
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
            if (e.key === 'Escape') { inp.value = inp.dataset.orig; inp.blur(); }
        });
        inp.addEventListener('blur', function () {
            if (inp.value === inp.dataset.orig) { return; }
            const fd = new FormData();
            fd.append('id', inp.dataset.id);
            fd.append('campo', inp.dataset.campo);
            fd.append('valor', inp.value);
            inp.classList.remove('err');
            fetch('estoque_editar_campo.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.error) {
                        inp.classList.add('err');
                        inp.value = inp.dataset.orig;
                        if (window.showToast) { showToast(d.error, 'danger'); }
                        return;
                    }
                    inp.dataset.orig = inp.value;
                    inp.classList.add('saved');
                    setTimeout(function () { inp.classList.remove('saved'); }, 1000);
                    if (inp.dataset.campo === 'estoque_minimo') {
                        const td = document.querySelector('[data-situacao="' + inp.dataset.id + '"]');
                        if (td) {
                            td.innerHTML = d.abaixo
                                ? '<span class="badge bg-danger">abaixo do mínimo</span>'
                                : '<span class="badge bg-light text-success border border-success">ok</span>';
                        }
                    }
                })
                .catch(function () { inp.classList.add('err'); inp.value = inp.dataset.orig; });
        });
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
