<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';

$configurado = financeiro_configurado();

$flash = null;
if (isset($_SESSION['financeiro_flash'])) {
    $flash = $_SESSION['financeiro_flash'];
    unset($_SESSION['financeiro_flash']);
}
$teste = null;
if (isset($_SESSION['financeiro_teste'])) {
    $teste = $_SESSION['financeiro_teste'];
    unset($_SESSION['financeiro_teste']);
}

// Nota em revisão (após upload do XML).
$revisao = $_SESSION['financeiro_revisao'] ?? null;

// Se há revisão, busca os cadastros para montar os campos.
$listas = ['contas' => [], 'categorias' => [], 'fornecedores' => [], 'formas' => [], 'centros' => []];
$fornecedoresFull = [];
$erroListas = '';
if ($revisao && $configurado) {
    try {
        $api = financeiro_api();
        $fornecedoresFull       = financeiro_extrair_lista($api->listarFornecedores());
        $listas['contas']       = financeiro_nomes($api->listarContas());
        $listas['categorias']   = financeiro_nomes($api->listarCategorias());
        $listas['fornecedores'] = financeiro_nomes($api->listarFornecedores());
        $listas['formas']       = financeiro_nomes($api->listarFormasPagamento());
        $listas['centros']      = financeiro_nomes($api->listarCentrosCusto());
    } catch (\Throwable $e) {
        $erroListas = $e->getMessage();
    }
}

$page_title = 'Financeiro';
$active = 'financeiro';
$extra_css = '
        .status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .mono { font-family: monospace; font-size: 0.85rem; }';
require __DIR__ . '/_header.php';

// helper local para <datalist>
$datalist = function (string $id, array $opts): string {
    $h = "<datalist id=\"$id\">";
    foreach ($opts as $o) { $h .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '"></option>'; }
    return $h . '</datalist>';
};
?>
        <h1 class="mb-2">Financeiro — Contas a pagar</h1>
        <p class="text-muted mb-4">
            Importe notas fiscais (XML) e lance como contas a pagar no Cardápio Web,
            sem digitar uma por uma. As contas a receber continuam sendo geradas pelo sistema de pedidos.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <!-- Status da integração -->
        <div class="card mb-4" style="max-width: 820px;">
            <div class="card-header fw-semibold">Status da integração</div>
            <div class="card-body">
                <?php if ($configurado): ?>
                    <p class="mb-3"><span class="status-dot bg-success"></span> Configurado.</p>
                    <form method="post" action="controller_financeiro.php?acao=testar" class="d-inline">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Testar conexão com o Cardápio Web</button>
                    </form>
                    <?php if ($teste): ?>
                        <div class="mt-3">
                            <?php if ($teste['ok']): ?>
                                <div class="alert alert-success mb-0">
                                    Conexão OK! Cadastros lidos: contas <strong><?= (int) $teste['contas'] ?></strong>,
                                    categorias <strong><?= (int) $teste['categorias'] ?></strong>,
                                    fornecedores <strong><?= (int) $teste['fornecedores'] ?></strong>,
                                    formas de pagamento <strong><?= (int) $teste['formas'] ?></strong>,
                                    centros de custo <strong><?= (int) $teste['centros'] ?></strong>.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger mb-0">Falha: <span class="mono"><?= htmlspecialchars($teste['erro']) ?></span></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="mb-2"><span class="status-dot bg-warning"></span> Ainda não configurado.</p>
                    <p class="mb-1">Crie <span class="mono">admin/config_financeiro.php</span> a partir do modelo
                       <span class="mono">.exemplo.php</span> e preencha:</p>
                    <ul class="mb-0">
                        <li><span class="mono">company_id</span> — id da empresa (header <span class="mono">companyid</span>)</li>
                        <li><span class="mono">refresh_token</span> — da resposta do login em <span class="mono">/auth/token</span>; <strong>segredo</strong></li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$configurado): ?>
            <div class="card" style="max-width: 820px;">
                <div class="card-body text-muted">Configure a integração acima para liberar a importação.</div>
            </div>

        <?php elseif (!$revisao): ?>
            <!-- Estado 1: upload do XML -->
            <div class="card" style="max-width: 820px;">
                <div class="card-header fw-semibold">Importar nota fiscal (XML)</div>
                <div class="card-body">
                    <form method="post" action="controller_financeiro.php?acao=upload" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
                        <input type="file" name="xml" accept=".xml,text/xml,application/xml" class="form-control" style="max-width: 420px;" required>
                        <button type="submit" class="btn btn-primary">Ler nota</button>
                    </form>
                    <p class="text-muted small mb-0 mt-2">Envie o XML da NF-e. Você confere e ajusta tudo antes de lançar — nada é enviado sem sua confirmação.</p>
                </div>
            </div>

        <?php else: ?>
            <!-- Estado 2: revisão antes de enviar -->
            <?php
            $l = $revisao['lancamento'];
            $cnpjForn = $revisao['fornecedor']['cnpj'] ?? '';
            $regra = financeiro_regra_buscar($cnpjForn);
            $regraAplicada = false;

            // 1) Classificação de gestão (conta/categoria/centro) — aprendida por fornecedor.
            //    Forma de pagamento NÃO entra aqui: vem sempre da nota (tPag).
            if ($regra) {
                foreach (['account', 'category', 'cost_center'] as $campo) {
                    if (!empty($regra[$campo])) {
                        $l[$campo] = $regra[$campo];
                        $regraAplicada = true;
                    }
                }
            }

            // 2) Fornecedor: reusa o cadastro certo em vez de duplicar a razão social.
            //    Prioridade: regra aprendida > casamento por CNPJ/semelhança > razão social.
            $fornMatch = 'nenhum';
            if ($regra && !empty($regra['supplier'])) {
                $l['supplier'] = $regra['supplier'];
                $fornMatch = 'regra';
            } elseif (!empty($fornecedoresFull)) {
                $casa = financeiro_casar_fornecedor($cnpjForn, $revisao['fornecedor']['nome'] ?? '', $fornecedoresFull);
                if ($casa['match'] !== 'nenhum' && $casa['name'] !== '') {
                    $l['supplier'] = $casa['name'];
                    $fornMatch = $casa['match'];
                }
            }
            ?>
            <div class="card" style="max-width: 820px;">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Revisar lançamento — NF-e <?= htmlspecialchars($revisao['numero']) ?></span>
                    <span class="badge bg-light text-dark">Fornecedor: <?= htmlspecialchars($revisao['fornecedor']['nome']) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($erroListas): ?>
                        <div class="alert alert-warning">Não foi possível carregar seus cadastros (<span class="mono"><?= htmlspecialchars($erroListas) ?></span>). Você ainda pode digitar os campos manualmente.</div>
                    <?php endif; ?>
                    <?php foreach ($revisao['avisos'] as $aviso): ?>
                        <div class="alert alert-warning py-2"><?= htmlspecialchars($aviso) ?></div>
                    <?php endforeach; ?>
                    <?php if ($regraAplicada): ?>
                        <div class="alert alert-info py-2">✓ Classificação preenchida automaticamente com base em compras anteriores deste fornecedor. Confira e ajuste se necessário.</div>
                    <?php endif; ?>

                    <form method="post" action="controller_financeiro.php?acao=importar">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Conta <span class="text-danger">*</span></label>
                                <select name="account" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($listas['contas'] as $c): ?>
                                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= ($l['account'] === $c ? 'selected' : '') ?>><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Obrigatório e precisa já existir no Cardápio Web.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Valor (R$)</label>
                                <div class="input-group">
                                    <span class="input-group-text">R$</span>
                                    <input type="text" name="value" class="form-control" value="<?= htmlspecialchars(financeiro_valor_br($revisao['valor_total'])) ?>" required>
                                </div>
                                <div class="form-text">Despesa (conta a pagar). Use vírgula para os centavos.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Forma de pagamento</label>
                                <input type="text" name="payment_method" class="form-control" list="dl-formas" value="<?= htmlspecialchars($l['payment_method']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Categoria</label>
                                <input type="text" name="category" class="form-control" list="dl-categorias" value="<?= htmlspecialchars($l['category']) ?>">
                                <div class="form-text">Se não existir, o Cardápio Web cria automaticamente.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Centro de custo</label>
                                <input type="text" name="cost_center" class="form-control" list="dl-centros" value="<?= htmlspecialchars($l['cost_center']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Fornecedor</label>
                                <input type="text" name="supplier" class="form-control" list="dl-fornecedores" value="<?= htmlspecialchars($l['supplier']) ?>">
                                <div class="form-text">
                                    <?php if ($fornMatch === 'cnpj' || $fornMatch === 'regra'): ?>
                                        ✓ Fornecedor já cadastrado (encontrado pelo CNPJ).
                                    <?php elseif ($fornMatch === 'nome'): ?>
                                        ✓ Correspondência por semelhança com um fornecedor existente — confira se é o mesmo.
                                    <?php else: ?>
                                        Fornecedor novo (não encontrado no cadastro). Da nota: <span class="mono"><?= htmlspecialchars($revisao['fornecedor']['nome']) ?></span>.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Descrição</label>
                                <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($l['description']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Vencimento</label>
                                <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($l['due_date']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Competência</label>
                                <input type="date" name="competence_date" class="form-control" value="<?= htmlspecialchars($l['competence_date']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data de pagamento</label>
                                <input type="date" name="settlement_date" class="form-control" value="<?= htmlspecialchars($l['settlement_date']) ?>">
                                <div class="form-text">Deixe vazio se ainda não foi paga.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Observação</label>
                                <input type="text" name="observation" class="form-control" value="<?= htmlspecialchars($l['observation']) ?>">
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-success">Enviar ao Cardápio Web</button>
                            <a href="controller_financeiro.php?acao=cancelar" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </form>

                    <?= $datalist('dl-categorias', $listas['categorias']) ?>
                    <?= $datalist('dl-centros', $listas['centros']) ?>
                    <?= $datalist('dl-fornecedores', $listas['fornecedores']) ?>
                    <?= $datalist('dl-formas', $listas['formas']) ?>

                    <details class="mt-3">
                        <summary class="text-muted small">Ver dados da nota (itens, chave)</summary>
                        <div class="small text-muted mt-2">
                            Chave: <span class="mono"><?= htmlspecialchars($revisao['chave']) ?></span><br>
                            Emissão: <?= htmlspecialchars($revisao['emissao']) ?> · Natureza: <?= htmlspecialchars($revisao['natureza_operacao']) ?><br>
                            Itens:
                            <ul class="mb-0">
                                <?php foreach ($revisao['itens'] as $it): ?>
                                    <li><?= htmlspecialchars($it['descricao']) ?> — <?= htmlspecialchars($it['quantidade']) ?> × R$ <?= htmlspecialchars($it['valor']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </details>
                </div>
            </div>
        <?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
