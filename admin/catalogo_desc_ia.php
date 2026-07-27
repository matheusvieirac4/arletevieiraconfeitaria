<?php
// Gera uma sugestão de descrição de produto do catálogo via IA (Gemini).
// Recebe nome (+ categoria/subcategoria/contexto) e devolve {descricao}.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/model_financeiro.php';   // reusa a config/chave do Gemini
require_once __DIR__ . '/lib/gemini_client.php';

header('Content-Type: application/json; charset=utf-8');

function desc_ia_erro(string $msg, int $http = 400): void {
    http_response_code($http);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { desc_ia_erro('Método inválido.', 405); }
if (!financeiro_ia_configurada()) { desc_ia_erro('A IA (Gemini) não está configurada.'); }

$nome = trim((string) ($_POST['nome'] ?? ''));
$cat  = trim((string) ($_POST['categoria'] ?? ''));
$sub  = trim((string) ($_POST['subcategoria'] ?? ''));
$ctx  = trim((string) ($_POST['contexto'] ?? ''));
if ($nome === '') { desc_ia_erro('Informe o nome do produto antes de gerar.'); }

try {
    $ia = financeiro_gemini();
    $r  = $ia->descricaoProduto($nome, $cat, $sub, $ctx);
} catch (\Throwable $e) {
    desc_ia_erro('Falha ao gerar: ' . $e->getMessage(), 500);
}

echo json_encode(['descricao' => trim((string) ($r['descricao'] ?? ''))], JSON_UNESCAPED_UNICODE);
