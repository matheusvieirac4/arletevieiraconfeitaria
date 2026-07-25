<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

$itens = estoque_lista_compra($pdo);

// Agrupa por fornecedor e soma custo estimado.
$grupos = [];
$total = 0.0;
foreach ($itens as $it) {
    $f = $it['fornecedor'] ?: 'Sem fornecedor';
    $grupos[$f][] = $it;
    if ($it['custo_estimado'] !== null) { $total += (float) $it['custo_estimado']; }
}
ksort($grupos, SORT_NATURAL | SORT_FLAG_CASE);

$fmt  = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
$real = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');

// Monta o texto para WhatsApp.
$linhasZap = ["*Lista de compra — Arlete Vieira*"];
foreach ($grupos as $forn => $lista) {
    $linhasZap[] = "\n*" . $forn . "*";
    foreach ($lista as $it) {
        $linhasZap[] = '- ' . $it['nome'] . ': ' . $fmt($it['qtde_comprar']);
    }
}
$textoZap = implode("\n", $linhasZap);

$page_title = 'Lista de compra';
$active = 'estoque';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <h1 class="mb-0">Lista de compra</h1>
            <div class="d-flex gap-2">
                <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
                <?php if ($itens): ?>
                    <a href="#" id="btn-zap" class="btn btn-success btn-sm"><i data-feather="message-circle" class="align-middle me-1" style="width:15px;height:15px;"></i>Enviar no WhatsApp</a>
                    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">Imprimir</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$itens): ?>
            <div class="card"><div class="card-body text-center text-muted py-5">
                <i data-feather="check-circle" style="width:40px;height:40px;" class="text-success mb-2"></i>
                <p class="mb-0">Tudo em dia — nenhum item abaixo do mínimo.</p>
            </div></div>
        <?php else: ?>
            <p class="text-muted">Itens abaixo do mínimo, com a quantidade para chegar ao ideal. Custo estimado total: <strong><?= $real($total) ?></strong>.</p>
            <?php foreach ($grupos as $forn => $lista): ?>
                <div class="card mb-3">
                    <div class="card-header fw-semibold"><?= htmlspecialchars($forn) ?></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 bg-white">
                            <thead><tr>
                                <th>Item</th>
                                <th class="text-end">Atual</th>
                                <th class="text-end">Mín.</th>
                                <th class="text-end">Ideal</th>
                                <th class="text-end">Comprar</th>
                                <th class="text-end">Custo est.</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($lista as $it): ?>
                                <tr>
                                    <td><?= htmlspecialchars($it['nome']) ?></td>
                                    <td class="text-end text-danger"><?= $fmt($it['estoque_atual']) ?></td>
                                    <td class="text-end text-muted"><?= $fmt($it['estoque_minimo']) ?></td>
                                    <td class="text-end text-muted"><?= $fmt($it['estoque_ideal']) ?></td>
                                    <td class="text-end fw-semibold"><?= $fmt($it['qtde_comprar']) ?></td>
                                    <td class="text-end"><?= $real($it['custo_estimado']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

<script>
(function () {
    const btn = document.getElementById('btn-zap');
    if (!btn) { return; }
    const texto = <?= json_encode($textoZap, JSON_UNESCAPED_UNICODE) ?>;
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        window.open('https://wa.me/?text=' + encodeURIComponent(texto), '_blank');
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
