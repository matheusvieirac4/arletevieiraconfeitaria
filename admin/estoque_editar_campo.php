<?php
// Edição inline de UM campo do item (preço, mínimo ou ideal) direto na listagem.
// POST: id, campo, valor. Devolve JSON {ok, abaixo} ou {error}.
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';
header('Content-Type: application/json; charset=utf-8');

function edc_out($d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); edc_out(['error' => 'Método inválido.']); }

$id    = (int) ($_POST['id'] ?? 0);
$campo = (string) ($_POST['campo'] ?? '');
$bruto = (string) ($_POST['valor'] ?? '');

$permitidos = ['preco', 'estoque_minimo', 'estoque_ideal'];   // whitelist (evita SQL na coluna)
if ($id <= 0 || !in_array($campo, $permitidos, true)) { http_response_code(400); edc_out(['error' => 'Parâmetros inválidos.']); }

// Número BR: vazio = null; "1.500,00" -> 1500.00 (parser único do estoque)
if (trim($bruto) === '') {
    $valor = null;
} else {
    $valor = estoque_num($bruto);
    if ($valor === null) { http_response_code(400); edc_out(['error' => 'Número inválido.']); }
}

try {
    $pdo->prepare("UPDATE estoque_itens SET {$campo} = :v WHERE id = :id")
        ->execute([':v' => $valor, ':id' => $id]);
    $it = estoque_buscar($pdo, $id);
    if (!$it) { http_response_code(404); edc_out(['error' => 'Item não encontrado.']); }
    $min = $it['estoque_minimo'];
    $abaixo = $min !== null && (float) $it['estoque_atual'] < (float) $min;
    edc_out(['ok' => true, 'abaixo' => $abaixo]);
} catch (\Throwable $e) {
    http_response_code(500);
    edc_out(['error' => 'Falha ao salvar: ' . $e->getMessage()]);
}
