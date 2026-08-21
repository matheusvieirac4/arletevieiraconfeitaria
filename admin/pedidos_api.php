<?php
// API JSON dos alertas de despacho de entregas (consumida pelo quiosque).
// Acesso: admin logado OU token do quiosque (mesmo do estoque/ponto).
//   ?acao=alertas          -> entregas a despachar + minutos restantes
//   ?acao=alertas&mock=1   -> dados falsos (testar o alarme sem a API)
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/model_estoque.php';   // estoque_kiosk_autorizado()
require_once __DIR__ . '/model_pedidos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pedidos_api_out($d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function pedidos_api_erro(string $m, int $http = 400): void { http_response_code($http); pedidos_api_out(['error' => $m]); }

if (!estoque_kiosk_autorizado()) { pedidos_api_erro('Não autorizado.', 401); }

$acao = $_GET['acao'] ?? 'alertas';

if ($acao === 'alertas') {
    $mock = !empty($_GET['mock']);
    try {
        pedidos_api_out(pedidos_alertas($mock));
    } catch (\Throwable $e) {
        // Não derruba o quiosque: devolve erro legível para a tela exibir
        // discretamente (o alarme continua valendo p/ o que já carregou).
        pedidos_api_erro($e->getMessage(), 502);
    }
}

pedidos_api_erro('Ação inválida.', 404);
