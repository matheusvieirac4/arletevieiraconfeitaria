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
$fmt = fn($n) => estoque_qtd($n);   // saldo/contagem são inteiros
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

        <div id="aud-nao-salvas" class="alert alert-warning py-2 px-3 mt-2" style="display:none;"></div>

        <script>
        // Contagens ficam guardadas no navegador (localStorage) por item, então trocar de
        // fornecedor no filtro ou recarregar NÃO apaga o que você já digitou. Você pode
        // contar fornecedor por fornecedor e salvar tudo de uma vez no fim.
        (function () {
            const KEY = 'aud_contagens';                 // { "<itemId>": "<valor>" }
            const form = document.getElementById('form-auditoria');
            const inputs = Array.prototype.slice.call(document.querySelectorAll('.aud-input'));

            function nomeItemId(inp) {
                const m = /contagem\[(\d+)\]/.exec(inp.getAttribute('name') || '');
                return m ? m[1] : null;
            }
            function ler() {
                try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; }
                catch (e) { return {}; }
            }
            function gravar(store) { localStorage.setItem(KEY, JSON.stringify(store)); }

            // Restaura o que já foi digitado nos itens visíveis.
            const store = ler();
            inputs.forEach(function (inp) {
                const id = nomeItemId(inp);
                if (id && store[id] != null && store[id] !== '') { inp.value = store[id]; }
            });

            // Aviso de quantas contagens estão guardadas mas ainda não salvas.
            function atualizarAviso() {
                const s = ler();
                const n = Object.keys(s).filter(k => (s[k] + '').trim() !== '').length;
                const box = document.getElementById('aud-nao-salvas');
                if (!box) { return; }
                if (n > 0) {
                    box.style.display = 'block';
                    box.innerHTML = '📝 Você tem <strong>' + n + '</strong> contagem(ns) guardada(s) e ainda não salva(s) '
                        + '(inclui outros fornecedores). Clique em <strong>Salvar contagem</strong> para gravar tudo.';
                } else {
                    box.style.display = 'none';
                }
            }
            atualizarAviso();

            // Teclado de texto (tem Enter): só números "grudam", e Enter pula pro próximo item.
            inputs.forEach(function (inp, i) {
                inp.addEventListener('input', function () {
                    inp.value = inp.value.replace(/[^0-9.,-]/g, '');
                    const id = nomeItemId(inp);
                    if (!id) { return; }
                    const s = ler();
                    if (inp.value.trim() === '') { delete s[id]; } else { s[id] = inp.value; }
                    gravar(s);
                    atualizarAviso();
                });
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const next = inputs[i + 1];
                        if (next) { next.focus(); if (next.select) { next.select(); } }
                        else { inp.blur(); }
                    }
                });
            });

            // Ao salvar: injeta campos ocultos para as contagens guardadas de itens que
            // NÃO estão na tela agora (outros fornecedores), pra gravar tudo de uma vez.
            form.addEventListener('submit', function () {
                const s = ler();
                const idsNaTela = {};
                inputs.forEach(function (inp) { const id = nomeItemId(inp); if (id) { idsNaTela[id] = true; } });
                Object.keys(s).forEach(function (id) {
                    if (idsNaTela[id] || (s[id] + '').trim() === '') { return; }
                    const h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = 'contagem[' + id + ']';
                    h.value = s[id];
                    form.appendChild(h);
                });
                // Marca que uma gravação foi disparada; a limpeza acontece na tela de
                // sucesso (estoque.php). Se der erro e voltar pra cá, os dados continuam.
                sessionStorage.setItem('aud_salvando', '1');
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
