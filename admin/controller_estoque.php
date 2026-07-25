<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

$acao = $_GET['acao'] ?? '';

function estoque_redirect(string $tipo, string $texto, string $para = 'estoque.php'): void
{
    $_SESSION['estoque_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: ' . $para);
    exit;
}

// ---- Salvar item (novo ou edição) ----
if (($acao === 'salvar') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (trim((string) ($_POST['nome'] ?? '')) === '') {
        estoque_redirect('danger', 'O nome do item é obrigatório.');
    }
    try {
        if ($id > 0) {
            estoque_atualizar($pdo, $id, $_POST);
            estoque_redirect('success', 'Item atualizado.');
        } else {
            $novo = estoque_criar($pdo, $_POST);
            estoque_redirect('success', 'Item cadastrado.', 'estoque_item.php?id=' . $novo);
        }
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'Falha ao salvar: ' . $e->getMessage());
    }
}

// ---- Movimentar saldo (entrada / saída / ajuste) ----
if ($acao === 'movimentar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int) ($_POST['item_id'] ?? 0);
    $tipo = (string) ($_POST['tipo'] ?? '');
    $qtd  = (float) str_replace(',', '.', (string) ($_POST['quantidade'] ?? '0'));
    $obs  = trim((string) ($_POST['observacao'] ?? ''));
    $volta = 'estoque_item.php?id=' . $id;
    if ($id <= 0 || $qtd <= 0 && $tipo !== 'ajuste') {
        estoque_redirect('danger', 'Informe uma quantidade válida.', $volta);
    }
    try {
        $novo = estoque_movimentar($pdo, $id, $tipo, $qtd, 'manual', $obs);
        estoque_redirect('success', 'Saldo atualizado para ' . rtrim(rtrim(number_format($novo, 3, ',', '.'), '0'), ',') . '.', $volta);
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'Falha na movimentação: ' . $e->getMessage(), $volta);
    }
}

// ---- Excluir (soft delete) ----
if ($acao === 'excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) { estoque_deletar($pdo, $id); }
    estoque_redirect('success', 'Item removido.');
}

header('Location: estoque.php');
exit;
