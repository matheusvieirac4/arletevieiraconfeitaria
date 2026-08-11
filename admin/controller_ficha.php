<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$acao = $_GET['acao'] ?? '';

function ficha_redirect(string $tipo, string $texto, string $para = 'ficha_receitas.php'): void
{
    $_SESSION['ficha_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: ' . $para);
    exit;
}

// ---- Salvar receita (cabeçalho + ingredientes numa tacada) ----
if ($acao === 'receita_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $id = ficha_receita_salvar($pdo, $id, $_POST);
        ficha_receita_itens_salvar($pdo, $id, $_POST['item_id'] ?? [], $_POST['quantidade'] ?? []);
        ficha_redirect('success', 'Receita salva.', 'ficha_receita.php?id=' . $id);
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha ao salvar: ' . $e->getMessage(),
            $id > 0 ? 'ficha_receita.php?id=' . $id : 'ficha_receita.php');
    }
}

// ---- Excluir receita (soft delete) ----
if ($acao === 'receita_excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) { ficha_receita_excluir($pdo, $id); }
    ficha_redirect('success', 'Receita removida.', 'ficha_receitas.php');
}

// ---- Registrar custo da receita no histórico (snapshot manual) ----
if ($acao === 'receita_snapshot') {
    $id = (int) ($_GET['id'] ?? 0);
    $volta = 'ficha_receita.php?id=' . $id;
    if ($id <= 0) { ficha_redirect('danger', 'Receita inválida.', 'ficha_receitas.php'); }
    try {
        ficha_receita_snapshot($pdo, $id, estoque_responsavel_atual());
        ficha_redirect('success', 'Custo registrado no histórico.', $volta);
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha ao registrar: ' . $e->getMessage(), $volta);
    }
}

// ---- Salvar produto (cabeçalho + componentes) ----
if ($acao === 'produto_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $id = ficha_produto_salvar($pdo, $id, $_POST);
        ficha_produto_componentes_salvar(
            $pdo, $id,
            $_POST['bloco'] ?? [], $_POST['tipo'] ?? [],
            $_POST['ref_id'] ?? [], $_POST['quantidade'] ?? []
        );
        ficha_redirect('success', 'Produto salvo.', 'ficha_produto.php?id=' . $id);
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha ao salvar: ' . $e->getMessage(),
            $id > 0 ? 'ficha_produto.php?id=' . $id : 'ficha_produto.php');
    }
}

// ---- Excluir produto (soft delete) ----
if ($acao === 'produto_excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) { ficha_produto_excluir($pdo, $id); }
    ficha_redirect('success', 'Produto removido.', 'ficha_produtos.php');
}

// ---- Registrar CMV do produto no histórico (snapshot do custo atual) ----
if ($acao === 'cmv_registrar') {
    $id = (int) ($_GET['id'] ?? 0);
    $volta = 'ficha_produto.php?id=' . $id;
    if ($id <= 0) { ficha_redirect('danger', 'Produto inválido.', 'ficha_produtos.php'); }
    try {
        ficha_cmv_registrar($pdo, $id, estoque_responsavel_atual());
        ficha_redirect('success', 'CMV registrado no histórico.', $volta);
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha ao registrar CMV: ' . $e->getMessage(), $volta);
    }
}

// ---- Registrar CMV de TODOS os produtos (fechamento em lote) ----
if ($acao === 'cmv_registrar_todos') {
    $n = 0;
    try {
        foreach (ficha_produtos_listar($pdo) as $p) {
            ficha_cmv_registrar($pdo, (int) $p['id'], estoque_responsavel_atual());
            $n++;
        }
        ficha_redirect('success', "CMV registrado para $n produto(s).", 'ficha_cmv.php');
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha ao registrar em lote: ' . $e->getMessage(), 'ficha_cmv.php');
    }
}

// ---- Categorias: criar / excluir ----
if ($acao === 'cat_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo = (string) ($_POST['tipo'] ?? '');
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $volta = 'ficha_categorias.php?tipo=' . ($tipo === 'produto' ? 'produto' : 'receita');
    if ($nome === '') { ficha_redirect('danger', 'Informe o nome da categoria.', $volta); }
    try {
        ficha_categoria_salvar($pdo, $tipo, $nome);
        ficha_redirect('success', 'Categoria adicionada.', $volta);
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha: ' . $e->getMessage(), $volta);
    }
}

if ($acao === 'cat_renomear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $tipo = (string) ($_POST['tipo'] ?? '');
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $volta = 'ficha_categorias.php?tipo=' . ($tipo === 'produto' ? 'produto' : 'receita');
    if ($nome === '') { ficha_redirect('danger', 'Informe o nome da categoria.', $volta); }
    try {
        ficha_categoria_renomear($pdo, $id, $nome);
        ficha_redirect('success', 'Categoria atualizada.', $volta);
    } catch (\Throwable $e) {
        ficha_redirect('danger', 'Falha: ' . $e->getMessage(), $volta);
    }
}

if ($acao === 'cat_excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    $tipo = (string) ($_GET['tipo'] ?? 'receita');
    if ($id > 0) { ficha_categoria_excluir($pdo, $id); }
    ficha_redirect('success', 'Categoria removida.', 'ficha_categorias.php?tipo=' . ($tipo === 'produto' ? 'produto' : 'receita'));
}

header('Location: ficha_receitas.php');
exit;
