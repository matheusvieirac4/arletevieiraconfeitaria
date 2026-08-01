<?php
// Download autenticado do PDF do currículo. Os arquivos ficam em
// admin/data/curriculos/ (protegida por .htaccess) e só saem por aqui, logado.
require_once __DIR__ . '/_auth.php';
require_once 'model_curriculos.php';

$id  = (int) ($_GET['id'] ?? 0);
$reg = $id ? curriculos_buscar($pdo, $id) : null;
if (!$reg || empty($reg['curriculo_pdf'])) {
    http_response_code(404);
    exit('Currículo não encontrado.');
}

$arq = __DIR__ . '/data/curriculos/' . basename($reg['curriculo_pdf']);
if (!is_file($arq)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$nomeBase = $reg['nome'] ? preg_replace('/[^\w\-]+/u', '_', $reg['nome']) : 'curriculo';
$baixarComo = 'curriculo_' . $nomeBase . '_' . $id . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $baixarComo . '"');
header('Content-Length: ' . filesize($arq));
header('X-Content-Type-Options: nosniff');
readfile($arq);
exit;
