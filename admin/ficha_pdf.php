<?php
// Página de impressão da ficha técnica (receita ou produto), uma por folha.
// Uso: ficha_pdf.php?tipo=receita&id=5  |  ...&ids=5,7,9  (uma ficha por página)
// Layout standalone (sem sidebar); abre o diálogo de impressão → Salvar como PDF.
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$tipo = ($_GET['tipo'] ?? 'receita') === 'produto' ? 'produto' : 'receita';
// Modo: 'producao' (cozinha — sem valores em R$) ou 'custo' (gestão). Padrão: produção.
$modo = ($_GET['modo'] ?? 'producao') === 'custo' ? 'custo' : 'producao';
$mostrarCusto = $modo === 'custo';

// Coleta os ids pedidos (id único ou lista ids=1,2,3).
$ids = [];
if (isset($_GET['ids'])) {
    foreach (explode(',', (string) $_GET['ids']) as $x) { $x = (int) trim($x); if ($x > 0) { $ids[] = $x; } }
} elseif (isset($_GET['id'])) {
    $x = (int) $_GET['id']; if ($x > 0) { $ids[] = $x; }
}
$ids = array_values(array_unique($ids));

$fmtNum = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
$reais  = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 2, ',', '.');
$reais4 = fn($n) => $n === null ? '—' : 'R$ ' . number_format((float) $n, 4, ',', '.');
$esc = fn($s) => htmlspecialchars((string) $s);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Ficha técnica</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; color: #222; margin: 0; padding: 24px; font-size: 13px; }
    .ficha { max-width: 720px; margin: 0 auto 24px; }
    .ficha + .ficha { page-break-before: always; }
    .cab { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #c0392b; padding-bottom: 8px; margin-bottom: 14px; }
    .cab h1 { font-size: 20px; margin: 0 0 2px; }
    .cab .marca { text-align: right; color: #c0392b; font-weight: bold; font-size: 12px; line-height: 1.3; }
    .cab .cat { color: #666; font-size: 12px; }
    .resumo { display: flex; flex-wrap: wrap; gap: 10px 24px; margin-bottom: 14px; }
    .resumo .box { min-width: 120px; }
    .resumo .rot { color: #888; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
    .resumo .val { font-size: 16px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; text-align: left; }
    th { background: #c0392b; color: #fff; font-size: 12px; }
    td.num, th.num { text-align: right; }
    tfoot td { font-weight: bold; border-top: 2px solid #999; border-bottom: none; }
    .sec { background: #f3f3f3; font-weight: bold; padding: 5px 8px; border-left: 4px solid #c0392b; margin: 10px 0 0; }
    .cmv { display: inline-block; padding: 2px 10px; border-radius: 10px; color: #fff; font-weight: bold; }
    .cmv.ok { background: #2e7d5b; } .cmv.med { background: #b8860b; } .cmv.alto { background: #c0392b; }
    .vazio { text-align: center; color: #999; padding: 40px; }
    .rodape { color: #999; font-size: 10px; text-align: right; margin-top: 6px; }
    @media print {
        body { padding: 0; }
        .no-print { display: none !important; }
        .ficha { margin: 0; padding: 12mm; }
    }
    .barra { max-width: 720px; margin: 0 auto 16px; display: flex; gap: 8px; }
    .barra button, .barra a { padding: 8px 16px; border: 1px solid #c0392b; background: #c0392b; color: #fff; border-radius: 6px; cursor: pointer; text-decoration: none; font-size: 13px; }
    .barra a.sec { background: #fff; color: #c0392b; }
</style>
</head>
<body>
<?php
// Mantém tipo + ids ao alternar o modo.
$qsIds = isset($_GET['ids']) ? '&ids=' . urlencode((string) $_GET['ids']) : (isset($_GET['id']) ? '&id=' . (int) $_GET['id'] : '');
$linkOutroModo = 'ficha_pdf.php?tipo=' . $tipo . $qsIds . '&modo=' . ($mostrarCusto ? 'producao' : 'custo');
?>
<div class="barra no-print">
    <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    <a class="sec" href="<?= $esc($linkOutroModo) ?>"><?= $mostrarCusto ? '👩‍🍳 Ver versão de produção (sem valores)' : '💰 Ver versão com custos' ?></a>
    <a class="sec" href="<?= $tipo === 'produto' ? 'ficha_produtos.php' : 'ficha_receitas.php' ?>">Voltar</a>
</div>
<div class="no-print" style="max-width:720px;margin:0 auto 12px;color:#666;font-size:12px;">
    Modo: <strong><?= $mostrarCusto ? 'Gestão (com custos)' : 'Produção (cozinha)' ?></strong>
</div>

<?php if (!$ids): ?>
    <div class="vazio">Nenhuma ficha selecionada.</div>
<?php elseif ($tipo === 'receita'): ?>
    <?php foreach ($ids as $rid):
        $rec = ficha_receita_buscar($pdo, $rid);
        if (!$rec) { continue; }
        $c = ficha_receita_custo($pdo, $rid);
        $un = strtolower($rec['unidade_rendimento'] ?? 'g');
    ?>
    <div class="ficha">
        <div class="cab">
            <div>
                <h1><?= $esc($rec['nome']) ?></h1>
                <div class="cat">Receita<?= $rec['categoria'] ? ' · ' . $esc($rec['categoria']) : '' ?></div>
            </div>
            <div class="marca">ARLETE VIEIRA<br>CONFEITARIA</div>
        </div>
        <div class="resumo">
            <div class="box"><div class="rot">% Evaporação</div><div class="val"><?= rtrim(rtrim(number_format((float) $rec['percentual_evaporacao'], 2, ',', '.'), '0'), ',') ?>%</div></div>
            <div class="box"><div class="rot">Peso total</div><div class="val"><?= $fmtNum($c['peso_total']) ?> <?= $esc($un) ?></div></div>
            <div class="box"><div class="rot">Peso final</div><div class="val"><?= $fmtNum($c['peso_final']) ?> <?= $esc($un) ?></div></div>
            <?php if ($mostrarCusto): ?>
            <div class="box"><div class="rot">Custo total</div><div class="val"><?= $reais($c['custo_total']) ?></div></div>
            <div class="box"><div class="rot">Custo / <?= $esc($un) ?></div><div class="val"><?= $reais4($c['custo_por_g']) ?></div></div>
            <?php endif; ?>
        </div>
        <table>
            <thead><tr><th>Ingrediente</th><th class="num">Quantidade</th><?php if ($mostrarCusto): ?><th class="num">Custo</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($c['linhas'] as $l): ?>
                <tr>
                    <td><?= $esc($l['item_nome']) ?></td>
                    <td class="num"><?= $fmtNum($l['quantidade']) ?> <?= $esc($l['rotulo_base'] ?? '') ?></td>
                    <?php if ($mostrarCusto): ?><td class="num"><?= $l['sem_preco'] ? '(sem preço)' : $reais($l['custo']) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($mostrarCusto): ?><tfoot><tr><td>Total</td><td></td><td class="num"><?= $reais($c['custo_total']) ?></td></tr></tfoot><?php endif; ?>
        </table>
        <?php if (!empty($rec['preparo'])): ?>
            <div class="sec">Modo de preparo</div>
            <p style="white-space:pre-wrap;margin:6px 2px;"><?= $esc($rec['preparo']) ?></p>
        <?php endif; ?>
        <div class="rodape">Gerado em <?= date('d/m/Y H:i') ?><?= $mostrarCusto ? ' · custo com preços atuais do estoque' : '' ?></div>
    </div>
    <?php endforeach; ?>
<?php else: ?>
    <?php foreach ($ids as $pid):
        $prod = ficha_produto_buscar($pdo, $pid);
        if (!$prod) { continue; }
        $c = ficha_precificar($pdo, $pid);
        $cmv = $c['cmv_pct'];
        $cmvCls = $cmv === null ? '' : ($cmv <= 35 ? 'ok' : ($cmv <= 45 ? 'med' : 'alto'));
    ?>
    <div class="ficha">
        <div class="cab">
            <div>
                <h1><?= $esc($prod['nome']) ?></h1>
                <div class="cat">Produto<?= $prod['categoria'] ? ' · ' . $esc($prod['categoria']) : '' ?></div>
            </div>
            <div class="marca">ARLETE VIEIRA<br>CONFEITARIA</div>
        </div>
        <?php if ($mostrarCusto): ?>
        <div class="resumo">
            <div class="box"><div class="rot">Custo do prato</div><div class="val"><?= $reais($c['custo_total']) ?></div></div>
            <div class="box"><div class="rot">Custo + fixo/var</div><div class="val"><?= $reais($c['custo_com_overhead']) ?></div></div>
            <div class="box"><div class="rot">Preço Direta</div><div class="val"><?= $reais($c['preco_direta']) ?></div></div>
            <div class="box"><div class="rot">Margem contrib. Direta</div><div class="val"><?= $c['margem_direta_pct'] !== null ? number_format($c['margem_direta_pct'], 1, ',', '.') . '%' : '—' ?></div></div>
            <?php if ($c['vende_ifood']): ?>
            <div class="box"><div class="rot">Preço iFood</div><div class="val"><?= $reais($c['preco_ifood']) ?></div></div>
            <div class="box"><div class="rot">Margem contrib. iFood</div><div class="val"><?= $c['margem_ifood_pct'] !== null ? number_format($c['margem_ifood_pct'], 1, ',', '.') . '%' : '—' ?></div></div>
            <?php endif; ?>
            <div class="box"><div class="rot">CMV</div><div class="val"><?php if ($cmv === null): ?>—<?php else: ?><span class="cmv <?= $cmvCls ?>"><?= number_format($cmv, 1, ',', '.') ?>%</span><?php endif; ?></div></div>
        </div>
        <?php endif; ?>
        <?php
        $bloco = function (string $titulo, array $linhas) use ($fmtNum, $reais, $esc, $mostrarCusto) {
            echo '<div class="sec">' . $esc($titulo) . '</div>';
            echo '<table><thead><tr><th>Item</th><th class="num">Quantidade</th>' . ($mostrarCusto ? '<th class="num">Custo</th>' : '') . '</tr></thead><tbody>';
            $sub = 0;
            if (!$linhas) { echo '<tr><td colspan="' . ($mostrarCusto ? 3 : 2) . '" style="color:#999">—</td></tr>'; }
            foreach ($linhas as $l) {
                if (!$l['sem_preco']) { $sub += (float) $l['custo']; }
                echo '<tr><td>' . $esc($l['nome']) . '</td><td class="num">' . $fmtNum($l['quantidade']) . ' ' . $esc($l['rotulo'] ?? '') . '</td>'
                   . ($mostrarCusto ? '<td class="num">' . ($l['sem_preco'] ? '(sem preço)' : $reais($l['custo'])) . '</td>' : '') . '</tr>';
            }
            echo '</tbody>' . ($mostrarCusto ? '<tfoot><tr><td>Subtotal</td><td></td><td class="num">' . $reais($sub) . '</td></tr></tfoot>' : '') . '</table>';
        };
        $bloco('Ingredientes (embalagens e itens diretos)', $c['linhas']['ingrediente']);
        $bloco('Recheios (receitas)', $c['linhas']['recheio']);
        ?>
        <div class="rodape">Gerado em <?= date('d/m/Y H:i') ?><?= $mostrarCusto ? ' · custo com preços atuais do estoque' : '' ?></div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
// Abre o diálogo de impressão automaticamente (o usuário escolhe "Salvar como PDF").
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
</script>
</body>
</html>
