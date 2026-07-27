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
$forn  = trim((string) ($_GET['fornecedor'] ?? ''));
$ord   = in_array($_GET['ord'] ?? '', ['nome', 'fornecedor'], true) ? $_GET['ord'] : 'nome';
$dir   = (strtolower($_GET['dir'] ?? '') === 'desc') ? 'desc' : 'asc';
$itens = estoque_listar($pdo, $busca, false, $ord, $dir, $forn);
// Só itens marcados para controle (ex.: exclui sala dos doces / geladeiras).
if (estoque_tem_controlar($pdo)) {
    $itens = array_values(array_filter($itens, fn($it) => !empty($it['controlar_estoque'])));
}
$fornecedores = estoque_fornecedores($pdo);
$colabs = estoque_colaboradores_listar($pdo);

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


        <style>
            .aud-row { display:flex; align-items:center; gap:12px; padding:11px 14px; border-bottom:1px solid #eef0f3; }
            .aud-row:last-child { border-bottom:0; }
            .aud-info { flex:1; min-width:0; }
            .aud-nome { font-weight:600; line-height:1.2; }
            .aud-meta { color:#8a929c; font-size:.82rem; margin-top:2px; }
            .aud-meta .sis { color:#28303f; font-weight:600; }
            .aud-input { width:86px; flex:0 0 86px; text-align:center; font-size:1.15rem; padding:.4rem; }
        </style>

        <form method="get" class="row g-2 mb-2" style="max-width:760px;">
            <div class="col-12 col-md"><input type="text" name="busca" class="form-control" placeholder="Filtrar por nome" value="<?= htmlspecialchars($busca) ?>"></div>
            <input type="hidden" name="ord" value="<?= htmlspecialchars($ord) ?>">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($dir) ?>">
            <div class="col-auto">
                <select name="fornecedor" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos os fornecedores</option>
                    <?php foreach ($fornecedores as $f): ?>
                        <option value="<?= htmlspecialchars($f, ENT_QUOTES) ?>" <?= $forn === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto"><button class="btn btn-outline-secondary">Filtrar</button></div>
        </form>

        <?php
        $qs   = fn($o, $d) => 'estoque_auditoria.php?' . http_build_query(['busca' => $busca, 'fornecedor' => $forn, 'ord' => $o, 'dir' => $d]);
        $seta = fn($o) => $ord === $o ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
        $prox = fn($o) => ($ord === $o && $dir === 'asc') ? 'desc' : 'asc';
        ?>
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <span class="text-muted small">Ordenar:</span>
            <a class="btn btn-sm <?= $ord === 'nome' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= htmlspecialchars($qs('nome', $prox('nome'))) ?>">Item<?= $seta('nome') ?></a>
            <a class="btn btn-sm <?= $ord === 'fornecedor' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= htmlspecialchars($qs('fornecedor', $prox('fornecedor'))) ?>">Fornecedor<?= $seta('fornecedor') ?></a>
        </div>

        <form method="post" action="controller_estoque.php?acao=auditoria" id="form-auditoria">
            <input type="hidden" name="responsavel_id" id="aud-resp">
            <div class="card">
                <?php if (!$itens): ?>
                    <div class="text-muted text-center py-4">Nenhum item.</div>
                <?php endif; ?>
                <?php foreach ($itens as $it): ?>
                    <div class="aud-row">
                        <div class="aud-info">
                            <div class="aud-nome"><?= htmlspecialchars($it['nome']) ?></div>
                            <div class="aud-meta"><?= htmlspecialchars($it['fornecedor'] ?? '—') ?> · Sistema: <span class="sis"><?= $fmt($it['estoque_atual']) ?></span></div>
                        </div>
                        <input type="text" name="contagem[<?= (int) $it['id'] ?>]" class="form-control aud-input"
                               placeholder="—" enterkeyhint="next" autocomplete="off"
                               aria-label="Contagem de <?= htmlspecialchars($it['nome'], ENT_QUOTES) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($itens): ?>
                <div class="mt-3">
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modal-auditoria">Salvar contagem</button>
                </div>
            <?php endif; ?>
        </form>

        <script>
        // Teclado de texto (tem Enter): só números "grudam", e Enter pula pro próximo item.
        (function () {
            const inputs = Array.prototype.slice.call(document.querySelectorAll('.aud-input'));
            inputs.forEach(function (inp, i) {
                inp.addEventListener('input', function () { inp.value = inp.value.replace(/[^0-9.,-]/g, ''); });
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const next = inputs[i + 1];
                        if (next) { next.focus(); if (next.select) { next.select(); } }
                        else { inp.blur(); }
                    }
                });
            });
        })();
        </script>

        <div class="modal fade" id="modal-auditoria" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Salvar contagem</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <p>Só os itens <strong>preenchidos</strong> serão ajustados; os deixados em branco não mudam.</p>
                        <label class="form-label">Quem fez a contagem?</label>
                        <select class="form-select" id="aud-resp-sel">
                            <option value="">Selecione...</option>
                            <option value="admin"><?= htmlspecialchars(($_SESSION['admin_nome'] ?? 'Eu') . ' (eu)') ?></option>
                            <?php foreach ($colabs as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Fica registrado como responsável de cada ajuste.</div>
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
            const sel = document.getElementById('aud-resp-sel');
            if (!sel.value) { sel.classList.add('is-invalid'); return; }
            document.getElementById('aud-resp').value = sel.value;
            document.getElementById('form-auditoria').submit();
        });
        </script>
<?php require __DIR__ . '/_footer.php'; ?>
