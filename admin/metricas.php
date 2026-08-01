<?php
// As métricas foram unificadas na tela "Links da bio" (aba Métricas).
// Mantido apenas como redirecionamento para links antigos/favoritos.
require_once __DIR__ . '/_auth.php';

$dias = isset($_GET['dias']) ? (int) $_GET['dias'] : 0;
$destino = 'links_bio.php?aba=metricas';
if (in_array($dias, [7, 30, 90, 365], true)) {
    $destino .= '&dias=' . $dias;
}
header('Location: ' . $destino, true, 301);
exit;
