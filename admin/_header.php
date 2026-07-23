<?php
// Cabeçalho compartilhado do painel admin (tema AdminKit / Bootstrap 5).
// A página deve definir antes de incluir:
//   $page_title (string), $active (string), $extra_css (string, opcional).
$page_title = isset($page_title) ? $page_title : 'Painel';
$active     = isset($active) ? $active : '';
$extra_css  = isset($extra_css) ? $extra_css : '';
$adminNome  = isset($_SESSION['admin_nome']) ? $_SESSION['admin_nome'] : 'Admin';

// Menu do painel: chave => [arquivo, rótulo, ícone Feather].
$navItems = [
    'blog'      => ['index.php',     'Blog',         'file-text'],
    'usuarios'  => ['usuarios.php',  'Usuários',     'users'],
    'cardapios' => ['cardapios.php', 'Cardápios',    'book-open'],
    'links_bio' => ['links_bio.php', 'Links da bio', 'link'],
    'metricas'  => ['metricas.php',  'Métricas',     'bar-chart-2'],
    'financeiro'=> ['financeiro.php','Financeiro',   'dollar-sign'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Admin · <?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@adminkit/core@3/dist/css/app.css">
    <?php require __DIR__ . '/_brand.php'; ?>
    <style>
<?= $extra_css ?>
    </style>
</head>
<body>
<div class="wrapper">
    <nav id="sidebar" class="sidebar js-sidebar">
        <div class="sidebar-content js-simplebar">
            <a class="sidebar-brand text-center" href="index.php">
                <img src="../img/logo.png" alt="Arlete Vieira Confeitaria">
            </a>
            <ul class="sidebar-nav">
                <li class="sidebar-header">Painel</li>
<?php foreach ($navItems as $key => $item): ?>
                <li class="sidebar-item<?= $active === $key ? ' active' : '' ?>">
                    <a class="sidebar-link" href="<?= $item[0] ?>">
                        <i class="align-middle" data-feather="<?= $item[2] ?>"></i>
                        <span class="align-middle"><?= $item[1] ?></span>
                    </a>
                </li>
<?php endforeach; ?>
                <li class="sidebar-item">
                    <a class="sidebar-link" href="logout.php">
                        <i class="align-middle" data-feather="log-out"></i>
                        <span class="align-middle">Sair</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <div class="main">
        <nav class="navbar navbar-expand navbar-light navbar-bg">
            <a class="sidebar-toggle js-sidebar-toggle">
                <i class="hamburger align-self-center"></i>
            </a>
            <div class="navbar-collapse collapse">
                <ul class="navbar-nav navbar-align">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-none d-sm-inline-block" href="#" data-bs-toggle="dropdown">
                            <i class="align-middle" data-feather="user"></i>
                            <span class="text-dark"><?= htmlspecialchars($adminNome) ?></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item" href="usuarios.php"><i class="align-middle me-1" data-feather="users"></i> Usuários</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="logout.php"><i class="align-middle me-1" data-feather="log-out"></i> Sair</a>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>

        <main class="content">
            <div class="container-fluid p-0">
