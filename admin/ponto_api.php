<?php
// API JSON do relógio de ponto. Acesso: admin logado OU token do quiosque
// (o mesmo cookie do aparelho do estoque). Ações: pessoas, pin, status, bater.
require_once __DIR__ . '/_session.php';
require_once 'model_ponto.php';

header('Content-Type: application/json; charset=utf-8');

function pt_out($d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function pt_erro(string $msg, int $http = 400): void { http_response_code($http); pt_out(['error' => $msg]); }

if (!estoque_kiosk_autorizado()) { pt_erro('Não autorizado.', 401); }
if (!ponto_pronto($pdo))         { pt_erro('Ponto não inicializado.', 503); }

$acao = $_GET['acao'] ?? '';

// ---- Pessoas (1ª tela do relógio) ----
if ($acao === 'pessoas') {
    $ps = array_map(function ($p) {
        return ['id' => (int) $p['id'], 'nome' => $p['nome']];
    }, ponto_pessoas($pdo, true));
    pt_out(['pessoas' => $ps]);
}

// ---- Verifica PIN e devolve o estado atual (dentro/fora) ----
if ($acao === 'pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int) ($_POST['colaborador_id'] ?? 0);
    $pin = (string) ($_POST['pin'] ?? '');
    $nome = estoque_colaborador_verificar($pdo, $id, $pin);
    if ($nome === null) { pt_out(['ok' => false]); }
    pt_out(['ok' => true, 'nome' => $nome, 'status' => ponto_status($pdo, $id)]);
}

// ---- Bater ponto (toggle entrada/saída) ----
if ($acao === 'bater' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int) ($_POST['colaborador_id'] ?? 0);
    $pin = (string) ($_POST['pin'] ?? '');
    // Reexige o PIN na hora de bater (o cliente não injeta identidade).
    $nome = estoque_colaborador_verificar($pdo, $id, $pin);
    if ($nome === null) { pt_erro('PIN inválido.', 401); }
    try {
        $r = ponto_registrar($pdo, $id, 'kiosk');
        pt_out(['ok' => true, 'nome' => $nome, 'tipo' => $r['tipo'],
                'hora' => substr($r['momento'], 11, 5),
                'duplicado' => !empty($r['duplicado']),
                'esqueceu' => !empty($r['esqueceu']),
                'status' => ponto_status($pdo, $id)]);
    } catch (\Throwable $e) {
        pt_erro('Falha ao bater ponto: ' . $e->getMessage(), 500);
    }
}

pt_erro('Ação inválida.', 404);
