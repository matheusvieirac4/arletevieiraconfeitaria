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

$categorias = ficha_categorias_nomes($pdo, 'produto');
$cfg = ficha_config_get($pdo);
$rawPct = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 3, ',', ''), '0'), ',');
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
                    <label class="form-label">Categoria <a href="ficha_categorias.php?tipo=produto" class="small text-decoration-none" title="Gerenciar categorias">(gerenciar)</a></label>
                    <?php $catAtual = $prod['categoria'] ?? ''; ?>
                    <select name="categoria" class="form-select">
                        <option value="">— sem categoria —</option>
                        <?php foreach ($categorias as $cn): ?>
                            <option value="<?= htmlspecialchars($cn, ENT_QUOTES) ?>" <?= $catAtual === $cn ? 'selected' : '' ?>><?= htmlspecialchars($cn) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Markup Direta <span class="text-muted small">(padrão <?= number_format($cfg['markup_direta_padrao'], 2, ',', '.') ?>%)</span></label>
                    <div class="input-group">
                        <input type="text" name="markup_direta" id="f-mkdir" class="form-control text-end" inputmode="decimal" placeholder="<?= number_format($cfg['markup_direta_padrao'], 2, ',', '') ?>" value="<?= $rawPct($prod['markup_direta'] ?? null) ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
            </div>
            <div class="row g-3 mb-4 align-items-end">
                <div class="col-6 col-md-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="vende_ifood" value="0">
                        <input type="checkbox" name="vende_ifood" value="1" id="f-vendeifood" class="form-check-input" <?= (!$prod || (int) ($prod['vende_ifood'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="f-vendeifood">Vende no iFood</label>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label">Markup iFood <span class="text-muted small">(padrão <?= number_format($cfg['markup_ifood_padrao'], 2, ',', '.') ?>%)</span></label>
                    <div class="input-group">
                        <input type="text" name="markup_ifood" id="f-mkif" class="form-control text-end" inputmode="decimal" placeholder="<?= number_format($cfg['markup_ifood_padrao'], 2, ',', '') ?>" value="<?= $rawPct($prod['markup_ifood'] ?? null) ?>">
                        <span class="input-group-text">%</span>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="small text-muted">Markups em branco usam o padrão do negócio. Ajuste as taxas e o overhead em <a href="ficha_config.php">⚙️ Precificação</a>.</div>
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
                    <div><span class="text-muted d-block small">Custo do prato</span><span class="fs-5 fw-bold" id="custo-total">R$ 0,00</span></div>
                    <div><span class="text-muted d-block small">Custo + fixo/var</span><span class="fs-5" id="custo-overhead">—</span></div>
                    <div class="border-start ps-4"><span class="text-muted d-block small">Preço Direta</span><span class="fs-4 fw-bold text-success" id="preco-direta">—</span></div>
                    <div><span class="text-muted d-block small">Margem contrib. Direta</span><span class="fs-5 fw-bold" id="margem-direta">—</span></div>
                    <div class="border-start ps-4" id="box-ifood"><span class="text-muted d-block small">Preço iFood</span><span class="fs-4 fw-bold" id="preco-ifood">—</span></div>
                    <div id="box-ifood2"><span class="text-muted d-block small">Margem contrib. iFood</span><span class="fs-5 fw-bold" id="margem-ifood">—</span></div>
                    <div class="border-start ps-4"><span class="text-muted d-block small">CMV</span><span class="fs-5 fw-bold" id="cmv-show">—</span></div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-primary">Salvar produto</button>
                <?php if ($id > 0): ?>
                    <a href="ficha_pdf.php?tipo=produto&id=<?= $id ?>&modo=producao" target="_blank" class="btn btn-outline-secondary">📄 PDF de produção</a>
                    <a href="ficha_pdf.php?tipo=produto&id=<?= $id ?>&modo=custo" target="_blank" class="btn btn-outline-secondary">💰 PDF com custos</a>
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
                    <thead><tr><th>Data</th><th class="text-end">Custo</th><th class="text-end">Preço</th><th class="text-end">CMV</th><th>Motivo</th><th>Por</th></tr></thead>
                    <tbody>
                    <?php foreach ($historico as $h): ?>
                        <tr>
                            <td><?= date('d/m/Y H:i', strtotime($h['criado_em'])) ?></td>
                            <td class="text-end"><?= $reais($h['custo']) ?></td>
                            <td class="text-end"><?= $reais($h['preco_venda']) ?></td>
                            <td class="text-end"><?= $h['cmv_pct'] !== null ? number_format((float) $h['cmv_pct'], 1, ',', '.') . '%' : '—' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars(($h['motivo'] ?? '') === 'preco' ? 'mudança de preço' : ($h['motivo'] ?? '—')) ?></td>
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
const CFG = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE) ?>;

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
    precificar(custoTotal);
}

const pct1 = n => n.toLocaleString('pt-BR', {minimumFractionDigits: 1, maximumFractionDigits: 1}) + '%';
const corMargem = m => m < 0 ? 'text-danger' : (m >= 30 ? 'text-success' : (m >= 15 ? 'text-warning' : 'text-danger'));

// Replica ficha_precificar() do PHP: C = B/(1-overhead); Direta = C/(1-mkDir);
// iFood = C*(1+mkIf)/(1-taxasIfood). Margem de contribuição em % (lucro/preço).
function precificar(B) {
    const overhead = CFG.overhead_pct / 100;
    const taxasIfood  = (CFG.taxa_cartao + CFG.taxa_ifood + CFG.taxa_imposto + CFG.taxa_pgto_app) / 100;
    const taxasDireta = (CFG.taxa_cartao + CFG.taxa_imposto + CFG.taxa_pgto_app) / 100;
    const mkDir = (numBR(document.getElementById('f-mkdir').value) || CFG.markup_direta_padrao) / 100;
    const mkIf  = (numBR(document.getElementById('f-mkif').value)  || CFG.markup_ifood_padrao) / 100;
    const vendeIfood = document.getElementById('f-vendeifood').checked;

    const C = overhead < 1 ? B / (1 - overhead) : null;
    document.getElementById('custo-overhead').textContent = C != null ? fmtReais(C) : '—';

    // Direta.
    const precoDireta = (C != null && mkDir < 1) ? C / (1 - mkDir) : null;
    const elPD = document.getElementById('preco-direta'), elMD = document.getElementById('margem-direta');
    const elCmv = document.getElementById('cmv-show');
    if (precoDireta != null && precoDireta > 0) {
        elPD.textContent = fmtReais(precoDireta);
        const lucro = precoDireta - (C + precoDireta * taxasDireta);
        const m = lucro / precoDireta * 100;
        elMD.textContent = pct1(m); elMD.className = 'fs-5 fw-bold ' + corMargem(m);
        const cmv = B / precoDireta * 100;
        elCmv.textContent = pct1(cmv); elCmv.className = 'fs-5 fw-bold ' + (cmv <= 35 ? 'text-success' : (cmv <= 45 ? 'text-warning' : 'text-danger'));
    } else {
        elPD.textContent = '—'; elMD.textContent = '—'; elMD.className = 'fs-5 fw-bold';
        elCmv.textContent = '—'; elCmv.className = 'fs-5 fw-bold';
    }

    // iFood.
    const b1 = document.getElementById('box-ifood'), b2 = document.getElementById('box-ifood2');
    const elPI = document.getElementById('preco-ifood'), elMI = document.getElementById('margem-ifood');
    if (vendeIfood && C != null && taxasIfood < 1) {
        b1.style.display = ''; b2.style.display = '';
        const F = C * (1 + mkIf);
        const precoIfood = F / (1 - taxasIfood);
        elPI.textContent = fmtReais(precoIfood);
        const lucro = precoIfood - (C + F * taxasIfood);
        const m = precoIfood > 0 ? lucro / precoIfood * 100 : 0;
        elMI.textContent = pct1(m); elMI.className = 'fs-5 fw-bold ' + corMargem(m);
    } else {
        b1.style.display = 'none'; b2.style.display = 'none';
    }
}

document.querySelectorAll('.add-linha').forEach(btn =>
    btn.addEventListener('click', () => novaLinha(btn.dataset.bloco)));
['f-mkdir', 'f-mkif'].forEach(id => document.getElementById(id).addEventListener('input', recalc));
document.getElementById('f-vendeifood').addEventListener('change', recalc);

PRE_INGRED.forEach(c => novaLinha('ingrediente', c.ref, String(c.qtd).replace('.', ',')));
PRE_RECH.forEach(c => novaLinha('recheio', c.ref, String(c.qtd).replace('.', ',')));
if (!PRE_INGRED.length) novaLinha('ingrediente');
if (!PRE_RECH.length) novaLinha('recheio');
recalc();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
