<?php
// Recebe o sinal de vida do bot (rodando no PC) e grava o timestamp + status.
// O bot faz POST aqui a cada 2 min.
require __DIR__ . '/config.php';
header('Content-Type: application/json');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

// Confere o segredo (evita que qualquer um zere seu monitor).
if (!is_array($data) || ($data['secret'] ?? '') !== HB_SECRET) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'segredo invalido']);
    exit;
}

$estado = [
    'last_seen' => time(),
    'status'    => (string)($data['status'] ?? 'unknown'),  // 'open' = WhatsApp conectado
];
file_put_contents(HB_STATE_FILE, json_encode($estado), LOCK_EX);

echo json_encode(['ok' => true]);
