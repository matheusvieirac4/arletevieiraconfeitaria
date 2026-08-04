<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ponto.php';

$acao = $_GET['acao'] ?? '';

function ponto_redirect(string $tipo, string $texto, string $para = 'ponto.php'): void
{
    $_SESSION['estoque_flash'] = ['tipo' => $tipo, 'texto' => $texto];   // reusa o toast do _footer
    header('Location: ' . $para);
    exit;
}

/** Quem está logado (para registrar como autor de uma correção manual). */
function ponto_autor(): string
{
    return (string) ($_SESSION['admin_nome'] ?? 'Admin');
}

// ---- Salvar jornada de uma pessoa ----
if ($acao === 'jornada_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['colaborador_id'] ?? 0);
    try {
        ponto_jornada_salvar($pdo, $id, $_POST);
        ponto_redirect('success', 'Jornada salva.', 'ponto_funcionario.php?id=' . $id);
    } catch (\Throwable $e) {
        ponto_redirect('danger', 'Falha ao salvar jornada: ' . $e->getMessage(), 'ponto_funcionario.php?id=' . $id);
    }
}

// ---- Salvar/editar uma batida manualmente ----
if ($acao === 'batida_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int) ($_POST['colaborador_id'] ?? 0);
    $volta = 'ponto_funcionario.php?id=' . $id;
    if (isset($_POST['mes']))  { $volta .= '&mes=' . urlencode((string) $_POST['mes']); }
    try {
        ponto_batida_salvar($pdo, $_POST, ponto_autor());
        ponto_redirect('success', 'Batida registrada.', $volta);
    } catch (\Throwable $e) {
        ponto_redirect('danger', 'Falha: ' . $e->getMessage(), $volta);
    }
}

// ---- Excluir uma batida ----
if ($acao === 'batida_excluir') {
    $bid   = (int) ($_GET['id'] ?? 0);
    $id    = (int) ($_GET['colaborador_id'] ?? 0);
    $volta = 'ponto_funcionario.php?id=' . $id;
    if (isset($_GET['mes'])) { $volta .= '&mes=' . urlencode((string) $_GET['mes']); }
    try {
        ponto_batida_excluir($pdo, $bid);
        ponto_redirect('success', 'Batida removida.', $volta);
    } catch (\Throwable $e) {
        ponto_redirect('danger', 'Falha ao remover: ' . $e->getMessage(), $volta);
    }
}

ponto_redirect('danger', 'Ação inválida.');
