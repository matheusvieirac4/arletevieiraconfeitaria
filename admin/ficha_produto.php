<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$page_title = 'Produto';
$active = 'ficha_produtos';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Produto</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
$prod = $id > 0 ? ficha_produto_buscar($pdo, $id) : null;
if ($id > 0 && !$prod) {
    require __DIR__ . '/_header.php';
    echo '<div class="alert alert-warning">Produto não encontrado.</div>';
    require __DIR__ . '/_footer.php';
    exit;
}
$comps = $id > 0 ? ficha_produto_componentes($pdo, $id) : [];
$historico = $id > 0 ? ficha_cmv_historico($pdo, $id, 20) : [];

// Itens do estoque (para o bloco Ingredientes) com custo/base.
$itensEstoque = estoque_listar($pdo);
$mapaItem = [];
foreach ($itensEstoque as $it) {
    $pb = estoque_preco_por_base($it);
    $mapaItem[(int) $it['id']] = [
        'valor'  => $pb['valor'] ?? ($it['preco'] !== null ? (float) $it['preco'] : null),
        'rotulo' => $pb['rotulo'] ?? 'un',
    ];
}
// Receitas (para o bloco Recheios) com custo/g já calculado.
$receitas = ficha_receitas_listar($pdo);
$mapaReceita = [];
foreach ($receitas as $r) {
    $rc = ficha_receita_custo($pdo, (int) $r['id']);
    $mapaReceita[(int) $r['id']] = [
        'custo_g' => $rc['custo_por_g'],
        'rotulo'  => strtolower($r['unidade_rendimento'] ?? 'g'),
    ];
}

// Separa os componentes salvos por bloco, para pré-preencher os dois quadros.
$compIngred = array_values(array_filter($comps, fn($c) => $c['bloco'] === 'ingrediente'));
$compRech   = array_values(array_filter($comps, fn($c) => $c['bloco'] === 'recheio'));

$reais = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');
$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <div class="d-flex align-items-center gap-2 mb-3">
            <a href="ficha_produtos.php" class="btn btn-outline-secondary btn-sm">&larr; Produtos</a>
            <h1 class="mb-0 fs-3"><?= $id > 0 ? htmlspecialchars($prod['nome']) : 'Novo produto' ?></h1>
        </div>

        <form method="post" action="controller_ficha.php?acao=produto_salvar">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <label class="form-label">Nome do produto</label>
                    <input type="text" name="nome" class="form-control" required value="<?= htmlspecialchars($prod['nome'] ?? '') ?>" placeholder="Ex.: BOMBOM DE MORANGO">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Categoria</label>
                    <input type="text" name="categoria" class="form-control" value="<?= htmlspecialchars($prod['categoria'] ?? '') ?>" placeholder="Ex.: Bombom">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Preço de venda</label>
                    <div class="input-group">
                        <span class="input-group-text">R$</span>
                        <input type="text" name="preco_venda" id="f-preco" class="form-control text-end" inputmode="decimal" value="<?= $prod && $prod['preco_venda'] !== null ? number_format((float) $prod['preco_venda'], 2, ',', '') : '' ?>">
                    </div>
                </div>
            </div>

            <?php
            // Renderiza um quadro (Ingredientes ou Recheios) reutilizando o mesmo HTML.
            $quadro = function (string $bloco, string $titulo, string $rotuloRef) { ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($titulo) ?></strong>
                    <button type="button" class="btn btn-sm btn-outline-success add-linha" data-bloco="<?= $bloco ?>">+ <?= htmlspecialchars($rotuloRef) ?></button>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:50%"><?= htmlspecialchars($rotuloRef) ?></th>
                                <th class="text-end" style="width:20%">Quantidade</th>
                                <th class="text-end" style="width:20%">Custo</th>
                                <th style="width:10%"></th>
                            </tr>
                        </thead>
                        <tbody class="linhas" data-bloco="<?= $bloco ?>"></tbody>
                        <tfoot>
                            <tr class="fw-semibold"><td colspan="2" class="text-end">Subtotal</td><td class="text-end subtotal">R$ 0,00</td><td></td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php };
            $quadro('ingrediente', 'Ingredientes (embalagens e itens diretos)', 'Item do estoque');
            $quadro('recheio', 'Recheios (receitas)', 'Receita');
            ?>

            <div class="card mb-3 bg-light">
                <div class="card-body d-flex flex-wrap gap-4 align-items-center">
                    <div><span class="text-muted d-block small">Custo total</span><span class="fs-4 fw-bold" id="custo-total">R$ 0,00</span></div>
                    <div><span class="text-muted d-block small">Preço de venda</span><span class="fs-4" id="preco-show">—</span></div>
                    <div><span class="text-muted d-block small">Margem</span><span class="fs-4" id="margem-show">—</span></div>
                    <div><span class="text-muted d-block small">CMV</span><span class="fs-4 fw-bold" id="cmv-show">—</span></div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary">Salvar produto</button>
                <?php if ($id > 0): ?>
                    <a href="controller_ficha.php?acao=cmv_registrar&id=<?= $id ?>" class="btn btn-outline-dark js-confirm" data-msg="Congelar o custo/CMV atual no histórico?">Registrar CMV agora</a>
                    <a href="controller_ficha.php?acao=produto_excluir&id=<?= $id ?>" class="btn btn-outline-danger js-confirm" data-msg="Remover este produto?">Excluir</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($historico): ?>
        <div class="card mt-4">
            <div class="card-header"><strong>Histórico de CMV</strong> <span class="text-muted small">(últimos registros)</span></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Data</th><th class="text-end">Custo</th><th class="text-end">Preço</th><th class="text-end">CMV</th><th>Por</th></tr></thead>
                    <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></td>
                            <td class="text-end"><?= $reais($h['custo']) ?></td>
                            <td class="text-end"><?= $reais($h['preco_venda']) ?></td>
                            <td class="text-end"><?= $h['cmv_pct'] !== null ? number_format((float) $h['cmv_pct'], 1, ',', '.') . '%' : '—' ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($h['responsavel'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

<script>
const MAPA_ITEM = <?= json_encode($mapaItem, JSON_UNESCAPED_UNICODE) ?>;
const MAPA_RECEITA = <?= json_encode($mapaReceita, JSON_UNESCAPED_UNICODE) ?>;
const ITENS = <?= json_encode(array_map(fn($it) => ['id' => (int) $it['id'], 'nome' => $it['nome']], $itensEstoque), JSON_UNESCAPED_UNICODE) ?>;
const RECEITAS = <?= json_encode(array_map(fn($r) => ['id' => (int) $r['id'], 'nome' => $r['nome']], $receitas), JSON_UNESCAPED_UNICODE) ?>;
const PRE_INGRED = <?= json_encode(array_map(fn($c) => ['ref' => (int) $c['ref_id'], 'qtd' => (float) $c['quantidade']], $compIngred), JSON_UNESCAPED_UNICODE) ?>;
const PRE_RECH = <?= json_encode(array_map(fn($c) => ['ref' => (int) $c['ref_id'], 'qtd' => (float) $c['quantidade']], $compRech), JSON_UNESCAPED_UNICODE) ?>;

function numBR(v) {
    v = String(v || '').trim().replace(/[^\d.,-]/g, '');
    if (v === '') return 0;
    if (v.indexOf(',') !== -1) { v = v.replace(/\./g, '').replace(',', '.'); }
    else if ((v.match(/\./g) || []).length > 1) { v = v.replace(/\./g, ''); }
    const n = parseFloat(v);
    return isNaN(n) ? 0 : n;
}
const fmtReais = n => 'R$ ' + n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

function optionsHtml(lista, sel) {
    return '<option value="">— escolha —</option>' +
        lista.map(i => `<option value="${i.id}" ${i.id === sel ? 'selected' : ''}>${i.nome.replace(/</g,'&lt;')}</option>`).join('');
}

function custoLinha(bloco, ref, qtd) {
    if (bloco === 'ingrediente') {
        const info = MAPA_ITEM[ref];
        return (info && info.valor != null) ? qtd * info.valor : null;
    }
    const info = MAPA_RECEITA[ref];
    return (info && info.custo_g != null) ? qtd * info.custo_g : null;
}

function novaLinha(bloco, ref = 0, qtd = '') {
    const tbody = document.querySelector(`.linhas[data-bloco="${bloco}"]`);
    const lista = bloco === 'ingrediente' ? ITENS : RECEITAS;
    const tipo = bloco === 'ingrediente' ? 'item' : 'receita';
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="hidden" name="bloco[]" value="${bloco}">
            <input type="hidden" name="tipo[]" value="${tipo}">
            <select name="ref_id[]" class="form-select form-select-sm sel-ref">${optionsHtml(lista, ref)}</select>
        </td>
        <td class="text-end"><input type="text" name="quantidade[]" class="form-control form-control-sm text-end inp-qtd" inputmode="decimal" value="${qtd}"></td>
        <td class="text-end custo-linha">—</td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger del-linha">×</button></td>`;
    tbody.appendChild(tr);
    tr.querySelector('.sel-ref').addEventListener('change', recalc);
    tr.querySelector('.inp-qtd').addEventListener('input', recalc);
    tr.querySelector('.del-linha').addEventListener('click', () => { tr.remove(); recalc(); });
}

function recalc() {
    let custoTotal = 0;
    document.querySelectorAll('.linhas').forEach(tbody => {
        const bloco = tbody.dataset.bloco;
        let sub = 0;
        tbody.querySelectorAll('tr').forEach(tr => {
            const ref = parseInt(tr.querySelector('.sel-ref').value, 10) || 0;
            const qtd = numBR(tr.querySelector('.inp-qtd').value);
            const c = custoLinha(bloco, ref, qtd);
            const cel = tr.querySelector('.custo-linha');
            if (c != null) { sub += c; cel.textContent = fmtReais(c); }
            else { cel.textContent = ref ? '(sem preço)' : '—'; }
        });
        custoTotal += sub;
        tbody.parentElement.querySelector('.subtotal').textContent = fmtReais(sub);
    });
    document.getElementById('custo-total').textContent = fmtReais(custoTotal);
    const preco = numBR(document.getElementById('f-preco').value);
    const precoShow = document.getElementById('preco-show');
    const margemShow = document.getElementById('margem-show');
    const cmvShow = document.getElementById('cmv-show');
    if (preco > 0) {
        precoShow.textContent = fmtReais(preco);
        const margem = preco - custoTotal;
        margemShow.textContent = fmtReais(margem);
        margemShow.className = 'fs-4' + (margem < 0 ? ' text-danger fw-bold' : '');
        const cmv = (custoTotal / preco) * 100;
        cmvShow.textContent = cmv.toLocaleString('pt-BR', {minimumFractionDigits: 1, maximumFractionDigits: 1}) + '%';
        cmvShow.className = 'fs-4 fw-bold ' + (cmv <= 35 ? 'text-success' : (cmv <= 45 ? 'text-warning' : 'text-danger'));
    } else {
        precoShow.textContent = '—'; margemShow.textContent = '—'; cmvShow.textContent = '—';
        margemShow.className = cmvShow.className = 'fs-4';
    }
}

document.querySelectorAll('.add-linha').forEach(btn =>
    btn.addEventListener('click', () => novaLinha(btn.dataset.bloco)));
document.getElementById('f-preco').addEventListener('input', recalc);

PRE_INGRED.forEach(c => novaLinha('ingrediente', c.ref, String(c.qtd).replace('.', ',')));
PRE_RECH.forEach(c => novaLinha('recheio', c.ref, String(c.qtd).replace('.', ',')));
if (!PRE_INGRED.length) novaLinha('ingrediente');
if (!PRE_RECH.length) novaLinha('recheio');
recalc();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
