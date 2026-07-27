<?php
// Tela unificada agora é catalogo.php (mestre-detalhe). Mantido só como
// redirecionamento para links antigos.
require_once __DIR__ . '/_auth.php';
$id = (int) ($_GET['id'] ?? 0);
header('Location: catalogo.php' . ($id > 0 ? '?cat=' . $id : ''));
exit;
