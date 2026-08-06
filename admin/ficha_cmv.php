<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$page_title = 'CMV';
$active = 'ficha_cmv';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">CMV</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$reais = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');
$cmvBadge = function (?float $cmv): string {
    if ($cmv === null) { return '<span class="badge bg-light text-muted border">sem preço</span>'; }
    $cls = $cmv <= 35 ? 'bg-success' : ($cmv <= 45 ? 'bg-warning text-dark' : 'bg-danger');
    return '<span class="badge ' . $cls . '">' . number_format($cmv, 1, ',', '.') . '%</span>';
};

$produtos = ficha_produtos_listar($pdo);

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-3">CMV <span class="text-muted fs-5">(controle ao longo do tempo)</span></h1>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="controller_ficha.php?acao=cmv_registrar_todos" class="btn btn-dark btn-sm js-confirm" data-msg="Congelar o CMV atual de todos os produtos no histórico? (faça isso no fechamento semanal)">Registrar CMV de todos agora</a>
            <a href="ficha_produtos.php" class="btn btn-outline-primary btn-sm">Produtos</a>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 bg-white">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th class="text-end">Custo atual</th>
                            <th class="text-end">Preço</th>
                            <th class="text-center">CMV atual</th>
                            <th class="text-center">Último registrado</th>
                            <th class="text-center">Variação</th>
                            <th class="text-muted">Últ. registro</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$produtos): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">Nenhum produto cadastrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($produtos as $p):
                        $c = ficha_precificar($pdo, (int) $p['id']);
                        $hist = ficha_cmv_historico($pdo, (int) $p['id'], 1);
                        $ult = $hist[0] ?? null;
                        $cmvAtual = $c['cmv_pct'];
                        $cmvUlt = $ult && $ult['cmv_pct'] !== null ? (float) $ult['cmv_pct'] : null;
                        $delta = ($cmvAtual !== null && $cmvUlt !== null) ? $cmvAtual - $cmvUlt : null;
                    ?>
                        <tr>
                            <td><a href="ficha_produto.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none fw-semibold"><?= htmlspecialchars($p['nome']) ?></a></td>
                            <td class="text-end"><?= $reais($c['custo_total']) ?></td>
                            <td class="text-end"><?= $reais($c['preco_direta']) ?></td>
                            <td class="text-center"><?= $cmvBadge($cmvAtual) ?></td>
                            <td class="text-center"><?= $cmvUlt !== null ? number_format($cmvUlt, 1, ',', '.') . '%' : '—' ?></td>
                            <td class="text-center">
                                <?php if ($delta === null): ?>—<?php
                                elseif (abs($delta) < 0.05): ?><span class="text-muted">=</span><?php
                                elseif ($delta > 0): ?><span class="text-danger">▲ <?= number_format($delta, 1, ',', '.') ?>pp</span><?php
                                else: ?><span class="text-success">▼ <?= number_format(abs($delta), 1, ',', '.') ?>pp</span><?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= $ult ? date('d/m/Y', strtotime($ult['criado_em'])) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted small mt-2">Variação = CMV atual − último registrado (em pontos percentuais). Abra o produto para ver o histórico completo.</p>
<?php require __DIR__ . '/_footer.php'; ?>
