<?php
// Edição inline do preço (Direta/iFood) de um produto direto na listagem.
// POST: id, canal (direta|ifood), valor. Calcula o markup e salva. Devolve JSON
// com os valores recalculados para atualizar a linha.
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';
header('Content-Type: application/json; charset=utf-8');

function fep_out($d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); fep_out(['error' => 'Método inválido.']); }

$id    = (int) ($_POST['id'] ?? 0);
$canal = ($_POST['canal'] ?? '') === 'ifood' ? 'ifood' : 'direta';
$valor = estoque_num_manual((string) ($_POST['valor'] ?? ''));

if ($id <= 0 || $valor === null || $valor <= 0) { http_response_code(400); fep_out(['error' => 'Valor inválido.']); }

try {
    ficha_produto_set_preco($pdo, $id, $canal, $valor);
    $c = ficha_precificar($pdo, $id);
    fep_out([
        'ok'            => true,
        'preco_direta'  => $c['preco_direta'],
        'preco_ifood'   => $c['preco_ifood'],
        'cmv_direta'    => $c['cmv_direta_pct'],
        'margem_direta' => $c['margem_direta_pct'],
        'cmv_ifood'     => $c['cmv_ifood_pct'],
        'margem_ifood'  => $c['margem_ifood_pct'],
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    fep_out(['error' => $e->getMessage()]);
}
