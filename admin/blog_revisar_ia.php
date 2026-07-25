<?php
// Revisão de post por IA (Gemini). Recebe título + HTML do editor e devolve
// texto reescrito + sugestões de SEO/legibilidade em JSON. Não grava nada.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/model_financeiro.php';        // reusa a config/chave do Gemini
require_once __DIR__ . '/lib/gemini_client.php';
require_once __DIR__ . '/../includes/blog_html.php';   // sanitiza o HTML da IA

header('Content-Type: application/json; charset=utf-8');

function ia_erro(string $msg, int $http = 400): void {
    http_response_code($http);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ia_erro('Método inválido.', 405); }
if (!financeiro_ia_configurada()) { ia_erro('A IA (Gemini) não está configurada.'); }

$titulo = trim((string) ($_POST['titulo'] ?? ''));
$html   = trim((string) ($_POST['conteudo_html'] ?? ''));
if ($html === '') { ia_erro('Nada para revisar.'); }

try {
    $ia = financeiro_gemini();
    $r  = $ia->revisarBlog($titulo, $html);
} catch (\Throwable $e) {
    ia_erro('Falha na revisão: ' . $e->getMessage(), 500);
}

// Sanitiza o HTML devolvido pela IA antes de mandar de volta ao editor.
if (!empty($r['conteudo_revisado_html'])) {
    $r['conteudo_revisado_html'] = blog_sanitizar_html((string) $r['conteudo_revisado_html']);
}
echo json_encode($r, JSON_UNESCAPED_UNICODE);
