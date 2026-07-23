<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';

$acao = isset($_GET['acao']) ? $_GET['acao'] : '';

function financeiro_redirect(string $tipo, string $texto): void
{
    $_SESSION['financeiro_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: financeiro.php');
    exit;
}

// -------- Testar conexão: autentica e lê os 5 cadastros (passos 1 e 2) --------
if ($acao === 'testar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $api = financeiro_api();
        $contas       = $api->listarContas();
        $categorias   = $api->listarCategorias();
        $fornecedores = $api->listarFornecedores();
        $formas       = $api->listarFormasPagamento();
        $centros      = $api->listarCentrosCusto();

        // A API às vezes embrulha em {"data":[...]}; conta os dois formatos.
        $contar = function ($r) {
            if (isset($r['data']) && is_array($r['data'])) return count($r['data']);
            return is_array($r) ? count($r) : 0;
        };

        $_SESSION['financeiro_teste'] = [
            'ok'           => true,
            'contas'       => $contar($contas),
            'categorias'   => $contar($categorias),
            'fornecedores' => $contar($fornecedores),
            'formas'       => $contar($formas),
            'centros'      => $contar($centros),
        ];
    } catch (\Throwable $e) {
        $_SESSION['financeiro_teste'] = ['ok' => false, 'erro' => $e->getMessage()];
    }
    header('Location: financeiro.php');
    exit;
}

header('Location: financeiro.php');
exit;
