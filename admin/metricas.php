<?php
require_once __DIR__ . '/_auth.php';

$logFile = __DIR__ . '/../links/logs/clicks.jsonl';
$registros = [];

if (is_file($logFile) && is_readable($logFile)) {
    $linhas = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $item = json_decode($linha, true);
        if (is_array($item) && isset($item['timestamp'], $item['link'])) {
            $registros[] = $item;
        }
    }
}

$diasFiltro = isset($_GET['dias']) ? (int) $_GET['dias'] : 30;
if (!in_array($diasFiltro, [7, 30, 90, 365], true)) {
    $diasFiltro = 30;
}

$agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
$limite = $agora->modify('-' . ($diasFiltro - 1) . ' days')->setTime(0, 0, 0);

$filtrados = [];
foreach ($registros as $r) {
    $ts = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $r['timestamp']);
    if ($ts && $ts >= $limite) {
        $filtrados[] = $r;
    }
}

require_once __DIR__ . '/../includes/links_bio.php';
$nomesLinks = links_bio_mapa_rotulos();

$porLink = [];
$porDispositivo = ['mobile' => 0, 'desktop' => 0];
$porOrigem = [];
$porDia = [];

foreach ($filtrados as $r) {
    $link = $r['link'] ?? 'desconhecido';
    $porLink[$link] = ($porLink[$link] ?? 0) + 1;

    $device = $r['device'] ?? 'desktop';
    $porDispositivo[$device] = ($porDispositivo[$device] ?? 0) + 1;

    $source = trim((string) ($r['source'] ?? ''));
    $source = $source !== '' ? $source : 'não informado';
    $porOrigem[$source] = ($porOrigem[$source] ?? 0) + 1;

    $dia = substr((string) ($r['timestamp'] ?? ''), 0, 10);
    if ($dia !== '') { $porDia[$dia] = ($porDia[$dia] ?? 0) + 1; }
}

arsort($porLink);
arsort($porOrigem);

// Série diária contínua (preenche dias sem clique com 0).
$serieLabels = [];
$serieData = [];
$cursor = $limite;
$fim = $agora->setTime(0, 0, 0);
while ($cursor <= $fim) {
    $k = $cursor->format('Y-m-d');
    $serieLabels[] = $cursor->format('d/m');
    $serieData[] = (int) ($porDia[$k] ?? 0);
    $cursor = $cursor->modify('+1 day');
}

// Top links (até 8) para o gráfico de barras.
$topLinks = array_slice($porLink, 0, 8, true);
$linkLabels = [];
$linkData = [];
foreach ($topLinks as $slug => $qtd) {
    $linkLabels[] = $nomesLinks[$slug] ?? ucfirst($slug);
    $linkData[] = (int) $qtd;
}

$totalCliques = count($filtrados);
$mediaDiaria = $diasFiltro > 0 ? round($totalCliques / $diasFiltro, 1) : 0;
$topLink = array_key_first($porLink);
$topLinkNome = $topLink ? ($nomesLinks[$topLink] ?? ucfirst($topLink)) : '—';
$pctMobile = $totalCliques > 0 ? round(($porDispositivo['mobile'] ?? 0) / $totalCliques * 100) : 0;

$ultimos = array_slice(array_reverse($filtrados), 0, 50);

$page_title = 'Métricas';
$active = 'metricas';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
$extra_css = '
        .mtr { border:0; border-radius:14px; padding:18px 20px; height:100%; color:#fff; box-shadow:0 6px 18px rgba(0,0,0,.06); }
        .mtr .label { font-size:.8rem; text-transform:uppercase; letter-spacing:.04em; opacity:.85; }
        .mtr .value { font-size:1.8rem; font-weight:700; line-height:1.15; margin-top:4px; }
        .mtr .sub { font-size:.8rem; opacity:.85; margin-top:2px; }
        .mtr-1 { background:linear-gradient(135deg,#a51d32,#c94b5f); }
        .mtr-2 { background:linear-gradient(135deg,#2c6fb5,#4f96de); }
        .mtr-3 { background:linear-gradient(135deg,#2e7d5b,#54ab83); }
        .mtr-4 { background:linear-gradient(135deg,#8e6d1f,#c2a24a); }
        .chart-wrap { position:relative; height:300px; }
        .chart-wrap-sm { position:relative; height:240px; }';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
            <h1 class="mb-0">Métricas — Links da bio</h1>
            <form method="get" class="d-flex gap-2">
                <select name="dias" class="form-select" onchange="this.form.submit()">
                    <option value="7"   <?= $diasFiltro === 7 ? 'selected' : '' ?>>Últimos 7 dias</option>
                    <option value="30"  <?= $diasFiltro === 30 ? 'selected' : '' ?>>Últimos 30 dias</option>
                    <option value="90"  <?= $diasFiltro === 90 ? 'selected' : '' ?>>Últimos 90 dias</option>
                    <option value="365" <?= $diasFiltro === 365 ? 'selected' : '' ?>>Últimos 365 dias</option>
                </select>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="mtr mtr-1">
                    <div class="label">Cliques</div>
                    <div class="value"><?= number_format($totalCliques, 0, ',', '.') ?></div>
                    <div class="sub">nos últimos <?= $diasFiltro ?> dias</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="mtr mtr-2">
                    <div class="label">Média diária</div>
                    <div class="value"><?= number_format($mediaDiaria, 1, ',', '.') ?></div>
                    <div class="sub">cliques por dia</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="mtr mtr-3">
                    <div class="label">Link campeão</div>
                    <div class="value" style="font-size:1.2rem;"><?= htmlspecialchars($topLinkNome) ?></div>
                    <div class="sub"><?= $topLink ? (int) ($porLink[$topLink]) . ' cliques' : '—' ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="mtr mtr-4">
                    <div class="label">No celular</div>
                    <div class="value"><?= $pctMobile ?>%</div>
                    <div class="sub">dos acessos</div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header fw-semibold">Cliques ao longo do tempo</div>
            <div class="card-body">
                <?php if ($totalCliques === 0): ?>
                    <p class="text-muted text-center py-4 mb-0">Sem cliques no período.</p>
                <?php else: ?>
                    <div class="chart-wrap"><canvas id="chart-dias"></canvas></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Cliques por link</div>
                    <div class="card-body">
                        <?php if (!$linkData): ?>
                            <p class="text-muted text-center py-4 mb-0">Sem dados no período.</p>
                        <?php else: ?>
                            <div class="chart-wrap"><canvas id="chart-links"></canvas></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="row g-3">
                    <div class="col-12 col-md-6 col-lg-12">
                        <div class="card">
                            <div class="card-header fw-semibold">Dispositivo</div>
                            <div class="card-body">
                                <?php if ($totalCliques === 0): ?>
                                    <p class="text-muted text-center py-3 mb-0">Sem dados.</p>
                                <?php else: ?>
                                    <div class="chart-wrap-sm"><canvas id="chart-device"></canvas></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-lg-12">
                        <div class="card">
                            <div class="card-header fw-semibold">Origem</div>
                            <div class="card-body">
                                <?php if (!$porOrigem): ?>
                                    <p class="text-muted text-center py-3 mb-0">Sem dados.</p>
                                <?php else: ?>
                                    <div class="chart-wrap-sm"><canvas id="chart-origem"></canvas></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Últimos cliques</div>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr><th>Data/hora</th><th>Link</th><th>Origem</th><th>Dispositivo</th></tr></thead>
                    <tbody>
                        <?php if (!$ultimos): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3">Ainda não há cliques registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($ultimos as $r): ?>
                                <tr>
                                    <td class="text-nowrap small"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $r['timestamp']))) ?></td>
                                    <td><?= htmlspecialchars($nomesLinks[$r['link']] ?? ucfirst((string) $r['link'])) ?></td>
                                    <td class="small"><?= htmlspecialchars((string) ($r['source'] ?? 'não informado')) ?></td>
                                    <td class="small"><?= htmlspecialchars(ucfirst((string) ($r['device'] ?? 'desktop'))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    Chart.defaults.font.family = 'system-ui, sans-serif';
    Chart.defaults.color = '#6c757d';
    const paleta = ['#a51d32','#2c6fb5','#f0a500','#2e7d5b','#8e44ad','#e67e22','#16a085','#c94b5f','#7f8c8d','#e07a8b'];

    // Linha — cliques por dia
    const elDias = document.getElementById('chart-dias');
    if (elDias) {
        new Chart(elDias, {
            type: 'line',
            data: {
                labels: <?= json_encode($serieLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: 'Cliques', data: <?= json_encode($serieData) ?>,
                    borderColor: '#a51d32', backgroundColor: 'rgba(165,29,50,.12)',
                    fill: true, tension: .35, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f0f1f3' } },
                          x: { grid: { display: false }, ticks: { maxTicksLimit: 12 } } },
            },
        });
    }

    // Barras horizontais — cliques por link
    const elLinks = document.getElementById('chart-links');
    if (elLinks) {
        new Chart(elLinks, {
            type: 'bar',
            data: {
                labels: <?= json_encode($linkLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{ label: 'Cliques', data: <?= json_encode($linkData) ?>,
                             backgroundColor: '#a51d32', borderRadius: 6, barThickness: 'flex', maxBarThickness: 30 }],
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f0f1f3' } },
                          y: { grid: { display: false } } },
            },
        });
    }

    function rosca(id, labels, data) {
        const el = document.getElementById(id);
        if (!el) { return; }
        new Chart(el, {
            type: 'doughnut',
            data: { labels: labels, datasets: [{ data: data, backgroundColor: paleta, borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
                       plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } } } },
        });
    }
    rosca('chart-device',
        <?= json_encode(array_map('ucfirst', array_keys($porDispositivo)), JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode(array_values($porDispositivo)) ?>);
    rosca('chart-origem',
        <?= json_encode(array_keys($porOrigem), JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode(array_values($porOrigem)) ?>);
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
