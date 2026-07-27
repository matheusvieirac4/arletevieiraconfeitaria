<?php
// Catálogo — formulário de produto (novo/edição) com faixas de preço.
require_once __DIR__ . '/_auth.php';
require_once 'model_catalogo.php';

$id = (int) ($_GET['id'] ?? 0);

if ($id > 0) {
    $produto = catalogo_produto_por_id($pdo, $id);
    if (!$produto) {
        $_SESSION['catalogo_flash'] = ['tipo' => 'danger', 'texto' => 'Produto não encontrado.'];
        header('Location: catalogo.php');
        exit;
    }
    $sub = catalogo_subcategoria_por_id($pdo, (int) $produto['subcategoria_id']);
    $catId = (int) ($sub['categoria_id'] ?? 0);
    $subIdSel = (int) $produto['subcategoria_id'];
} else {
    $produto  = ['id' => 0, 'nome' => '', 'descricao' => '', 'imagem' => '', 'instrucao_pedido' => '',
                 'preco_rotulo' => '', 'ordem' => 0, 'ativo' => 1, 'precos' => []];
    $catId    = (int) ($_GET['categoria_id'] ?? 0);
    $subIdSel = (int) ($_GET['subcategoria_id'] ?? 0);
}

$categoria = $catId > 0 ? catalogo_categoria_por_id($pdo, $catId) : null;
if (!$categoria) {
    $_SESSION['catalogo_flash'] = ['tipo' => 'danger', 'texto' => 'Categoria inválida.'];
    header('Location: catalogo.php');
    exit;
}
$subs = catalogo_subcategorias_listar($pdo, $catId);

$flash = $_SESSION['catalogo_flash'] ?? null;
unset($_SESSION['catalogo_flash']);

$page_title = 'Catálogo · ' . ($id > 0 ? 'Editar produto' : 'Novo produto');
$active = 'catalogo';
require __DIR__ . '/_header.php';
?>
        <div class="mb-3">
            <a href="catalogo.php?cat=<?= $catId ?>" class="text-decoration-none text-muted small">&larr; <?= htmlspecialchars($categoria['nome']) ?></a>
        </div>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
            <h1 class="mb-0"><?= $id > 0 ? htmlspecialchars($produto['nome']) : 'Novo produto' ?></h1>
            <span class="badge bg-light text-dark"><?= $id > 0 ? 'Editando' : 'Novo produto' ?> · <?= htmlspecialchars($categoria['nome']) ?></span>
        </div>

        <form method="post" action="controller_catalogo.php?acao=produto_salvar" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">
            <input type="hidden" name="categoria_id" value="<?= $catId ?>">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($produto['nome']) ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Subcategoria</label>
                                <select name="subcategoria_id" class="form-select" required>
                                    <option value="">— selecione —</option>
                                    <?php foreach ($subs as $s): ?>
                                        <option value="<?= (int) $s['id'] ?>" <?= $subIdSel === (int) $s['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label mb-0">Descrição</label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-ia-desc">
                                        <i class="align-middle" data-feather="zap"></i> Gerar com IA
                                    </button>
                                </div>
                                <textarea name="descricao" id="prod-descricao" class="form-control" rows="3" placeholder="Ex.: massa branca, recheio de brigadeiro…"><?= htmlspecialchars($produto['descricao']) ?></textarea>
                                <div class="form-text" id="ia-desc-msg">A IA usa o nome, a categoria e o que você já escreveu como base. Revise antes de salvar.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Instrução de pedido</label>
                                <input type="text" name="instrucao_pedido" class="form-control" value="<?= htmlspecialchars($produto['instrucao_pedido']) ?>" placeholder="Ex.: Mínimo 20 unidades por sabor.">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Foto</label>
                                <?php if (!empty($produto['imagem'])): ?>
                                    <div class="mb-2"><img src="../<?= htmlspecialchars(ltrim($produto['imagem'], '/')) ?>" style="max-height:140px;" class="rounded border" alt=""></div>
                                <?php endif; ?>
                                <input type="file" name="imagem_arquivo" class="form-control" accept="image/*">
                                <div class="form-text"><?= !empty($produto['imagem']) ? 'Envie outra para trocar.' : 'JPG, PNG ou WEBP (até 6 MB).' ?></div>
                            </div>
                            <div class="row g-2">
                                <div class="col-7">
                                    <label class="form-label">Rótulo do preço</label>
                                    <input type="text" name="preco_rotulo" class="form-control" value="<?= htmlspecialchars($produto['preco_rotulo']) ?>" placeholder="valor para o cento">
                                    <div class="form-text">Aparece após o “A partir de”.</div>
                                </div>
                                <div class="col-3">
                                    <label class="form-label">Ordem</label>
                                    <input type="number" name="ordem" class="form-control" value="<?= (int) $produto['ordem'] ?>">
                                </div>
                                <div class="col-2 d-flex align-items-end pb-2">
                                    <div class="form-check">
                                        <input type="checkbox" name="ativo" class="form-check-input" id="p-ativo" <?= $produto['ativo'] ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="p-ativo">Ativo</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Faixas de preço -->
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Preços</span>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addFaixa()">+ Adicionar faixa</button>
                </div>
                <div class="card-body">
                    <p class="text-muted small">Rótulo é livre: “100 unidades”, “25 fatias”, “Pequeno c/ recheio”. <strong>De/por</strong> (combos): preencha “valor cheio”. O card mostra o menor valor como “A partir de”.</p>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr>
                                <th style="min-width:160px;">Rótulo</th>
                                <th style="min-width:110px;">Valor (R$)</th>
                                <th style="min-width:120px;">Valor cheio <span class="text-muted small">(de/por)</span></th>
                                <th style="min-width:180px;">Observação</th>
                                <th></th>
                            </tr></thead>
                            <tbody id="faixas-body"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 my-4">
                <button type="submit" class="btn btn-success">Salvar produto</button>
                <a href="catalogo.php?cat=<?= $catId ?>" class="btn btn-outline-secondary">Cancelar</a>
            </div>
        </form>

<script>
const FAIXAS_INICIAIS = <?= json_encode(array_map(function ($p) {
    return [
        'rotulo' => $p['rotulo'] ?? '',
        'valor'  => $p['valor'] !== null ? number_format((float) $p['valor'], 2, ',', '') : '',
        'cheio'  => !empty($p['valor_cheio']) ? number_format((float) $p['valor_cheio'], 2, ',', '') : '',
        'obs'    => $p['obs'] ?? '',
    ];
}, $produto['precos']), JSON_UNESCAPED_UNICODE) ?>;

function faixaRow(f) {
    f = f || {rotulo:'', valor:'', cheio:'', obs:''};
    const esc = s => String(s == null ? '' : s).replace(/"/g, '&quot;');
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td><input type="text" name="faixa_rotulo[]" class="form-control form-control-sm" value="' + esc(f.rotulo) + '" placeholder="100 unidades"></td>' +
        '<td><input type="text" name="faixa_valor[]" class="form-control form-control-sm" value="' + esc(f.valor) + '" inputmode="decimal" placeholder="145,00"></td>' +
        '<td><input type="text" name="faixa_cheio[]" class="form-control form-control-sm" value="' + esc(f.cheio) + '" inputmode="decimal" placeholder="—"></td>' +
        '<td><input type="text" name="faixa_obs[]" class="form-control form-control-sm" value="' + esc(f.obs) + '" placeholder="escolha até 5 variações"></td>' +
        '<td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'tr\').remove()">✕</button></td>';
    return tr;
}
function addFaixa(f) { document.getElementById('faixas-body').appendChild(faixaRow(f)); }

(function () {
    if (FAIXAS_INICIAIS.length) { FAIXAS_INICIAIS.forEach(addFaixa); }
    else { addFaixa(); }
})();
</script>
<script>
(function () {
    const btn = document.getElementById('btn-ia-desc');
    if (!btn) { return; }
    const CAT_NOME = <?= json_encode($categoria['nome'], JSON_UNESCAPED_UNICODE) ?>;
    btn.addEventListener('click', async function () {
        const nome = (document.querySelector('input[name="nome"]').value || '').trim();
        const msg = document.getElementById('ia-desc-msg');
        const ta  = document.getElementById('prod-descricao');
        if (!nome) { msg.textContent = 'Escreva o nome do produto antes de gerar.'; return; }
        const subSel = document.querySelector('select[name="subcategoria_id"]');
        const sub = (subSel && subSel.selectedIndex > 0) ? subSel.options[subSel.selectedIndex].text.trim() : '';
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Gerando…';
        msg.textContent = '';
        try {
            const fd = new FormData();
            fd.append('nome', nome);
            fd.append('categoria', CAT_NOME);
            fd.append('subcategoria', sub);
            fd.append('contexto', ta.value.trim());
            const r = await fetch('catalogo_desc_ia.php', { method: 'POST', body: fd });
            const d = await r.json();
            if (d.error) { msg.textContent = d.error; }
            else if (d.descricao) { ta.value = d.descricao; msg.textContent = 'Sugestão da IA — revise e ajuste antes de salvar.'; }
            else { msg.textContent = 'A IA não retornou descrição.'; }
        } catch (e) {
            msg.textContent = 'Falha ao gerar: ' + e;
        } finally {
            btn.disabled = false;
            btn.innerHTML = orig;
            if (window.feather) { feather.replace(); }
        }
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
