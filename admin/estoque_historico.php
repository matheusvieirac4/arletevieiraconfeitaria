<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Histórico'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Histórico de estoque</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$busca  = trim((string) ($_GET['busca'] ?? ''));
$tipo   = (string) ($_GET['tipo'] ?? '');
$origem = (string) ($_GET['origem'] ?? '');
$resp   = (string) ($_GET['responsavel'] ?? '');
$de     = trim((string) ($_GET['de'] ?? ''));
$ate    = trim((string) ($_GET['ate'] ?? ''));
$pag    = max(1, (int) ($_GET['pag'] ?? 1));
$porPag = 100;

$filtros = [
    'busca' => $busca, 'tipo' => $tipo, 'origem' => $origem,
    'responsavel' => $resp, 'de' => $de, 'ate' => $ate,
];
$res    = estoque_historico($pdo, $filtros, $porPag, ($pag - 1) * $porPag);
$linhas = $res['linhas'];
$total  = $res['total'];
$totPag = max(1, (int) ceil($total / $porPag));

$origens = estoque_historico_origens($pdo);
$resps   = estoque_historico_responsaveis($pdo);

$page_title = 'Histórico';
$active = 'estoque';
$fmt = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');

// Mantém os filtros ao trocar de página.
$qsPag = fn($p) => 'estoque_historico.php?' . http_build_query(array_filter([
    'busca' => $busca, 'tipo' => $tipo, 'origem' => $origem, 'responsavel' => $resp,
    'de' => $de, 'ate' => $ate, 'pag' => $p,
]));

require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="mb-0">Histórico de estoque</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>
        <p class="text-muted">Todas as movimentações de todos os itens, do mais recente ao mais antigo. Use para conferir se as baixas estão realmente acontecendo.</p>

        <form method="get" class="row g-2 mb-3">
            <div class="col-12 col-md-3">
                <input type="text" name="busca" class="form-control" placeholder="Item, observação ou responsável" value="<?= htmlspecialchars($busca) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="tipo" class="form-select">
                    <option value="">Todos os tipos</option>
                    <option value="entrada" <?= $tipo === 'entrada' ? 'selected' : '' ?>>Entrada (+)</option>
                    <option value="saida" <?= $tipo === 'saida' ? 'selected' : '' ?>>Saída (−)</option>
                    <option value="ajuste" <?= $tipo === 'ajuste' ? 'selected' : '' ?>>Ajuste (=)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="origem" class="form-select">
                    <option value="">Todas as origens</option>
                    <?php foreach ($origens as $o): ?>
                        <option value="<?= htmlspecialchars($o, ENT_QUOTES) ?>" <?= $origem === $o ? 'selected' : '' ?>><?= htmlspecialchars($o) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="responsavel" class="form-select">
                    <option value="">Todos responsáveis</option>
                    <?php foreach ($resps as $r): ?>
                        <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>" <?= $resp === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <input type="date" name="de" class="form-control" value="<?= htmlspecialchars($de) ?>" title="De" aria-label="De">
            </div>
            <div class="col-6 col-md-1">
                <input type="date" name="ate" class="form-control" value="<?= htmlspecialchars($ate) ?>" title="Até" aria-label="Até">
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-outline-secondary">Filtrar</button>
            </div>
        </form>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0 bg-white">
                    <thead>
                        <tr>
                            <th class="text-nowrap">Data</th>
                            <th>Item</th>
                            <th class="text-end">Qtde</th>
                            <th class="text-end">Saldo após</th>
                            <th>Origem</th>
                            <th>Responsável</th>
                            <th>Observação</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$linhas): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">Nenhuma movimentação encontrada.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($linhas as $m):
                        $sinal = $m['tipo'] === 'saida' ? '−' : ($m['tipo'] === 'entrada' ? '+' : '=');
                        $cor   = $m['tipo'] === 'saida' ? 'text-danger' : ($m['tipo'] === 'entrada' ? 'text-success' : 'text-muted');
                        $estornado = !empty($m['estornado']);
                        $risca = $estornado ? 'text-decoration-line-through opacity-50' : '';
                    ?>
                        <tr>
                            <td class="text-nowrap small text-muted <?= $risca ?>"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($m['criado_em']))) ?></td>
                            <td>
                                <?php if (!empty($m['item_id']) && $m['item_nome'] !== null): ?>
                                    <a href="estoque_item.php?id=<?= (int) $m['item_id'] ?>&voltar=<?= urlencode('estoque_historico.php') ?>" class="text-decoration-none fw-semibold <?= $risca ?>"><?= htmlspecialchars($m['item_nome']) ?></a>
                                <?php else: ?>
                                    <span class="text-muted">item removido</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold <?= $estornado ? 'text-muted ' . $risca : $cor ?>"><?= $sinal ?> <?= $fmt($m['quantidade']) ?></td>
                            <td class="text-end small text-muted <?= $risca ?>"><?= $m['saldo_apos'] !== null ? $fmt($m['saldo_apos']) : '—' ?></td>
                            <td class="small text-muted <?= $risca ?>"><?= htmlspecialchars($m['origem']) ?></td>
                            <td class="small text-muted <?= $risca ?>"><?= htmlspecialchars($m['responsavel'] ?? '—') ?></td>
                            <td class="small text-muted">
                                <span class="<?= $risca ?>"><?= $m['observacao'] ? htmlspecialchars($m['observacao']) : '' ?></span>
                                <?php if ($estornado): ?>
                                    <span class="badge bg-secondary">estornado<?= !empty($m['estornado_por']) ? ' por ' . htmlspecialchars($m['estornado_por']) : '' ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2">
            <p class="text-muted small mb-0"><?= number_format($total, 0, ',', '.') ?> movimentação(ões)<?= ($busca || $tipo || $origem || $resp || $de || $ate) ? ' (filtrado)' : '' ?>.</p>
            <?php if ($totPag > 1): ?>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $pag <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($qsPag($pag - 1)) ?>">&larr;</a>
                        </li>
                        <li class="page-item disabled"><span class="page-link"><?= $pag ?> / <?= $totPag ?></span></li>
                        <li class="page-item <?= $pag >= $totPag ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars($qsPag($pag + 1)) ?>">&rarr;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
