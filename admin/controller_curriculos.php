<?php
// Controller do módulo Currículos (área admin): alterar status e excluir.
// O cadastro público vem por /trabalhe-conosco.php (não passa aqui).
require_once __DIR__ . '/_auth.php';
require_once 'model_curriculos.php';

$acao = $_GET['acao'] ?? '';

function curriculos_redirect(string $tipo, string $texto, string $para = 'curriculos.php'): void {
    $_SESSION['curriculos_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: ' . $para);
    exit;
}

if ($acao === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $volta = 'curriculos.php' . ($id ? '?id=' . $id : '');
    if (curriculos_atualizar_status($pdo, $id, $status)) {
        curriculos_redirect('success', 'Status atualizado.', $volta);
    }
    curriculos_redirect('danger', 'Não foi possível atualizar o status.', $volta);
}

if ($acao === 'deletar') {
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $reg = curriculos_buscar($pdo, $id);
        if ($reg && !empty($reg['curriculo_pdf'])) {
            $arq = __DIR__ . '/data/curriculos/' . basename($reg['curriculo_pdf']);
            if (is_file($arq)) { @unlink($arq); }
        }
        curriculos_deletar($pdo, $id);
        curriculos_redirect('success', 'Currículo excluído.');
    } catch (\Throwable $e) {
        curriculos_redirect('danger', 'Falha ao excluir: ' . $e->getMessage());
    }
}

curriculos_redirect('warning', 'Ação inválida.');
