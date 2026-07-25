<?php
// API JSON do quiosque de estoque. Tudo autenticado (o celular da porta fica
// logado com a sessão de 30 dias). Ações: lookup, buscar, baixa, associar.
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

header('Content-Type: application/json; charset=utf-8');

function api_out($d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function api_erro(string $msg, int $http = 400): void { http_response_code($http); api_out(['error' => $msg]); }

/** Formata um item para o quiosque (números crus; a tela formata). */
function estoque_item_publico(array $it): array
{
    return [
        'id'    => (int) $it['id'],
        'nome'  => $it['nome'],
        'saldo' => (float) $it['estoque_atual'],
        'imagem'=> $it['imagem'] ? '/img/imagens/estoque/' . $it['imagem'] : null,
        'barras'=> $it['codigo_barras'],
    ];
}

$acao = $_GET['acao'] ?? '';

// ---- Lookup por código de barras ----
if ($acao === 'lookup') {
    $cod = preg_replace('/\D/', '', (string) ($_GET['codigo'] ?? ''));
    if ($cod === '') { api_erro('Código vazio.'); }
    $it = estoque_buscar_por_barcode($pdo, $cod);
    if (!$it) { api_out(['found' => false, 'codigo' => $cod]); }
    api_out(['found' => true, 'item' => estoque_item_publico($it)]);
}

// ---- Buscar itens por nome (grade/busca do quiosque) ----
if ($acao === 'buscar') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $itens = estoque_listar($pdo, $q);
    $itens = array_slice($itens, 0, 60);
    api_out(['itens' => array_map('estoque_item_publico', $itens)]);
}

// ---- Colaboradores (lista para a 1ª tela do quiosque) ----
if ($acao === 'colaboradores') {
    api_out(['colaboradores' => estoque_colaboradores_listar($pdo)]);
}

// ---- Verifica o PIN do colaborador ----
if ($acao === 'pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int) ($_POST['colaborador_id'] ?? 0);
    $pin = (string) ($_POST['pin'] ?? '');
    $nome = estoque_colaborador_verificar($pdo, $id, $pin);
    if ($nome === null) { api_out(['ok' => false]); }
    api_out(['ok' => true, 'nome' => $nome]);
}

// ---- Baixa (saída) ----
if ($acao === 'baixa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int) ($_POST['item_id'] ?? 0);
    $qtd   = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '0'));
    $colab = (int) ($_POST['colaborador_id'] ?? 0);
    if ($id <= 0 || $qtd <= 0) { api_erro('Item ou quantidade inválidos.'); }
    // O responsável vem do id do colaborador (o cliente não injeta o nome).
    $resp = $colab > 0 ? estoque_colaborador_nome($pdo, $colab) : '';
    try {
        $saldo = estoque_movimentar($pdo, $id, 'saida', $qtd, 'quiosque', '', $resp);
        api_out(['ok' => true, 'saldo' => $saldo]);
    } catch (\Throwable $e) {
        api_erro('Falha na baixa: ' . $e->getMessage(), 500);
    }
}

// ---- Associar um código de barras novo a um item existente ----
if ($acao === 'associar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int) ($_POST['item_id'] ?? 0);
    $cod = preg_replace('/\D/', '', (string) ($_POST['codigo'] ?? ''));
    if ($id <= 0 || $cod === '') { api_erro('Item ou código inválidos.'); }
    $item = estoque_buscar($pdo, $id);
    if (!$item) { api_erro('Item não encontrado.'); }
    // Não sobrescreve se o código já pertence a outro item.
    $jaExiste = estoque_buscar_por_barcode($pdo, $cod);
    if ($jaExiste && (int) $jaExiste['id'] !== $id) {
        api_erro('Este código já está em "' . $jaExiste['nome'] . '".');
    }
    $item['codigo_barras'] = $cod;
    estoque_atualizar($pdo, $id, $item);
    api_out(['ok' => true, 'item' => estoque_item_publico(estoque_buscar($pdo, $id))]);
}

api_erro('Ação inválida.', 404);
