<?php
// Alteração inline do status do currículo direto na listagem (AJAX).
// POST: id, status. Devolve JSON {ok, status, rotulo, cor} ou {error}.
require_once __DIR__ . '/_auth.php';
require_once 'model_curriculos.php';
header('Content-Type: application/json; charset=utf-8');

function css_out($d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); css_out(['error' => 'Método inválido.']); }

$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
$map    = curriculos_status_map();

if ($id <= 0 || !array_key_exists($status, $map)) {
    http_response_code(400);
    css_out(['error' => 'Parâmetros inválidos.']);
}

try {
    if (!curriculos_atualizar_status($pdo, $id, $status)) {
        throw new RuntimeException('não foi possível atualizar.');
    }
    css_out(['ok' => true, 'status' => $status, 'rotulo' => $map[$status][0], 'cor' => $map[$status][1]]);
} catch (\Throwable $e) {
    http_response_code(500);
    css_out(['error' => 'Falha ao salvar: ' . $e->getMessage()]);
}
