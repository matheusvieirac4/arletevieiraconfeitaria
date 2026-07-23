<?php
// Cabeçalho compartilhado do painel admin.
// Antes de incluir, a página deve definir:
//   $page_title  (string) - título da aba do navegador
//   $active      (string) - chave do item de menu ativo (ver $navItems)
//   $extra_css   (string) - opcional, CSS específico da página (sem tags <style>)
$page_title = isset($page_title) ? $page_title : 'Painel';
$active     = isset($active) ? $active : '';
$extra_css  = isset($extra_css) ? $extra_css : '';

// Menu único do painel. Para adicionar uma nova seção, basta uma linha aqui.
$navItems = [
    'blog'      => ['index.php',     'Blog'],
    'usuarios'  => ['usuarios.php',  'Usuários'],
    'cardapios' => ['cardapios.php', 'Cardápios'],
    'links_bio' => ['links_bio.php', 'Links da bio'],
    'metricas'  => ['metricas.php',  'Métricas'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin - <?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
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
        .admin-sidebar .logo { width: 120px; margin-bottom: 32px; }
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
        .admin-main { margin-left: 220px; padding: 32px 24px 24px 24px; }
        @media (max-width: 767px) {
            .admin-sidebar { position: static; width: 100vw; min-height: unset; flex-direction: row; justify-content: flex-start; padding: 12px 0; }
            .admin-sidebar .logo { width: 80px; margin-bottom: 0; margin-right: 16px; }
            .admin-sidebar .nav { flex-direction: row; width: auto; }
            .admin-sidebar .nav-link { padding: 8px 12px; margin-bottom: 0; margin-right: 8px; }
            .admin-main { margin-left: 0; padding: 16px 8px; }
        }
<?= $extra_css ?>
    </style>
</head>
<body>
<div class="admin-sidebar">
    <img src="../img/logo.png" alt="Logo Arlete Vieira Confeitaria" class="logo">
    <nav class="nav flex-column mt-2">
<?php foreach ($navItems as $key => $item): ?>
        <a href="<?= $item[0] ?>" class="nav-link<?= $active === $key ? ' active' : '' ?>"><?= $item[1] ?></a>
<?php endforeach; ?>
        <a href="logout.php" class="nav-link text-danger">Sair</a>
    </nav>
</div>
<div class="admin-main">
    <div class="container-fluid px-0">
