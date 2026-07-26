<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Estoque'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Estoque</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$id   = (int) ($_GET['id'] ?? 0);
$item = $id ? estoque_buscar($pdo, $id) : null;
if ($id && !$item) { header('Location: estoque.php'); exit; }
$movs = $id ? estoque_movimentacoes($pdo, $id) : [];

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$page_title = $item ? 'Item de estoque' : 'Novo item';
$active = 'estoque';
$fmt = fn($n) => $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
$fmt2 = fn($n) => $n === null || $n === '' ? '' : number_format((float) $n, 2, ',', '.');
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0"><?= $item ? htmlspecialchars($item['nome']) : 'Novo item' ?></h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header fw-semibold">Cadastro</div>
                    <div class="card-body">
                        <form method="post" action="controller_estoque.php?acao=salvar">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Nome <span class="text-danger">*</span></label>
                                    <input type="text" name="nome" class="form-control" value="<?= htmlspecialchars($item['nome'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Fornecedor</label>
                                    <input type="text" name="fornecedor" class="form-control" value="<?= htmlspecialchars($item['fornecedor'] ?? '') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Código de barras (unidade)</label>
                                    <input type="text" name="codigo_barras" class="form-control" value="<?= htmlspecialchars($item['codigo_barras'] ?? '') ?>" placeholder="escaneado na baixa">
                                    <div class="form-text">O que se lê no quiosque.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Código de compra (nota)</label>
                                    <input type="text" name="codigo_compra" class="form-control" value="<?= htmlspecialchars($item['codigo_compra'] ?? '') ?>" placeholder="código do fardo/nota">
                                    <div class="form-text">O que vem na nota (fardo).</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Preço (R$)</label>
                                    <input type="text" name="preco" class="form-control" value="<?= $fmt2($item['preco'] ?? '') ?>" placeholder="0,00" inputmode="decimal">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Unidade</label>
                                    <select name="unidade_medida" class="form-select">
                                        <?php foreach (estoque_unidades_medida() as $sig => $rot): ?>
                                            <option value="<?= $sig ?>" <?= (($item['unidade_medida'] ?? 'UN') === $sig ? 'selected' : '') ?>><?= $sig ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Conteúdo</label>
                                    <input type="text" name="conteudo" class="form-control" value="<?= $fmt($item['conteudo'] ?? '') ?>" inputmode="decimal" placeholder="1">
                                    <?php if ($item && ($pb = estoque_preco_por_base($item))): ?>
                                        <div class="form-text">R$ <?= number_format($pb['valor'], 4, ',', '.') ?>/<?= $pb['rotulo'] ?></div>
                                    <?php else: ?>
                                        <div class="form-text">Ex.: 5 (para 5 KG).</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Imagem (arquivo)</label>
                                    <input type="text" name="imagem" class="form-control" value="<?= htmlspecialchars($item['imagem'] ?? '') ?>" placeholder="nome do arquivo">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Estoque atual</label>
                                    <input type="text" name="estoque_atual" class="form-control" value="<?= $fmt($item['estoque_atual'] ?? '0') ?>" inputmode="decimal">
                                    <div class="form-text">Prefira ajustar pela movimentação (mantém histórico).</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Estoque mínimo</label>
                                    <input type="text" name="estoque_minimo" class="form-control" value="<?= $fmt($item['estoque_minimo'] ?? '') ?>" inputmode="decimal">
                                    <div class="form-text">Abaixo disso entra na lista de compra.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Estoque ideal</label>
                                    <input type="text" name="estoque_ideal" class="form-control" value="<?= $fmt($item['estoque_ideal'] ?? '') ?>" inputmode="decimal">
                                    <div class="form-text">Compra até esse nível.</div>
                                </div>
                            </div>
                            <div class="mt-4 d-flex gap-2">
                                <button class="btn btn-primary">Salvar</button>
                                <?php if ($item): ?>
                                    <a href="controller_estoque.php?acao=excluir&id=<?= (int) $id ?>" class="btn btn-outline-danger ms-auto"
                                       onclick="return confirm('Remover este item do estoque? O histórico é mantido.')">Excluir</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($item): ?>
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header fw-semibold">Movimentar saldo</div>
                    <div class="card-body">
                        <div class="mb-3">Saldo atual: <span class="fs-4 fw-bold"><?= $fmt($item['estoque_atual']) ?></span></div>
                        <form method="post" action="controller_estoque.php?acao=movimentar" class="row g-2">
                            <input type="hidden" name="item_id" value="<?= (int) $id ?>">
                            <div class="col-5">
                                <select name="tipo" class="form-select">
                                    <option value="entrada">Entrada (+)</option>
                                    <option value="saida">Saída (−)</option>
                                    <option value="ajuste">Ajuste (=)</option>
                                </select>
                            </div>
                            <div class="col-4">
                                <input type="text" name="quantidade" class="form-control" placeholder="qtde" inputmode="decimal" required>
                            </div>
                            <div class="col-3">
                                <button class="btn btn-primary w-100">OK</button>
                            </div>
                            <div class="col-12">
                                <input type="text" name="observacao" class="form-control form-control-sm" placeholder="observação (opcional)">
                            </div>
                        </form>
                        <div class="form-text mt-1">Ajuste define o saldo absoluto (ex.: contagem física).</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">Últimas movimentações</div>
                    <div class="card-body p-0">
                        <?php if (!$movs): ?>
                            <p class="text-muted p-3 mb-0">Sem movimentações ainda.</p>
                        <?php else: ?>
                            <table class="table table-sm mb-0">
                                <tbody>
                                <?php foreach ($movs as $m):
                                    $sinal = $m['tipo'] === 'saida' ? '−' : ($m['tipo'] === 'entrada' ? '+' : '=');
                                    $cor = $m['tipo'] === 'saida' ? 'text-danger' : ($m['tipo'] === 'entrada' ? 'text-success' : 'text-muted');
                                ?>
                                    <tr>
                                        <td class="text-nowrap small text-muted"><?= htmlspecialchars(date('d/m H:i', strtotime($m['criado_em']))) ?></td>
                                        <td class="<?= $cor ?> fw-semibold"><?= $sinal ?> <?= $fmt($m['quantidade']) ?></td>
                                        <td class="small text-muted">
                                            <?= htmlspecialchars($m['origem']) ?><?= $m['observacao'] ? ' · ' . htmlspecialchars($m['observacao']) : '' ?>
                                            <?php if (!empty($m['responsavel'])): ?><div class="text-muted">por <?= htmlspecialchars($m['responsavel']) ?></div><?php endif; ?>
                                        </td>
                                        <td class="text-end small">→ <?= $fmt($m['saldo_apos']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
