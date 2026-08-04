<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ponto.php';

if (!ponto_pronto($pdo)) {
    $page_title = 'Ponto'; $active = 'ponto';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Controle de ponto</h1>';
    ponto_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

// Mês selecionado (?mes=AAAA-MM); padrão = mês atual.
$mesRef = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) { $mesRef = date('Y-m'); }
[$ano, $mes] = array_map('intval', explode('-', $mesRef));
$mesAnt = date('Y-m', strtotime("$mesRef-01 -1 month"));
$mesProx = date('Y-m', strtotime("$mesRef-01 +1 month"));
$mesLabel = ['', 'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'][$mes] . ' ' . $ano;

$linhas = ponto_dashboard($pdo, $ano, $mes);

// Totais gerais (só quem tem meta entra em extra/falta; horas somam todos).
$g = ['trab' => 0, 'esp' => 0, 'extra' => 0, 'faltaMin' => 0, 'faltaDias' => 0, 'abertos' => 0, 'pessoas' => 0];
foreach ($linhas as $l) {
    $t = $l['totais'];
    $g['trab']      += $t['trabalhado_min'];
    $g['esp']       += $t['esperado_min'];
    $g['extra']     += $t['extra_min'];
    $g['faltaMin']  += $t['falta_min'];
    $g['faltaDias'] += $t['falta_dias'];
    $g['abertos']   += $t['abertos'];
    $g['pessoas']++;
}

$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$page_title = 'Ponto';
$active = 'ponto';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <h1 class="mb-0">Controle de ponto</h1>
            <div class="d-flex gap-2">
                <a href="ponto_feriados.php" class="btn btn-outline-secondary btn-sm"><i data-feather="calendar" class="align-middle"></i> Feriados e folgas</a>
                <a href="ponto_kiosk.php" target="_blank" class="btn btn-outline-secondary btn-sm"><i data-feather="clock" class="align-middle"></i> Abrir relógio</a>
                <a href="ponto_export.php?mes=<?= htmlspecialchars($mesRef) ?>" class="btn btn-outline-success btn-sm"><i data-feather="download" class="align-middle"></i> Exportar (contabilidade)</a>
            </div>
        </div>

        <!-- Seletor de mês -->
        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="?mes=<?= $mesAnt ?>" class="btn btn-outline-secondary btn-sm">&larr;</a>
            <span class="fw-semibold" style="min-width:190px;text-align:center;"><?= htmlspecialchars($mesLabel) ?></span>
            <a href="?mes=<?= $mesProx ?>" class="btn btn-outline-secondary btn-sm">&rarr;</a>
            <?php if ($mesRef !== date('Y-m')): ?>
                <a href="?mes=<?= date('Y-m') ?>" class="btn btn-link btn-sm">mês atual</a>
            <?php endif; ?>
        </div>

        <!-- Cards de insight geral -->
        <div class="row g-3 mb-4">
            <?php
            $cards = [
                ['Horas trabalhadas', ponto_hm($g['trab']),      'clock',        'primary'],
                ['Horas extras',      ponto_hm($g['extra']),     'trending-up',  'success'],
                ['Faltas (horas)',    ponto_hm($g['faltaMin']),  'trending-down','danger'],
                ['Faltas (dias)',     (string) $g['faltaDias'],  'calendar',     'warning'],
                ['Pendências',        (string) $g['abertos'],    'alert-triangle','secondary'],
            ];
            foreach ($cards as $c): ?>
                <div class="col-6 col-md">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="text-muted small mb-1"><i data-feather="<?= $c[2] ?>" class="align-middle me-1" style="width:15px"></i><?= $c[0] ?></div>
                            <div class="fs-4 fw-bold text-<?= $c[3] ?>"><?= htmlspecialchars($c[1]) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($g['abertos'] > 0): ?>
            <div class="alert alert-warning py-2"><i data-feather="alert-triangle" class="align-middle me-1"></i>
                Há <strong><?= $g['abertos'] ?></strong> dia(s) com entrada sem saída (alguém esqueceu de bater). Corrija no detalhe da pessoa.</div>
        <?php endif; ?>

        <!-- Tabela por funcionário -->
        <div class="card">
            <div class="card-header fw-semibold">Por funcionário — <?= htmlspecialchars($mesLabel) ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Pessoa</th>
                            <th class="text-center">Tipo</th>
                            <th class="text-end">Dias</th>
                            <th class="text-end">Trabalhado</th>
                            <th class="text-end">Esperado</th>
                            <th class="text-end">Extras</th>
                            <th class="text-end">Faltas</th>
                            <th class="text-end">Saldo</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$linhas): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">Nenhuma pessoa cadastrada. Cadastre em <a href="estoque_colaboradores.php">Estoque → Colaboradores</a>.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($linhas as $l):
                            $p = $l['pessoa']; $t = $l['totais'];
                            $temMeta = !empty($p['tem_meta']);
                            $saldo = $t['saldo_min'];
                        ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($p['nome']) ?>
                                    <?php if ($t['abertos'] > 0): ?><span class="badge bg-warning text-dark ms-1" title="dias em aberto"><?= $t['abertos'] ?> aberto(s)</span><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?= $p['tipo'] === 'socio' ? 'info' : 'secondary' ?>"><?= $p['tipo'] === 'socio' ? 'Sócio' : 'Freelancer' ?></span>
                                </td>
                                <td class="text-end"><?= (int) $t['dias_trabalhados'] ?></td>
                                <td class="text-end fw-semibold"><?= ponto_hm($t['trabalhado_min']) ?></td>
                                <td class="text-end"><?= $temMeta ? ponto_hm($t['esperado_min']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end text-success"><?= $temMeta && $t['extra_min'] > 0 ? ponto_hm($t['extra_min']) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end text-danger"><?= $temMeta && ($t['falta_min'] > 0 || $t['falta_dias'] > 0) ? (ponto_hm($t['falta_min']) . ($t['falta_dias'] > 0 ? ' (' . $t['falta_dias'] . 'd)' : '')) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end fw-bold <?= $saldo >= 0 ? 'text-success' : 'text-danger' ?>"><?= $temMeta ? (($saldo >= 0 ? '+' : '') . ponto_hm($saldo)) : '<span class="text-muted">—</span>' ?></td>
                                <td class="text-end"><a href="ponto_funcionario.php?id=<?= (int) $p['id'] ?>&mes=<?= htmlspecialchars($mesRef) ?>" class="btn btn-outline-primary btn-sm">Detalhes</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <p class="text-muted small mt-2">Extras/faltas usam a tolerância diária configurada por pessoa. Sócios sem meta somam horas mas não geram extra/falta. "Saldo" = trabalhado − esperado (banco de horas do mês).</p>
<?php require __DIR__ . '/_footer.php'; ?>
