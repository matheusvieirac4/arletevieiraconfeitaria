<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$page_title = 'Receita';
$active = 'ficha_receitas';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Receita</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
// Duplicar: carrega os dados de outra receita em MODO CRIAÇÃO (id fica 0, então
// ao salvar nasce uma nova) com " (cópia)" no nome.
$dupId = $id === 0 ? (int) ($_GET['duplicar'] ?? 0) : 0;
$origemId = $id > 0 ? $id : $dupId;

$rec = $origemId > 0 ? ficha_receita_buscar($pdo, $origemId) : null;
if ($origemId > 0 && !$rec) {
    require __DIR__ . '/_header.php';
    echo '<div class="alert alert-warning">Receita não encontrada.</div>';
    require __DIR__ . '/_footer.php';
    exit;
}
$linhas = $origemId > 0 ? ficha_receita_itens($pdo, $origemId) : [];
if ($dupId > 0 && $rec) {
    $rec['nome'] = trim((string) $rec['nome']) . ' (cópia)';
}

// Itens do estoque para o seletor + mapa de custo/base para o cálculo ao vivo.
$itensEstoque = estoque_listar($pdo);
$mapaCusto = [];
foreach ($itensEstoque as $it) {
    $pb = estoque_preco_por_base($it);
    $mapaCusto[(int) $it['id']] = [
        'valor'  => $pb['valor'] ?? ($it['preco'] !== null ? (float) $it['preco'] : null),
        'rotulo' => $pb['rotulo'] ?? 'un',
    ];
}
$unidades = estoque_unidades_medida();
$categorias = ficha_categorias_nomes($pdo, 'receita');
$historico = $id > 0 ? ficha_receita_historico($pdo, $id, 20) : [];
$reais = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
$extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">';
$extra_css = '
        #editor-preparo { min-height: 160px; background:#fff; }
        #editor-preparo .ql-editor { font-size: 1rem; line-height: 1.5; }
';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex align-items-center gap-2 mb-3">
            <a href="ficha_receitas.php" class="btn btn-outline-secondary btn-sm">&larr; Receitas</a>
            <h1 class="mb-0 fs-3"><?= $id > 0 ? htmlspecialchars($rec['nome']) : ($dupId > 0 ? 'Duplicar receita' : 'Nova receita') ?></h1>
        </div>

        <form method="post" action="controller_ficha.php?acao=receita_salvar" id="form-receita">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-5">
                    <label class="form-label">Nome da receita</label>
                    <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars($rec['nome'] ?? '') ?>" placeholder="Ex.: MASSA DE BEIJINHO">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Categoria <a href="ficha_categorias.php?tipo=receita" class="small text-decoration-none" title="Gerenciar categorias">(gerenciar)</a></label>
                    <?php $catAtual = $rec['categoria'] ?? ''; ?>
                    <select name="categoria" class="form-select">
                        <option value="">— sem categoria —</option>
                        <?php foreach ($categorias as $cn): ?>
                            <option value="<?= htmlspecialchars($cn, ENT_QUOTES) ?>" <?= $catAtual === $cn ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">% de evaporação</label>
                    <input type="text" name="percentual_evaporacao" id="f-evap" class="form-control text-end" inputmode="decimal" value="<?= $rec ? rtrim(rtrim(number_format((float) $rec['percentual_evaporacao'], 2, ',', ''), '0'), ',') : '0' ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Rende em</label>
                    <select name="unidade_rendimento" class="form-select">
                        <?php $unSel = $rec['unidade_rendimento'] ?? 'G'; foreach ($unidades as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $unSel === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Ingredientes</strong>
                    <button type="button" class="btn btn-sm btn-outline-success" id="add-linha">+ Ingrediente</button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:45%">Item do estoque</th>
                                <th class="text-end" style="width:20%">Quantidade</th>
                                <th class="text-end" style="width:20%">Custo</th>
                                <th style="width:15%"></th>
                            </tr>
                        </thead>
                        <tbody id="linhas"></tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td class="text-end">Peso total: <span id="peso-total">0</span></td>
                                <td class="text-end">Peso final: <span id="peso-final">0</span></td>
                                <td class="text-end">Custo: <span id="custo-total">R$ 0,00</span></td>
                                <td class="text-end">Custo/un: <span id="custo-un">—</span></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Modo de preparo (opcional)</label>
                <input type="hidden" name="preparo" id="preparo">
                <div id="editor-preparo"><?= $rec['preparo'] ?? '' ?></div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary">Salvar receita</button>
                <?php if ($id > 0): ?>
                    <a href="ficha_pdf.php?tipo=receita&id=<?= $id ?>&modo=producao" target="_blank" class="btn btn-outline-secondary">📄 PDF de produção</a>
                    <a href="ficha_pdf.php?tipo=receita&id=<?= $id ?>&modo=custo" target="_blank" class="btn btn-outline-secondary">💰 PDF com custos</a>
                    <a href="controller_ficha.php?acao=receita_snapshot&id=<?= $id ?>" class="btn btn-outline-dark js-confirm" data-msg="Congelar o custo atual desta receita no histórico?">Registrar custo agora</a>
                    <a href="controller_ficha.php?acao=receita_excluir&id=<?= $id ?>" class="btn btn-outline-danger js-confirm" data-msg="Remover esta receita?">Excluir</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($historico): ?>
        <div class="card mt-4">
            <div class="card-header"><strong>Histórico de custo</strong> <span class="text-muted small">(congela a cada mudança de preço no estoque)</span></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Data</th><th class="text-end">Custo total</th><th class="text-end">Custo/un</th><th>Motivo</th><th>Por</th></tr></thead>
                    <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></td>
                            <td class="text-end"><?= $reais($h['custo']) ?></td>
                            <td class="text-end"><?= $h['custo_por_g'] !== null ? 'R$ ' . number_format((float) $h['custo_por_g'], 4, ',', '.') : '—' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($h['motivo'] === 'preco' ? 'mudança de preço' : ($h['motivo'] ?? '—')) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($h['responsavel'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
// Editor rich text do modo de preparo: joga o HTML no campo escondido ao enviar.
(function () {
    const quillPrep = new Quill('#editor-preparo', {
        theme: 'snow',
        placeholder: 'Passo a passo do preparo…',
        modules: {
            toolbar: [
                ['bold', 'italic', 'underline'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['clean'],
            ],
        },
    });
    const form = document.getElementById('form-receita');
    form.addEventListener('submit', function () {
        const vazio = quillPrep.getText().trim() === '';
        document.getElementById('preparo').value = vazio ? '' : quillPrep.root.innerHTML;
    });
})();
</script>
<script>
const MAPA_CUSTO = <?= json_encode($mapaCusto, JSON_UNESCAPED_UNICODE) ?>;
const ITENS = <?= json_encode(array_map(fn($it) => ['id' => (int) $it['id'], 'nome' => $it['nome']], $itensEstoque), JSON_UNESCAPED_UNICODE) ?>;
const LINHAS_EXISTENTES = <?= json_encode(array_map(fn($l) => ['item_id' => (int) $l['item_id'], 'quantidade' => (float) $l['quantidade']], $linhas), JSON_UNESCAPED_UNICODE) ?>;

// Parser BR (vírgula decimal, ponto milhar) — espelha estoque_num_manual do PHP.
function numBR(v) {
    v = String(v || '').trim().replace(/[^\d.,-]/g, '');
    if (v === '') return 0;
    if (v.indexOf(',') !== -1) { v = v.replace(/\./g, '').replace(',', '.'); }
    else if ((v.match(/\./g) || []).length > 1) { v = v.replace(/\./g, ''); }
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
}
const fmtNum = n => (Math.round(n * 1000) / 1000).toLocaleString('pt-BR');
const fmtReais = n => 'R$ ' + n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

const optionsHtml = sel => '<option value="">— escolha o item —</option>' +
    ITENS.map(i => `<option value="${i.id}" ${i.id === sel ? 'selected' : ''}>${i.nome.replace(/</g,'&lt;')}</option>`).join('');

function novaLinha(itemId = 0, qtd = '') {
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><select name="item_id[]" class="form-select form-select-sm sel-item">${optionsHtml(itemId)}</select></td>
        <td class="text-end"><input type="text" name="quantidade[]" class="form-control form-control-sm text-end inp-qtd" inputmode="decimal" value="${qtd}"></td>
        <td class="text-end custo-linha">—</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger del-linha">×</button></td>`;
    document.getElementById('linhas').appendChild(tr);
    tr.querySelector('.sel-item').addEventListener('change', recalc);
    tr.querySelector('.inp-qtd').addEventListener('input', recalc);
    tr.querySelector('.del-linha').addEventListener('click', () => { tr.remove(); recalc(); });
    return tr;
}

function recalc() {
    let custoTotal = 0, pesoTotal = 0;
    document.querySelectorAll('#linhas tr').forEach(tr => {
        const id = parseInt(tr.querySelector('.sel-item').value, 10) || 0;
        const qtd = numBR(tr.querySelector('.inp-qtd').value);
        pesoTotal += qtd;
        const info = MAPA_CUSTO[id];
        const cel = tr.querySelector('.custo-linha');
        if (info && info.valor != null) {
            const c = qtd * info.valor;
            custoTotal += c;
            cel.textContent = fmtReais(c);
        } else {
            cel.textContent = id ? '(sem preço)' : '—';
        }
    });
    const evap = numBR(document.getElementById('f-evap').value);
    const pesoFinal = pesoTotal * (1 - evap / 100);
    document.getElementById('peso-total').textContent = fmtNum(pesoTotal);
    document.getElementById('peso-final').textContent = fmtNum(pesoFinal);
    document.getElementById('custo-total').textContent = fmtReais(custoTotal);
    document.getElementById('custo-un').textContent = pesoFinal > 0
        ? 'R$ ' + (custoTotal / pesoFinal).toLocaleString('pt-BR', {minimumFractionDigits: 4, maximumFractionDigits: 4})
        : '—';
}

document.getElementById('add-linha').addEventListener('click', () => novaLinha());
document.getElementById('f-evap').addEventListener('input', recalc);
if (LINHAS_EXISTENTES.length) {
    LINHAS_EXISTENTES.forEach(l => novaLinha(l.item_id, String(l.quantidade).replace('.', ',')));
} else {
    novaLinha();
}
recalc();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
