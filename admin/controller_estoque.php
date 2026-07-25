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

// ---- Entrada por XML: parseia a nota e monta a revisão ----
if ($acao === 'entrada_xml' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/lib/nfe_parser.php';
    if (!isset($_FILES['xml']) || $_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
        estoque_redirect('danger', 'Selecione um arquivo XML válido.', 'estoque_entrada.php');
    }
    try {
        $nota = NFeParser::parse((string) file_get_contents($_FILES['xml']['tmp_name']));
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'XML inválido: ' . $e->getMessage(), 'estoque_entrada.php');
    }
    // Pré-casa cada item da nota com o estoque.
    $cache = estoque_listar($pdo);
    $linhas = [];
    foreach ($nota['itens'] as $it) {
        $casa = estoque_casar_item($it['ean'] ?? '', $it['descricao'] ?? '', $cache);
        $linhas[] = [
            'descricao' => $it['descricao'] ?? '',
            'ean'       => preg_replace('/\D/', '', (string) ($it['ean'] ?? '')),
            'quantidade'=> (float) str_replace(',', '.', (string) ($it['quantidade'] ?? '0')),
            'unidade'   => $it['unidade'] ?? '',
            'item_id'   => $casa['item_id'],
            'match'     => $casa['match'],
        ];
    }
    $_SESSION['estoque_entrada'] = [
        'fornecedor' => $nota['fornecedor']['nome'] ?? '',
        'numero'     => $nota['numero'] ?? '',
        'linhas'     => $linhas,
    ];
    header('Location: estoque_entrada.php');
    exit;
}

// ---- Entrada: confirma as linhas revisadas e dá entrada no estoque ----
if ($acao === 'entrada_confirmar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rev = $_SESSION['estoque_entrada'] ?? null;
    if (!$rev) { estoque_redirect('danger', 'Nada para confirmar.', 'estoque_entrada.php'); }

    $itemIds = $_POST['item_id'] ?? [];
    $qtds    = $_POST['quantidade'] ?? [];
    $eans    = $_POST['ean'] ?? [];
    $descs   = $_POST['descricao'] ?? [];
    $aplicadas = 0;

    try {
        foreach ($itemIds as $i => $sel) {
            $qtd = (float) str_replace(',', '.', (string) ($qtds[$i] ?? '0'));
            if ($qtd <= 0 || $sel === 'ignorar') { continue; }
            $ean = preg_replace('/\D/', '', (string) ($eans[$i] ?? ''));

            if ($sel === 'novo') {
                $id = estoque_criar($pdo, [
                    'nome'          => trim((string) ($descs[$i] ?? 'Novo item')),
                    'codigo_barras' => $ean,
                    'estoque_atual' => 0,
                ]);
            } else {
                $id = (int) $sel;
                if ($id <= 0) { continue; }
                if ($ean !== '') { estoque_definir_barcode($pdo, $id, $ean); }
            }
            estoque_movimentar($pdo, $id, 'entrada', $qtd, 'xml');
            $aplicadas++;
        }
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'Falha ao dar entrada: ' . $e->getMessage(), 'estoque_entrada.php');
    }

    unset($_SESSION['estoque_entrada']);
    estoque_redirect('success', "Entrada concluída: $aplicadas item(ns) atualizados.");
}

// ---- Descartar a entrada em revisão ----
if ($acao === 'entrada_cancelar') {
    unset($_SESSION['estoque_entrada']);
    estoque_redirect('info', 'Entrada descartada.', 'estoque_entrada.php');
}

// ---- Excluir (soft delete) ----
if ($acao === 'excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) { estoque_deletar($pdo, $id); }
    estoque_redirect('success', 'Item removido.');
}

header('Location: estoque.php');
exit;
