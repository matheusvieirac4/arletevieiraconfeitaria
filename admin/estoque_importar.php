<?php
// Substitui os dados de estoque desta base pelos do snapshot do localhost
// (admin/lib/estoque_snapshot.php). Apaga itens/fornecedores/movimentações e
// reinsere. Uso único, em DESENVOLVIMENTO (saldo/histórico são descartados).
// Rode DEPOIS do estoque_setup.php (para o schema estar completo).
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_estoque.php';

$snapFile = __DIR__ . '/lib/estoque_snapshot.php';
$snap = is_file($snapFile) ? require $snapFile : ['itens' => [], 'fornecedores' => []];

$prodItens = (int) $pdo->query("SELECT COUNT(*) FROM estoque_itens")->fetchColumn();
$prodForn  = 0;
try { $prodForn = (int) $pdo->query("SELECT COUNT(*) FROM estoque_fornecedores")->fetchColumn(); } catch (\Throwable $e) {}

$confirmar = (($_GET['confirmar'] ?? '') === '1');
$log = null;

if ($confirmar) {
    try {
        $pdo->beginTransaction();
        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        foreach (['estoque_movimentacoes', 'estoque_item_aliases', 'estoque_itens', 'estoque_fornecedores'] as $t) {
            try { $pdo->exec("DELETE FROM {$t}"); } catch (\Throwable $e) {}
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

        $fi = $pdo->prepare("INSERT INTO estoque_fornecedores (nome, ativo) VALUES (:n, :a)");
        foreach ($snap['fornecedores'] as $f) {
            $fi->execute([':n' => $f['nome'], ':a' => (int) ($f['ativo'] ?? 1)]);
        }

        $cols = ['nome', 'fornecedor', 'preco', 'estoque_atual', 'estoque_minimo', 'estoque_ideal',
                 'codigo_barras', 'codigo_compra', 'unidade_medida', 'conteudo', 'imagem', 'controlar_estoque', 'ativo'];
        $ph = implode(', ', array_map(fn($c) => ":{$c}", $cols));
        $ii = $pdo->prepare("INSERT INTO estoque_itens (" . implode(', ', $cols) . ") VALUES ({$ph})");
        $n = 0;
        foreach ($snap['itens'] as $it) {
            $p = [];
            foreach ($cols as $c) { $p[":{$c}"] = $it[$c] ?? null; }
            $ii->execute($p);
            $n++;
        }
        $pdo->commit();
        $log = ['ok' => true, 'msg' => "Pronto — {$n} itens e " . count($snap['fornecedores']) . " fornecedores importados. O estoque desta base foi substituído pelo do localhost."];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $log = ['ok' => false, 'msg' => 'ERRO: ' . $e->getMessage()];
    }
}

$page_title = 'Importar estoque';
$active = 'estoque';
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-3">Importar estoque do localhost</h1>

        <?php if ($log): ?>
            <div class="alert alert-<?= $log['ok'] ? 'success' : 'danger' ?> bg-white"><?= htmlspecialchars($log['msg']) ?></div>
            <a href="estoque.php" class="btn btn-primary">Ir para o estoque</a>

        <?php elseif (empty($snap['itens'])): ?>
            <div class="alert alert-warning bg-white">Snapshot vazio ou ausente (<code>admin/lib/estoque_snapshot.php</code>).</div>

        <?php else: ?>
            <div class="alert alert-warning bg-white">
                <p class="mb-2"><strong>Atenção — ação destrutiva.</strong> Isto vai:</p>
                <ul class="mb-2">
                    <li><strong>Apagar</strong> os <strong><?= $prodItens ?></strong> itens e <strong><?= $prodForn ?></strong> fornecedores atuais desta base (com movimentações e saldos).</li>
                    <li><strong>Inserir</strong> <strong><?= count($snap['itens']) ?></strong> itens e <strong><?= count($snap['fornecedores']) ?></strong> fornecedores do snapshot
                        (gerado em <?= htmlspecialchars($snap['gerado_em'] ?? '?') ?>).</li>
                </ul>
                <p class="mb-0 text-muted">Use só em desenvolvimento. Rode antes o <a href="estoque_setup.php" target="_blank">estoque_setup.php</a> (schema).</p>
            </div>
            <a href="estoque_importar.php?confirmar=1" class="btn btn-danger js-confirm"
               data-msg="Substituir TODO o estoque desta base pelo snapshot do localhost? Apaga saldos e histórico. Não dá pra desfazer.">Sim, substituir tudo</a>
            <a href="estoque.php" class="btn btn-outline-secondary">Cancelar</a>
        <?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
