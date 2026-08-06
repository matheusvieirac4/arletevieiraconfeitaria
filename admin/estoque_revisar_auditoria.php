<?php
// Manutenção: revisa os ajustes lançados pela auditoria, mostra o saldo ANTES
// (guardado na observação) e o que a contagem gravou, e permite corrigir —
// lançando o valor real (por padrão já vem preenchido com o saldo anterior,
// para simplesmente reverter). Roda no servidor, onde o banco resolve.
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Revisar auditoria'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Revisar auditoria</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

/** Extrai o "sistema X" (saldo antes) da observação da auditoria. Null se não achar. */
function rev_antes_da_obs(?string $obs): ?float
{
    if ($obs && preg_match('/sistema\s+([\d.,]+)/u', $obs, $m)) {
        return estoque_num_manual($m[1]);
    }
    return null;
}

$flash = null;

// ---- Aplicar correções (lança um ajuste para o valor real informado) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'corrigir') {
    $ids     = array_map('intval', (array) ($_POST['mov'] ?? []));   // ids das movimentações de auditoria
    $valores = (array) ($_POST['valor'] ?? []);
    $temEst  = estoque_mov_tem_estornado($pdo);
    $ok = 0; $erros = [];
    foreach ($ids as $movId) {
        if ($movId <= 0) { continue; }
        $bruto = trim((string) ($valores[$movId] ?? ''));
        if ($bruto === '') { continue; }
        $real = estoque_num_manual($bruto);
        if ($real === null) { $erros[] = "mov #$movId: valor inválido"; continue; }

        // Confirma que é mesmo uma movimentação de auditoria e pega o item.
        $st = $pdo->prepare("SELECT item_id, observacao FROM estoque_movimentacoes WHERE id = :id AND origem = 'auditoria'");
        $st->execute([':id' => $movId]);
        $mov = $st->fetch(PDO::FETCH_ASSOC);
        if (!$mov) { continue; }
        $item = estoque_buscar($pdo, (int) $mov['item_id']);
        if (!$item) { continue; }

        $obs = 'Correção de auditoria (mov #' . $movId . '): saldo real = '
             . rtrim(rtrim(number_format($real, 3, ',', '.'), '0'), ',');
        try {
            estoque_movimentar($pdo, (int) $mov['item_id'], 'ajuste', $real, 'correcao', $obs, estoque_responsavel_atual());
            // Marca a auditoria original como estornada (trilha limpa no histórico).
            if ($temEst) {
                $pdo->prepare("UPDATE estoque_movimentacoes SET estornado = 1 WHERE id = :id")->execute([':id' => $movId]);
            }
            $ok++;
        } catch (\Throwable $e) {
            $erros[] = $item['nome'] . ': ' . $e->getMessage();
        }
    }
    $msg = "$ok correção(ões) aplicada(s).";
    if ($erros) { $msg .= ' Falhas: ' . implode(' | ', $erros); }
    $_SESSION['estoque_flash'] = ['tipo' => $erros ? 'warning' : 'success', 'texto' => $msg];
    header('Location: estoque_revisar_auditoria.php');
    exit;
}

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

// Movimentações de auditoria ainda não estornadas, mais recentes primeiro.
$rows = $pdo->query("
    SELECT m.id, m.item_id, m.quantidade, m.observacao, m.criado_em, m.responsavel, i.nome AS item_nome, i.estoque_atual
    FROM estoque_movimentacoes m
    LEFT JOIN estoque_itens i ON i.id = m.item_id
    WHERE m.origem = 'auditoria' AND COALESCE(m.estornado, 0) = 0
    ORDER BY m.criado_em DESC, m.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Revisar auditoria';
$active = 'estoque';
$f = fn($n) => estoque_qtd($n);   // quantidades de estoque são inteiras
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="mb-0">Revisar auditoria</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>
        <p class="text-muted">
            Ajustes lançados pela auditoria, com o <strong>saldo anterior</strong> (guardado na observação)
            e o que a <strong>contagem gravou</strong>. O campo <strong>valor real</strong> já vem preenchido
            com o saldo anterior (para reverter) — troque pelo número certo se souber. Aplicar lança um
            ajuste para esse valor e marca a auditoria original como estornada.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <?php if (!$rows): ?>
            <div class="alert alert-success">✓ Nenhum ajuste de auditoria pendente de revisão.</div>
        <?php else: ?>
        <form method="post" action="estoque_revisar_auditoria.php">
            <input type="hidden" name="acao" value="corrigir">
            <div class="d-flex gap-2 mb-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="sel-todos">Marcar todos</button>
                <button type="submit" class="btn btn-success btn-sm ms-auto" id="btn-corrigir">Aplicar correções marcadas</button>
            </div>
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 bg-white">
                        <thead><tr class="small text-muted">
                            <th style="width:36px;"></th>
                            <th>Data</th>
                            <th>Item</th>
                            <th class="text-end">Saldo antes</th>
                            <th class="text-end">Contagem gravou</th>
                            <th class="text-end">Saldo atual</th>
                            <th style="width:130px;">Valor real</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r):
                            $antes = rev_antes_da_obs($r['observacao']);
                            $contado = (float) $r['quantidade'];
                            $suspeito = abs($contado) > 1000;   // realça o que parece absurdo
                        ?>
                            <tr>
                                <td><input class="form-check-input chk" type="checkbox" name="mov[]" value="<?= (int) $r['id'] ?>" <?= $suspeito ? 'checked' : '' ?>></td>
                                <td class="text-nowrap small text-muted"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($r['criado_em']))) ?></td>
                                <td class="small"><?= $r['item_nome'] !== null ? htmlspecialchars($r['item_nome']) : '<span class="text-muted">item removido</span>' ?></td>
                                <td class="text-end small"><?= $f($antes) ?></td>
                                <td class="text-end small <?= $suspeito ? 'text-danger fw-semibold' : '' ?>"><?= $f($contado) ?></td>
                                <td class="text-end small text-muted"><?= $f($r['estoque_atual']) ?></td>
                                <td>
                                    <input type="text" class="form-control form-control-sm text-end" name="valor[<?= (int) $r['id'] ?>]"
                                           value="<?= $antes !== null ? htmlspecialchars($f($antes)) : '' ?>" inputmode="decimal" placeholder="—">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </form>
        <script>
        document.getElementById('sel-todos')?.addEventListener('click', function () {
            document.querySelectorAll('.chk').forEach(c => c.checked = true);
        });
        document.getElementById('btn-corrigir')?.closest('form').addEventListener('submit', function (e) {
            const n = document.querySelectorAll('.chk:checked').length;
            if (!n) { e.preventDefault(); alert('Marque pelo menos uma linha.'); return; }
            if (!confirm('Aplicar a correção em ' + n + ' linha(s)? Cada uma lança um ajuste para o valor real e estorna a auditoria original.')) {
                e.preventDefault();
            }
        });
        </script>
        <?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
