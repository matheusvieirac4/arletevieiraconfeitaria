<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ponto.php';

if (!ponto_pronto($pdo)) { header('Location: ponto.php'); exit; }

$id = (int) ($_GET['id'] ?? 0);
$pessoa = ponto_pessoa($pdo, $id, false);
if (!$pessoa) {
    $page_title = 'Ponto'; $active = 'ponto';
    require __DIR__ . '/_header.php';
    echo '<div class="alert alert-danger">Pessoa não encontrada.</div><a href="ponto.php" class="btn btn-secondary">Voltar</a>';
    require __DIR__ . '/_footer.php';
    exit;
}

$mesRef = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) { $mesRef = date('Y-m'); }
[$ano, $mes] = array_map('intval', explode('-', $mesRef));
$mesAnt = date('Y-m', strtotime("$mesRef-01 -1 month"));
$mesProx = date('Y-m', strtotime("$mesRef-01 +1 month"));
$mesLabel = ['', 'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'][$mes] . ' ' . $ano;

$resumo = ponto_resumo_mes($pdo, $pessoa, $ano, $mes);
$t = $resumo['totais'];
$temMeta = !empty($pessoa['tem_meta']);
$mediaDia = $t['dias_trabalhados'] > 0 ? (int) round($t['trabalhado_min'] / $t['dias_trabalhados']) : 0;

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$page_title = 'Ponto · ' . $pessoa['nome'];
$active = 'ponto';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h1 class="mb-0"><?= htmlspecialchars($pessoa['nome']) ?>
                    <span class="badge bg-<?= $pessoa['tipo'] === 'socio' ? 'info' : 'secondary' ?> align-middle"><?= $pessoa['tipo'] === 'socio' ? 'Sócio' : 'Freelancer' ?></span>
                </h1>
                <div class="text-muted"><?= htmlspecialchars($mesLabel) ?></div>
            </div>
            <a href="ponto.php?mes=<?= htmlspecialchars($mesRef) ?>" class="btn btn-outline-secondary btn-sm">&larr; Todos</a>
        </div>

        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="?id=<?= $id ?>&mes=<?= $mesAnt ?>" class="btn btn-outline-secondary btn-sm">&larr;</a>
            <span class="fw-semibold" style="min-width:190px;text-align:center;"><?= htmlspecialchars($mesLabel) ?></span>
            <a href="?id=<?= $id ?>&mes=<?= $mesProx ?>" class="btn btn-outline-secondary btn-sm">&rarr;</a>
        </div>

        <!-- Insights da pessoa -->
        <div class="row g-3 mb-4">
            <?php
            $cards = [
                ['Trabalhado',   ponto_hm($t['trabalhado_min']),  'primary'],
                ['Esperado',     $temMeta ? ponto_hm($t['esperado_min']) : '—', 'secondary'],
                ['Extras',       $temMeta ? ponto_hm($t['extra_min']) : '—', 'success'],
                ['Faltas',       $temMeta ? (ponto_hm($t['falta_min']) . ' · ' . $t['falta_dias'] . 'd') : '—', 'danger'],
                ['Saldo',        $temMeta ? (($t['saldo_min'] >= 0 ? '+' : '') . ponto_hm($t['saldo_min'])) : '—', $t['saldo_min'] >= 0 ? 'success' : 'danger'],
                ['Dias / média', $t['dias_trabalhados'] . ' · ' . ponto_hm($mediaDia), 'info'],
            ];
            foreach ($cards as $c): ?>
                <div class="col-6 col-md-2">
                    <div class="card h-100"><div class="card-body py-3">
                        <div class="text-muted small mb-1"><?= $c[0] ?></div>
                        <div class="fs-5 fw-bold text-<?= $c[2] ?>"><?= htmlspecialchars($c[1]) ?></div>
                    </div></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-4">
            <!-- Espelho de ponto -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Espelho de ponto</span>
                        <span class="text-muted small">Intervalo descontado no mês: <?= ponto_hm($t['intervalo_min']) ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr>
                                <th>Dia</th><th>Batidas</th>
                                <th class="text-end">Trab.</th><th class="text-end">Esp.</th>
                                <th class="text-end">Saldo</th><th></th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($resumo['dias'] as $dia):
                                if ($dia['futuro']) { continue; }   // dia que ainda não chegou
                                $wl = ponto_dia_semana($dia['w']);
                                $fds = ($dia['w'] === 0 || $dia['w'] === 6);
                                $pend = $dia['aberto'] || !empty($dia['inconsistente']);
                                $rowClass = $dia['falta_dia'] ? 'table-danger' : ($pend ? 'table-warning' : '');
                            ?>
                                <tr class="<?= $rowClass ?>">
                                    <td class="<?= $fds ? 'text-muted' : '' ?>">
                                        <span class="fw-semibold"><?= (int) substr($dia['data'], 8, 2) ?></span>
                                        <span class="small"><?= $wl ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($dia['especial'])):
                                            $esp = $dia['especial'];
                                            $ic = ['feriado' => '🎉', 'folga' => '🌴', 'abono' => '📝'][$esp['tipo']] ?? '•'; ?>
                                            <span class="badge bg-info me-1" title="<?= htmlspecialchars((string) $esp['descricao']) ?>"><?= $ic ?> <?= htmlspecialchars($esp['descricao'] ?: ucfirst($esp['tipo'])) ?></span>
                                        <?php endif; ?>
                                        <?php if (!$dia['batidas']): ?>
                                            <span class="text-muted small"><?= $dia['falta_dia'] ? 'falta' : (($dia['futuro'] || !empty($dia['especial'])) ? '' : '—') ?></span>
                                        <?php else: foreach ($dia['batidas'] as $b): ?>
                                            <span class="badge bg-light text-dark border me-1" title="<?= $b['origem'] ?>">
                                                <?= $b['tipo'] === 'entrada' ? '▶' : '⏸' ?> <?= substr($b['momento'], 11, 5) ?>
                                                <a href="controller_ponto.php?acao=batida_excluir&id=<?= (int) $b['id'] ?>&colaborador_id=<?= $id ?>&mes=<?= htmlspecialchars($mesRef) ?>"
                                                   class="text-danger text-decoration-none ms-1 js-confirm" data-msg="Remover a batida <?= $b['tipo'] ?> <?= substr($b['momento'], 11, 5) ?>?">×</a>
                                            </span>
                                        <?php endforeach; endif; ?>
                                        <?php if ($dia['aberto']): ?><span class="badge bg-warning text-dark">sem saída</span><?php endif; ?>
                                        <?php if (!empty($dia['inconsistente'])): ?><span class="badge bg-warning text-dark">batidas inconsistentes</span><?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= $dia['trabalhado_min'] > 0 ? ponto_hm($dia['trabalhado_min']) : '' ?></td>
                                    <td class="text-end text-muted"><?= $dia['esperado_min'] > 0 ? ponto_hm($dia['esperado_min']) : '' ?></td>
                                    <td class="text-end <?= $dia['fechado'] && $dia['saldo_min'] < 0 && $dia['esperado_min'] > 0 ? 'text-danger' : ($dia['fechado'] && $dia['extra_min'] > 0 ? 'text-success' : '') ?>">
                                        <?php if ($temMeta && $dia['fechado'] && $dia['esperado_min'] > 0 && ($dia['batidas'] || $dia['falta_dia'])): echo ($dia['saldo_min'] >= 0 ? '+' : '') . ponto_hm($dia['saldo_min']); elseif ($dia['ehHoje']): ?><span class="badge bg-primary">hoje</span><?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-link btn-sm p-0 js-add-batida"
                                                data-bs-toggle="modal" data-bs-target="#modal-batida" data-data="<?= $dia['data'] ?>">+ batida</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Configuração da jornada -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header fw-semibold">Jornada de <?= htmlspecialchars($pessoa['nome']) ?></div>
                    <div class="card-body">
                        <form method="post" action="controller_ponto.php?acao=jornada_salvar">
                            <input type="hidden" name="colaborador_id" value="<?= $id ?>">
                            <div class="mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <option value="freelancer" <?= $pessoa['tipo'] !== 'socio' ? 'selected' : '' ?>>Freelancer</option>
                                    <option value="socio" <?= $pessoa['tipo'] === 'socio' ? 'selected' : '' ?>>Sócio</option>
                                </select>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="tem_meta" value="1" id="tem_meta" <?= $temMeta ? 'checked' : '' ?>>
                                <label class="form-check-label" for="tem_meta">Calcular extras/faltas (tem meta)</label>
                            </div>
                            <label class="form-label">Horas esperadas por dia</label>
                            <div class="row g-2 mb-3">
                                <?php
                                $dd = [['h_seg','Seg'],['h_ter','Ter'],['h_qua','Qua'],['h_qui','Qui'],['h_sex','Sex'],['h_sab','Sáb'],['h_dom','Dom']];
                                foreach ($dd as $c): ?>
                                    <div class="col-4">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text" style="width:46px"><?= $c[1] ?></span>
                                            <input type="text" name="<?= $c[0] ?>" class="form-control text-end" inputmode="decimal"
                                                   value="<?= rtrim(rtrim(number_format((float) $pessoa[$c[0]], 2, ',', ''), '0'), ',') ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label small">Desconto almoço (min)</label>
                                    <input type="number" name="intervalo_desconto_min" class="form-control form-control-sm" value="<?= (int) $pessoa['intervalo_desconto_min'] ?>" min="0">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Só descontar se passar de (h)</label>
                                    <input type="text" name="intervalo_limite_h" class="form-control form-control-sm" inputmode="decimal" value="<?= rtrim(rtrim(number_format((float) $pessoa['intervalo_limite_h'], 2, ',', ''), '0'), ',') ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Tolerância (min/dia)</label>
                                    <input type="number" name="tolerancia_min" class="form-control form-control-sm" value="<?= (int) $pessoa['tolerancia_min'] ?>" min="0">
                                </div>
                            </div>
                            <button class="btn btn-primary w-100">Salvar jornada</button>
                        </form>
                        <p class="text-muted small mt-2 mb-0">O desconto do almoço só é aplicado quando a pessoa bate <strong>só entrada e saída</strong> no dia. Se bater para o almoço (2 pares), o intervalo já sai do somatório.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: adicionar/editar batida -->
        <div class="modal fade" id="modal-batida" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="post" action="controller_ponto.php?acao=batida_salvar">
                    <input type="hidden" name="colaborador_id" value="<?= $id ?>">
                    <input type="hidden" name="mes" value="<?= htmlspecialchars($mesRef) ?>">
                    <div class="modal-header"><h5 class="modal-title">Registrar batida</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label">Data</label>
                                <input type="date" name="data" id="mb-data" class="form-control" required>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Hora</label>
                                <input type="time" name="hora" id="mb-hora" class="form-control" required>
                            </div>
                            <div class="col-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <option value="entrada">Entrada</option>
                                    <option value="saida">Saída</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Observação (opcional)</label>
                                <input type="text" name="observacao" class="form-control" placeholder="ex.: correção manual">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-primary">Salvar batida</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        // O AdminKit (Bootstrap) abre o modal pelo data-bs-toggle. Aqui só
        // preenchemos a data do dia clicado antes de ele aparecer.
        document.addEventListener('click', function (e) {
            const b = e.target.closest('.js-add-batida');
            if (!b) { return; }
            document.getElementById('mb-data').value = b.dataset.data || '';
            document.getElementById('mb-hora').value = '';
            const sel = document.querySelector('#modal-batida select[name="tipo"]');
            if (sel) { sel.value = 'entrada'; }
        });
        </script>
<?php require __DIR__ . '/_footer.php'; ?>
