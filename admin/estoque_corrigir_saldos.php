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

// Campos numéricos SEM histórico. Um valor absurdo aqui só pode ser o teto
// cravado pelo bug — não há de onde recalcular, então a correção é limpar.
const CORR_CAMPOS = ['preco', 'conteudo', 'estoque_minimo', 'estoque_ideal'];
const CORR_LIMITE = 100000;

/** Itens com algum campo (preço/conteúdo/mínimo/ideal) em valor absurdo. */
function corr_campos_absurdos(PDO $pdo): array
{
    $cols = implode(', ', CORR_CAMPOS);
    $itens = $pdo->query("SELECT id, nome, $cols FROM estoque_itens ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($itens as $it) {
        $ruins = [];
        foreach (CORR_CAMPOS as $c) {
            if ($it[$c] !== null && abs((float) $it[$c]) > CORR_LIMITE) { $ruins[] = $c; }
        }
        if ($ruins) { $out[] = ['id' => (int) $it['id'], 'nome' => $it['nome'], 'ruins' => $ruins, 'valores' => $it]; }
    }
    return $out;
}

$flash = null;

// ---- Limpar campos absurdos (preço/conteúdo/mínimo/ideal) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'limpar_campos') {
    $ids = array_map('intval', (array) ($_POST['itens'] ?? []));
    $ok = 0;
    foreach ($ids as $iid) {
        if ($iid <= 0) { continue; }
        $st = $pdo->prepare("SELECT " . implode(', ', CORR_CAMPOS) . " FROM estoque_itens WHERE id = :id");
        $st->execute([':id' => $iid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { continue; }
        $sets = [];
        foreach (CORR_CAMPOS as $c) {
            if ($row[$c] !== null && abs((float) $row[$c]) > CORR_LIMITE) { $sets[] = "$c = NULL"; }
        }
        if (!$sets) { continue; }
        $pdo->prepare("UPDATE estoque_itens SET " . implode(', ', $sets) . " WHERE id = :id")->execute([':id' => $iid]);
        $ok++;
    }
    $_SESSION['estoque_flash'] = ['tipo' => 'success', 'texto' => "$ok item(ns) com campos limpos. Reabra cada um e preencha preço, conteúdo, mínimo e ideal corretos."];
    header('Location: estoque_corrigir_saldos.php');
    exit;
}

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
$camposRuins = corr_campos_absurdos($pdo);

$page_title = 'Corrigir saldos';
$active = 'estoque';
$rotuloCampo = ['preco' => 'Preço', 'conteudo' => 'Conteúdo', 'estoque_minimo' => 'Mínimo', 'estoque_ideal' => 'Ideal'];
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

        <?php if (!$suspeitos && !$camposRuins): ?>
            <div class="alert alert-success">✓ Nada divergente. Saldos batem com o histórico e não há campos com valor absurdo.</div>
        <?php endif; ?>

        <?php if ($suspeitos): ?>
        <h5 class="mb-2">Saldos divergentes do histórico</h5>
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

        <?php if ($camposRuins): ?>
        <h5 class="mb-2 mt-4">Campos com valor absurdo (sem histórico)</h5>
        <p class="text-muted">
            Preço, conteúdo, mínimo e ideal <strong>não têm histórico</strong> — não dá para recalcular.
            Limpar deixa o campo em branco para você preencher o valor certo depois no item.
        </p>
        <form method="post" action="estoque_corrigir_saldos.php">
            <input type="hidden" name="acao" value="limpar_campos">
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="sel-todos-c">Marcar todos</button>
                <button type="submit" class="btn btn-warning btn-sm ms-auto" id="btn-limpar">Limpar campos marcados</button>
            </div>
            <div class="card">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr class="small text-muted">
                        <th style="width:40px;"></th><th>Item</th><th>Campos a limpar (valor atual)</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($camposRuins as $c): ?>
                        <tr>
                            <td><input class="form-check-input chk-campo" type="checkbox" name="itens[]" value="<?= $c['id'] ?>" checked></td>
                            <td class="fw-semibold"><?= htmlspecialchars($c['nome']) ?></td>
                            <td class="small">
                                <?php foreach ($c['ruins'] as $campo): ?>
                                    <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($rotuloCampo[$campo] ?? $campo) ?>: <?= $f($c['valores'][$campo]) ?></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <script>
        document.getElementById('sel-todos-c')?.addEventListener('click', function () {
            document.querySelectorAll('.chk-campo').forEach(c => c.checked = true);
        });
        document.getElementById('btn-limpar')?.closest('form').addEventListener('submit', function (e) {
            const n = document.querySelectorAll('.chk-campo:checked').length;
            if (!n) { e.preventDefault(); alert('Marque pelo menos um item.'); return; }
            if (!confirm('Limpar os campos absurdos de ' + n + ' item(ns)? Eles ficam em branco para você preencher depois.')) {
                e.preventDefault();
            }
        });
        </script>
        <?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
