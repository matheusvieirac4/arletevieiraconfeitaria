<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ponto.php';

if (!ponto_pronto($pdo)) { header('Location: ponto.php'); exit; }
if (!ponto_tem_especiais($pdo)) {
    $page_title = 'Feriados'; $active = 'ponto';
    require __DIR__ . '/_header.php';
    echo '<div class="alert alert-warning">A tabela de feriados ainda não existe. Rode <a href="ponto_setup.php">ponto_setup.php</a> uma vez.</div>';
    require __DIR__ . '/_footer.php';
    exit;
}

$ano = (int) ($_GET['ano'] ?? date('Y'));
if ($ano < 2020 || $ano > 2100) { $ano = (int) date('Y'); }
$lista = ponto_especiais_listar($pdo, $ano);
$pessoas = ponto_pessoas($pdo, true);

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$page_title = 'Feriados e folgas';
$active = 'ponto';
require __DIR__ . '/_header.php';

$rotulo = ['feriado' => ['🎉', 'Feriado', 'info'], 'folga' => ['🌴', 'Folga', 'success'], 'abono' => ['📝', 'Abono', 'secondary']];
?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <h1 class="mb-0">Feriados e folgas</h1>
            <a href="ponto.php" class="btn btn-outline-secondary btn-sm">&larr; Ponto</a>
        </div>

        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="?ano=<?= $ano - 1 ?>" class="btn btn-outline-secondary btn-sm">&larr;</a>
            <span class="fw-semibold fs-5" style="min-width:80px;text-align:center;"><?= $ano ?></span>
            <a href="?ano=<?= $ano + 1 ?>" class="btn btn-outline-secondary btn-sm">&rarr;</a>
            <a href="controller_ponto.php?acao=feriados_importar&ano=<?= $ano ?>" class="btn btn-primary btn-sm ms-3 js-confirm"
               data-msg="Importar os feriados nacionais de <?= $ano ?>? (não duplica os que já existem)">Importar feriados nacionais de <?= $ano ?></a>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header fw-semibold">Adicionar feriado / folga</div>
                    <div class="card-body">
                        <form method="post" action="controller_ponto.php?acao=especial_salvar">
                            <div class="mb-3">
                                <label class="form-label">Data</label>
                                <input type="date" name="data" class="form-control" value="<?= $ano ?>-01-01" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <option value="feriado">🎉 Feriado</option>
                                    <option value="folga">🌴 Folga</option>
                                    <option value="abono">📝 Abono (falta justificada)</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Vale para</label>
                                <select name="colaborador_id" class="form-select">
                                    <option value="">Todos (feriado geral)</option>
                                    <?php foreach ($pessoas as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Descrição (opcional)</label>
                                <input type="text" name="descricao" class="form-control" placeholder="ex.: Aniversário da cidade">
                            </div>
                            <button class="btn btn-primary w-100">Adicionar</button>
                        </form>
                        <p class="text-muted small mt-3 mb-0">Nesses dias a jornada esperada vira <strong>0h</strong> (não conta falta). Se a pessoa <strong>trabalhar</strong> num feriado/folga, as horas entram no <strong>banco de horas</strong> (saldo) para você liberar outra folga depois.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header fw-semibold"><?= count($lista) ?> dia(s) em <?= $ano ?></div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead><tr><th>Data</th><th>Tipo</th><th>Vale para</th><th>Descrição</th><th></th></tr></thead>
                            <tbody>
                                <?php if (!$lista): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum feriado/folga em <?= $ano ?>. Importe os nacionais acima.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($lista as $e):
                                    $r = $rotulo[$e['tipo']] ?? ['•', $e['tipo'], 'secondary'];
                                    $dt = strtotime($e['data']);
                                ?>
                                    <tr>
                                        <td class="fw-semibold"><?= date('d/m', $dt) ?> <span class="text-muted small"><?= ponto_dia_semana((int) date('w', $dt)) ?></span></td>
                                        <td><span class="badge bg-<?= $r[2] ?>"><?= $r[0] ?> <?= $r[1] ?></span></td>
                                        <td><?= $e['colaborador_id'] === null ? '<span class="text-muted">Todos</span>' : htmlspecialchars($e['pessoa'] ?? '?') ?></td>
                                        <td class="text-muted small"><?= htmlspecialchars((string) $e['descricao']) ?></td>
                                        <td class="text-end">
                                            <a href="controller_ponto.php?acao=especial_excluir&id=<?= (int) $e['id'] ?>&ano=<?= $ano ?>"
                                               class="btn btn-outline-danger btn-sm js-confirm" data-msg="Remover <?= htmlspecialchars(($r[1] . ' ' . date('d/m', $dt)), ENT_QUOTES) ?>?">×</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
