<?php
// Upload de imagem inserida no CORPO do post (chamado pelo editor Quill).
// Salva em img/imagens/blog/ e devolve {url} com caminho a partir da raiz do
// site, para funcionar tanto no preview do admin quanto na página pública.
require_once __DIR__ . '/_auth.php';
header('Content-Type: application/json; charset=utf-8');

function upload_erro(string $msg, int $http = 400): void {
    http_response_code($http);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { upload_erro('Método inválido.', 405); }
if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
    upload_erro('Nenhuma imagem enviada.');
}

$arq = $_FILES['imagem'];
if ($arq['size'] > 5 * 1024 * 1024) { upload_erro('Imagem acima de 5 MB.'); }

// Valida que é imagem de verdade (não confia na extensão do nome).
$info = @getimagesize($arq['tmp_name']);
$tiposOk = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];
if ($info === false || !isset($tiposOk[$info[2]])) {
    upload_erro('Arquivo não é uma imagem válida (use JPG, PNG, GIF ou WEBP).');
}
$ext = $tiposOk[$info[2]];

$destinoDir = __DIR__ . '/../img/imagens/blog';
if (!is_dir($destinoDir)) { @mkdir($destinoDir, 0755, true); }

$nome = 'blogimg_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destino = $destinoDir . '/' . $nome;

if (!move_uploaded_file($arq['tmp_name'], $destino)) {
    upload_erro('Falha ao salvar a imagem no servidor.', 500);
}

echo json_encode(['url' => '/img/imagens/blog/' . $nome], JSON_UNESCAPED_UNICODE);
