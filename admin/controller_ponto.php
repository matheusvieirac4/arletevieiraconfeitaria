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

// ---- Feriados / folgas: adicionar ----
if ($acao === 'especial_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data  = (string) ($_POST['data'] ?? '');
    $tipo  = (string) ($_POST['tipo'] ?? 'feriado');
    $desc  = (string) ($_POST['descricao'] ?? '');
    $colab = ($_POST['colaborador_id'] ?? '') === '' ? null : (int) $_POST['colaborador_id'];
    $ano   = (int) (substr($data, 0, 4) ?: date('Y'));
    try {
        ponto_especial_salvar($pdo, $data, $colab, $tipo, $desc);
        ponto_redirect('success', 'Dia registrado.', 'ponto_feriados.php?ano=' . $ano);
    } catch (\Throwable $e) {
        ponto_redirect('danger', 'Falha: ' . $e->getMessage(), 'ponto_feriados.php?ano=' . $ano);
    }
}

// ---- Feriados / folgas: excluir ----
if ($acao === 'especial_excluir') {
    $eid = (int) ($_GET['id'] ?? 0);
    $ano = (int) ($_GET['ano'] ?? date('Y'));
    try {
        ponto_especial_excluir($pdo, $eid);
        ponto_redirect('success', 'Removido.', 'ponto_feriados.php?ano=' . $ano);
    } catch (\Throwable $e) {
        ponto_redirect('danger', 'Falha ao remover: ' . $e->getMessage(), 'ponto_feriados.php?ano=' . $ano);
    }
}

// ---- Importar feriados nacionais do ano ----
if ($acao === 'feriados_importar') {
    $ano = (int) ($_GET['ano'] ?? date('Y'));
    try {
        $n = ponto_feriados_importar($pdo, $ano);
        ponto_redirect('success', $n > 0 ? "$n feriado(s) importado(s)." : 'Nenhum novo (já estavam lançados).', 'ponto_feriados.php?ano=' . $ano);
    } catch (\Throwable $e) {
        ponto_redirect('danger', 'Falha ao importar: ' . $e->getMessage(), 'ponto_feriados.php?ano=' . $ano);
    }
}

ponto_redirect('danger', 'Ação inválida.');
