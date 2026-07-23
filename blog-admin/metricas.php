<?php
session_start();
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}

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
$limite = $agora->modify('-' . $diasFiltro . ' days');

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
    $link = isset($r['link']) ? $r['link'] : 'desconhecido';
    $porLink[$link] = (isset($porLink[$link]) ? $porLink[$link] : 0) + 1;

    $device = isset($r['device']) ? $r['device'] : 'desktop';
    if (!isset($porDispositivo[$device])) {
        $porDispositivo[$device] = 0;
    }
    $porDispositivo[$device]++;

    $source = trim((string) (isset($r['source']) ? $r['source'] : ''));
    $source = $source !== '' ? $source : 'nao informado';
    $porOrigem[$source] = (isset($porOrigem[$source]) ? $porOrigem[$source] : 0) + 1;

    $dia = substr((string) (isset($r['timestamp']) ? $r['timestamp'] : ''), 0, 10);
    if ($dia !== '') {
        $porDia[$dia] = (isset($porDia[$dia]) ? $porDia[$dia] : 0) + 1;
    }
}

arsort($porLink);
arsort($porOrigem);
ksort($porDia);

$totalCliques = count($filtrados);
$mediaDiaria = $diasFiltro > 0 ? round($totalCliques / $diasFiltro, 2) : 0;
$topLink = array_key_first($porLink);
$topLinkNome = $topLink ? (isset($nomesLinks[$topLink]) ? $nomesLinks[$topLink] : ucfirst($topLink)) : '-';

$ultimos = array_slice(array_reverse($filtrados), 0, 50);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Admin Blog - Metricas de Links</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f8f9fa; }
        .admin-sidebar {
            width: 220px;
            min-height: 100vh;
            background: #23272b;
            color: #fff;
            position: fixed;
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-top: 32px;
            z-index: 100;
        }
        .admin-sidebar .logo {
            width: 120px;
            margin-bottom: 32px;
        }
        .admin-sidebar .nav { width: 100%; }
        .admin-sidebar .nav-link {
            color: #fff;
            font-weight: 500;
            padding: 12px 24px;
            border-radius: 6px;
            margin-bottom: 8px;
            transition: background 0.15s;
        }
        .admin-sidebar .nav-link.active, .admin-sidebar .nav-link:hover {
            background: #a51d32;
            color: #fff;
        }
        .admin-main {
            margin-left: 220px;
            padding: 32px 24px 24px 24px;
        }
        .stat-card {
            background: #fff;
            border: 1px solid #eceff1;
            border-radius: 10px;
            padding: 16px;
            height: 100%;
        }
        .stat-card .label {
            color: #6c757d;
            font-size: 0.86rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .stat-card .value {
            font-size: 1.6rem;
            font-weight: 700;
            color: #212529;
            line-height: 1.2;
            margin-top: 6px;
        }
        @media (max-width: 767px) {
            .admin-sidebar { position: static; width: 100vw; min-height: unset; flex-direction: row; justify-content: flex-start; padding: 12px 0; }
            .admin-sidebar .logo { width: 80px; margin-bottom: 0; margin-right: 16px; }
            .admin-sidebar .nav { flex-direction: row; width: auto; }
            .admin-sidebar .nav-link { padding: 8px 12px; margin-bottom: 0; margin-right: 8px; }
            .admin-main { margin-left: 0; padding: 16px 8px; }
        }
    </style>
</head>
<body>
<div class="admin-sidebar">
    <img src="../img/logo.png" alt="Logo Arlete Vieira Confeitaria" class="logo">
    <nav class="nav flex-column mt-2">
        <a href="index.php" class="nav-link">Blog</a>
        <a href="usuarios.php" class="nav-link">Usuarios</a>
        <a href="cardapios.php" class="nav-link">Cardapios</a>
        <a href="links_bio.php" class="nav-link">Links da bio</a>
        <a href="metricas.php" class="nav-link active">Metricas</a>
        <a href="logout.php" class="nav-link text-danger">Sair</a>
    </nav>
</div>

<div class="admin-main">
    <div class="container-fluid px-0">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
            <h1 class="mb-2 mb-md-0">Metricas de Links da Bio</h1>
            <form method="get" class="d-flex gap-2">
                <select name="dias" class="form-select">
                    <option value="7" <?= $diasFiltro === 7 ? 'selected' : '' ?>>Ultimos 7 dias</option>
                    <option value="30" <?= $diasFiltro === 30 ? 'selected' : '' ?>>Ultimos 30 dias</option>
                    <option value="90" <?= $diasFiltro === 90 ? 'selected' : '' ?>>Ultimos 90 dias</option>
                    <option value="365" <?= $diasFiltro === 365 ? 'selected' : '' ?>>Ultimos 365 dias</option>
                </select>
                <button type="submit" class="btn btn-primary">Filtrar</button>
            </form>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="label">Cliques</div>
                    <div class="value"><?= $totalCliques ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="label">Media diaria</div>
                    <div class="value"><?= number_format($mediaDiaria, 2, ',', '.') ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="label">Link campeao</div>
                    <div class="value"><?= htmlspecialchars($topLinkNome) ?></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="label">Periodo</div>
                    <div class="value"><?= $diasFiltro ?> dias</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header fw-semibold">Cliques por link</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Link</th>
                                    <th class="text-end">Cliques</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($porLink)): ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">Sem dados no periodo.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($porLink as $slug => $qtd): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(isset($nomesLinks[$slug]) ? $nomesLinks[$slug] : ucfirst($slug)) ?></td>
                                            <td class="text-end"><?= (int) $qtd ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Dispositivo</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Tipo</th><th class="text-end">Cliques</th></tr></thead>
                            <tbody>
                                <?php foreach ($porDispositivo as $tipo => $qtd): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(ucfirst($tipo)) ?></td>
                                        <td class="text-end"><?= (int) $qtd ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">Origem</div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped mb-0">
                            <thead><tr><th>Origem</th><th class="text-end">Cliques</th></tr></thead>
                            <tbody>
                                <?php if (empty($porOrigem)): ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">Sem dados no periodo.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($porOrigem as $origem => $qtd): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($origem) ?></td>
                                            <td class="text-end"><?= (int) $qtd ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold">Ultimos cliques (max 50)</div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>Data/hora</th>
                            <th>Link</th>
                            <th>Origem</th>
                            <th>Dispositivo</th>
                            <th>Referer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ultimos)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Ainda nao ha cliques registrados.</td></tr>
                        <?php else: ?>
                            <?php foreach ($ultimos as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime((string) $r['timestamp']))) ?></td>
                                    <td><?= htmlspecialchars(isset($nomesLinks[$r['link']]) ? $nomesLinks[$r['link']] : ucfirst((string) $r['link'])) ?></td>
                                    <td><?= htmlspecialchars((string) (isset($r['source']) ? $r['source'] : 'nao informado')) ?></td>
                                    <td><?= htmlspecialchars(ucfirst((string) (isset($r['device']) ? $r['device'] : 'desktop'))) ?></td>
                                    <td><?= htmlspecialchars((string) (isset($r['referer']) ? $r['referer'] : '-')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
