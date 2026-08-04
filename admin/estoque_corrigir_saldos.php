<?php
// Ferramenta de manutenção: encontra itens cujo saldo (estoque_atual) não bate
// com a reprodução do histórico de movimentações e corrige aplicando um AJUSTE
// (fica registrado no histórico e é reversível). Roda no servidor, onde a
// conexão localhost do banco resolve.
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Corrigir saldos'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Corrigir saldos</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

/**
 * Saldo correto reproduzindo o histórico (ignora estornadas):
 *   entrada += qtd | saida -= qtd | ajuste = qtd (define absoluto).
 * Devolve [saldo_correto, movimentacoes].
 */
function corr_recalcular(PDO $pdo, int $itemId): array
{
    $st = $pdo->prepare("SELECT id, tipo, quantidade, saldo_apos, origem, observacao, estornado, criado_em
                         FROM estoque_movimentacoes WHERE item_id = :id
                         ORDER BY criado_em ASC, id ASC");
    $st->execute([':id' => $itemId]);
    $movs = $st->fetchAll(PDO::FETCH_ASSOC);
    $saldo = 0.0;
    foreach ($movs as $m) {
        if (!empty($m['estornado'])) { continue; }
        $q = (float) $m['quantidade'];
        if ($m['tipo'] === 'entrada')   { $saldo += $q; }
        elseif ($m['tipo'] === 'saida') { $saldo -= $q; }
        else                            { $saldo = $q; }
    }
    return [$saldo, $movs];
}

/** Lista todos os itens cujo saldo diverge do recalculado (ou é absurdo). */
function corr_suspeitos(PDO $pdo): array
{
    $itens = $pdo->query("SELECT id, nome, estoque_atual FROM estoque_itens ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($itens as $it) {
        [$correto, $movs] = corr_recalcular($pdo, (int) $it['id']);
        $atual = (float) $it['estoque_atual'];
        if (abs($atual - $correto) > 0.0005 || abs($atual) > 100000) {
            $out[] = ['id' => (int) $it['id'], 'nome' => $it['nome'], 'atual' => $atual, 'correto' => $correto, 'movs' => $movs];
        }
    }
    return $out;
}

$flash = null;

// ---- Aplicar correções selecionadas ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'aplicar') {
    $ids = array_map('intval', (array) ($_POST['itens'] ?? []));
    $ok = 0; $erros = [];
    foreach ($ids as $iid) {
        if ($iid <= 0) { continue; }
        // Recalcula de novo no servidor (não confia em valor vindo do POST).
        [$correto, ] = corr_recalcular($pdo, $iid);
        $item = estoque_buscar($pdo, $iid);
        if (!$item) { continue; }
        $antes = (float) $item['estoque_atual'];
        if (abs($antes - $correto) < 0.0005) { continue; }   // já bate, ignora
        $obs = 'Correção de saldo: ' . rtrim(rtrim(number_format($antes, 3, ',', '.'), '0'), ',')
             . ' → ' . rtrim(rtrim(number_format($correto, 3, ',', '.'), '0'), ',') . ' (recalculado pelo histórico)';
        try {
            estoque_movimentar($pdo, $iid, 'ajuste', $correto, 'correcao', $obs, estoque_responsavel_atual());
            $ok++;
        } catch (\Throwable $e) {
            $erros[] = $item['nome'] . ': ' . $e->getMessage();
        }
    }
    $msg = "$ok saldo(s) corrigido(s).";
    if ($erros) { $msg .= ' Falhas: ' . implode(' | ', $erros); }
    $_SESSION['estoque_flash'] = ['tipo' => $erros ? 'warning' : 'success', 'texto' => $msg];
    header('Location: estoque_corrigir_saldos.php');
    exit;
}

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$suspeitos = corr_suspeitos($pdo);

$page_title = 'Corrigir saldos';
$active = 'estoque';
$f = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="mb-0">Corrigir saldos</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>
        <p class="text-muted">
            Itens cujo <strong>saldo no banco</strong> não bate com a reprodução do histórico
            (entradas menos saídas, ignorando estornadas). A correção aplica um <strong>ajuste</strong>
            para o saldo recalculado — fica no histórico e pode ser estornada.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <?php if (!$suspeitos): ?>
            <div class="alert alert-success">✓ Nenhum saldo divergente. Está tudo consistente com o histórico.</div>
        <?php else: ?>
        <form method="post" action="estoque_corrigir_saldos.php">
            <input type="hidden" name="acao" value="aplicar">
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="sel-todos">Marcar todos</button>
                <button type="submit" class="btn btn-success btn-sm ms-auto" id="btn-corrigir">Corrigir marcados</button>
            </div>
            <?php foreach ($suspeitos as $s): ?>
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center gap-2 flex-wrap">
                        <div class="form-check mb-0">
                            <input class="form-check-input chk-item" type="checkbox" name="itens[]" value="<?= $s['id'] ?>" id="chk-<?= $s['id'] ?>" checked>
                            <label class="form-check-label fw-semibold" for="chk-<?= $s['id'] ?>"><?= htmlspecialchars($s['nome']) ?></label>
                        </div>
                        <span class="ms-auto small">
                            banco: <span class="badge bg-danger"><?= $f($s['atual']) ?></span>
                            → correto: <span class="badge bg-success"><?= $f($s['correto']) ?></span>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <thead><tr class="small text-muted">
                                <th>Data</th><th>Tipo</th><th class="text-end">Qtde</th><th class="text-end">Saldo após (gravado)</th><th>Origem</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($s['movs'] as $m):
                                $sin = $m['tipo'] === 'saida' ? '−' : ($m['tipo'] === 'entrada' ? '+' : '=');
                                $est = !empty($m['estornado']);
                                $risca = $est ? 'text-decoration-line-through opacity-50' : '';
                            ?>
                                <tr class="small">
                                    <td class="text-nowrap text-muted <?= $risca ?>"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($m['criado_em']))) ?></td>
                                    <td class="<?= $risca ?>"><?= htmlspecialchars($m['tipo']) ?><?= $est ? ' (estornado)' : '' ?></td>
                                    <td class="text-end <?= $risca ?>"><?= $sin ?> <?= $f($m['quantidade']) ?></td>
                                    <td class="text-end text-muted <?= $risca ?>"><?= $m['saldo_apos'] !== null ? $f($m['saldo_apos']) : '—' ?></td>
                                    <td class="text-muted <?= $risca ?>"><?= htmlspecialchars($m['origem']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
        <script>
        document.getElementById('sel-todos')?.addEventListener('click', function () {
            document.querySelectorAll('.chk-item').forEach(c => c.checked = true);
        });
        // Confirmação antes de enviar (o js-confirm do painel é só para links <a>).
        document.getElementById('btn-corrigir')?.closest('form').addEventListener('submit', function (e) {
            const n = document.querySelectorAll('.chk-item:checked').length;
            if (!n) { e.preventDefault(); alert('Marque pelo menos um item.'); return; }
            if (!confirm('Aplicar a correção de saldo em ' + n + ' item(ns)? Cada um recebe um ajuste no histórico (reversível).')) {
                e.preventDefault();
            }
        });
        </script>
        <?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
