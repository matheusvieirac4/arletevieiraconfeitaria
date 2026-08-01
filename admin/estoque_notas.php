<?php
// Notas da SEFAZ com XML completo (procNFe) guardado — prontas para importar
// os itens no estoque. A guarda acontece no puxador do SEFAZ (model_financeiro).
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';
require_once 'model_financeiro.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Notas do SEFAZ'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Notas do SEFAZ</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

// Índice de XMLs guardados, mais recentes primeiro.
$notas = array_values(financeiro_nfe_index());
usort($notas, fn($a, $b) => strcmp($b['guardada_em'] ?? '', $a['guardada_em'] ?? ''));

$page_title = 'Notas do SEFAZ';
$active = 'estoque';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Notas do SEFAZ</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
        <?php endif; ?>

        <p class="text-muted">
            Estas são as NF-e recebidas do SEFAZ que vieram com o <strong>XML completo</strong> (com itens).
            Clique em <strong>Importar itens</strong> para revisar e dar entrada no estoque.
            Notas que chegam só como <em>resumo</em> não aparecem aqui — elas viram conta a pagar no financeiro.
        </p>

        <?php if (!$notas): ?>
            <div class="alert alert-info bg-white">
                Nenhuma nota com XML completo guardada ainda. Elas são baixadas pelo puxador do SEFAZ no
                <a href="financeiro.php">Financeiro</a> (quando o fornecedor envia a NF-e completa contra o CNPJ).
            </div>
        <?php else: ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 bg-white">
                        <thead><tr>
                            <th>Fornecedor</th>
                            <th class="text-center">Nota</th>
                            <th class="text-center">Emissão</th>
                            <th class="text-end">Valor</th>
                            <th class="text-center">Itens</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($notas as $n): ?>
                            <?php $ja = estoque_nota_processada($pdo, $n['chave']); ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($n['fornecedor'] !== '' ? $n['fornecedor'] : '(sem nome)') ?>
                                    <div class="small text-muted"><?= htmlspecialchars($n['chave']) ?></div>
                                </td>
                                <td class="text-center"><?= htmlspecialchars($n['numero'] !== '' ? $n['numero'] : '—') ?></td>
                                <td class="text-center"><?= $n['emissao'] !== '' ? htmlspecialchars(date('d/m/Y', strtotime($n['emissao']))) : '—' ?></td>
                                <td class="text-end"><?= $n['valor'] !== '' ? 'R$ ' . number_format((float) $n['valor'], 2, ',', '.') : '—' ?></td>
                                <td class="text-center"><?= (int) ($n['itens'] ?? 0) ?></td>
                                <td class="text-end">
                                    <?php if ($ja): ?>
                                        <span class="badge bg-secondary" title="Lançada em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($ja))) ?>">já lançada</span>
                                    <?php endif; ?>
                                    <form method="post" action="controller_estoque.php?acao=entrada_nfe" class="d-inline">
                                        <input type="hidden" name="chave" value="<?= htmlspecialchars($n['chave']) ?>">
                                        <button class="btn btn-success btn-sm">Importar itens</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
