<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/links_bio.php';

$links = links_bio_listar();
usort($links, function ($a, $b) {
    $oa = isset($a['ordem']) ? (int) $a['ordem'] : 0;
    $ob = isset($b['ordem']) ? (int) $b['ordem'] : 0;
    return $oa < $ob ? -1 : ($oa > $ob ? 1 : 0);
});

$flash = null;
if (isset($_SESSION['links_bio_flash'])) {
    $flash = $_SESSION['links_bio_flash'];
    unset($_SESSION['links_bio_flash']);
}

/* ----------------------------------------------------------------------
 * Métricas de cliques (mesma tela, aba "Métricas").
 * -------------------------------------------------------------------- */
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

// Aba inicial: "metricas" quando o usuário filtrou período ou pediu explicitamente.
$abaAtiva = (isset($_GET['aba']) && $_GET['aba'] === 'metricas') || isset($_GET['dias']) ? 'metricas' : 'links';

$page_title = 'Links da bio';
$active = 'links_bio';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
$extra_css = '
        .url-box { font-size: 0.82rem; word-break: break-all; }
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
        <h1 class="mb-2">Links da bio</h1>
        <p class="text-muted mb-4">
            Gerencie os botoes de
            <a href="https://links.arletevieiraconfeitaria.com.br/" target="_blank" rel="noopener noreferrer">links.arletevieiraconfeitaria.com.br</a>
            e acompanhe os cliques na aba <strong>Métricas</strong>.
        </p>

        <ul class="nav nav-tabs mb-4" id="tabsLinks" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $abaAtiva === 'links' ? ' active' : '' ?>" id="tab-links-btn" data-bs-toggle="tab"
                        data-bs-target="#tab-links" type="button" role="tab">
                    <i class="align-middle me-1" data-feather="link"></i> Links
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link<?= $abaAtiva === 'metricas' ? ' active' : '' ?>" id="tab-metricas-btn" data-bs-toggle="tab"
                        data-bs-target="#tab-metricas" type="button" role="tab">
                    <i class="align-middle me-1" data-feather="bar-chart-2"></i> Métricas
                </button>
            </li>
        </ul>

        <div class="tab-content">
        <!-- ================= ABA LINKS ================= -->
        <div class="tab-pane fade<?= $abaAtiva === 'links' ? ' show active' : '' ?>" id="tab-links" role="tabpanel">
        <p class="text-muted mb-3">
            O identificador (<code>slug</code>) nao muda ao editar — as metricas de cliques continuam funcionando.
        </p>

        <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#modalNovoLink">Novo link</button>

        <table class="table table-bordered table-striped bg-white">
            <thead>
                <tr>
                    <th>Ordem</th>
                    <th>Botao</th>
                    <th>Identificador</th>
                    <th>URL de destino</th>
                    <th>Status</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $item):
                $slug = isset($item['slug']) ? $item['slug'] : '';
                $clickUrl = 'https://links.arletevieiraconfeitaria.com.br/click.php?l=' . rawurlencode($slug) . '&s=bio';
            ?>
                <tr>
                    <td><?= (int) (isset($item['ordem']) ? $item['ordem'] : 0) ?></td>
                    <td><?= htmlspecialchars(isset($item['rotulo']) ? $item['rotulo'] : '') ?></td>
                    <td><code><?= htmlspecialchars($slug) ?></code></td>
                    <td class="url-box"><?= htmlspecialchars(isset($item['url']) ? $item['url'] : '') ?></td>
                    <td>
                        <?php if (!empty($item['ativo'])): ?>
                            <span class="badge bg-success">Ativo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Oculto</span>
                        <?php endif; ?>
                        <div class="small text-muted mt-1"><?= (isset($item['estilo']) && $item['estilo'] === 'primary') ? 'Destaque' : 'Secundario' ?></div>
                    </td>
                    <td class="text-nowrap">
                        <button type="button" class="btn btn-primary btn-sm btn-editar-link"
                            data-slug="<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
                            data-rotulo="<?= htmlspecialchars(isset($item['rotulo']) ? $item['rotulo'] : '', ENT_QUOTES) ?>"
                            data-url="<?= htmlspecialchars(isset($item['url']) ? $item['url'] : '', ENT_QUOTES) ?>"
                            data-estilo="<?= htmlspecialchars(isset($item['estilo']) ? $item['estilo'] : 'secondary', ENT_QUOTES) ?>"
                            data-ordem="<?= (int) (isset($item['ordem']) ? $item['ordem'] : 0) ?>"
                            data-ativo="<?= !empty($item['ativo']) ? '1' : '0' ?>"
                            data-click="<?= htmlspecialchars($clickUrl, ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalEditarLink">
                            Editar
                        </button>
                        <a href="controller_links_bio.php?acao=excluir&slug=<?= urlencode($slug) ?>"
                           class="btn btn-danger btn-sm js-confirm"
                           data-msg="Excluir este link da página da bio?">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div><!-- /tab-links -->

        <!-- ================= ABA MÉTRICAS ================= -->
        <div class="tab-pane fade<?= $abaAtiva === 'metricas' ? ' show active' : '' ?>" id="tab-metricas" role="tabpanel">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
            <h2 class="h4 mb-0">Cliques dos links da bio</h2>
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="aba" value="metricas">
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
        </div><!-- /tab-metricas -->
        </div><!-- /tab-content -->

<div class="modal fade" id="modalNovoLink" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Novo link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="controller_links_bio.php?acao=inserir">
        <div class="modal-body">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Identificador (fixo, para metricas)</label>
              <input type="text" name="slug_novo" class="form-control" placeholder="cardapio_bolos" required pattern="[a-z0-9][a-z0-9_-]*">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Ordem na pagina</label>
              <input type="number" name="ordem" class="form-control" value="99" min="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Texto do botao</label>
            <input type="text" name="rotulo" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">URL de destino</label>
            <input type="url" name="url" class="form-control" placeholder="https://..." required>
          </div>
          <div class="mb-3">
            <label class="form-label">Estilo</label>
            <select name="estilo" class="form-select">
              <option value="secondary">Secundario (branco)</option>
              <option value="primary">Destaque (vermelho)</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Adicionar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalEditarLink" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar link</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <form method="post" action="" id="formEditarLink">
        <input type="hidden" name="slug" id="edit-slug-hidden">
        <div class="modal-body">
          <p class="text-muted small">Identificador: <code id="edit-slug-preview"></code></p>
          <p class="text-muted small">Link rastreado: <span id="edit-click-preview" class="url-box"></span></p>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Texto do botao</label>
              <input type="text" name="rotulo" id="edit-rotulo" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Ordem</label>
              <input type="number" name="ordem" id="edit-ordem" class="form-control" min="1">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">URL de destino</label>
            <input type="url" name="url" id="edit-url" class="form-control" required>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Estilo</label>
              <select name="estilo" id="edit-estilo" class="form-select">
                <option value="secondary">Secundario</option>
                <option value="primary">Destaque</option>
              </select>
            </div>
            <div class="col-md-6 mb-3 d-flex align-items-end">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="ativo" id="edit-ativo" value="1">
                <label class="form-check-label" for="edit-ativo">Exibir na pagina da bio</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Salvar</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.querySelectorAll('.btn-editar-link').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var slug = this.dataset.slug;
    document.getElementById('formEditarLink').action = 'controller_links_bio.php?acao=editar&slug=' + encodeURIComponent(slug);
    document.getElementById('edit-slug-hidden').value = slug;
    document.getElementById('edit-slug-preview').textContent = slug;
    document.getElementById('edit-click-preview').textContent = this.dataset.click;
    document.getElementById('edit-rotulo').value = this.dataset.rotulo;
    document.getElementById('edit-url').value = this.dataset.url;
    document.getElementById('edit-estilo').value = this.dataset.estilo;
    document.getElementById('edit-ordem').value = this.dataset.ordem;
    document.getElementById('edit-ativo').checked = this.dataset.ativo === '1';
  });
});

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
