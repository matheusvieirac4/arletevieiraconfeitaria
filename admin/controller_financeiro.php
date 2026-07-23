<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';
require_once __DIR__ . '/lib/nfe_parser.php';

$acao = isset($_GET['acao']) ? $_GET['acao'] : '';

function financeiro_redirect(string $tipo, string $texto): void
{
    $_SESSION['financeiro_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: financeiro.php');
    exit;
}

// -------- Testar conexão: autentica e lê os 5 cadastros --------
if ($acao === 'testar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $api = financeiro_api();
        $_SESSION['financeiro_teste'] = [
            'ok'           => true,
            'contas'       => count(financeiro_extrair_lista($api->listarContas())),
            'categorias'   => count(financeiro_extrair_lista($api->listarCategorias())),
            'fornecedores' => count(financeiro_extrair_lista($api->listarFornecedores())),
            'formas'       => count(financeiro_extrair_lista($api->listarFormasPagamento())),
            'centros'      => count(financeiro_extrair_lista($api->listarCentrosCusto())),
        ];
    } catch (\Throwable $e) {
        $_SESSION['financeiro_teste'] = ['ok' => false, 'erro' => $e->getMessage()];
    }
    header('Location: financeiro.php');
    exit;
}

// -------- Upload de XML: parseia e manda para a revisão --------
if ($acao === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['xml']) || $_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
        financeiro_redirect('danger', 'Selecione um arquivo XML válido.');
    }
    $ext = strtolower(pathinfo($_FILES['xml']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xml') {
        financeiro_redirect('danger', 'O arquivo precisa ser um XML de NF-e.');
    }

    $conteudo = file_get_contents($_FILES['xml']['tmp_name']);
    try {
        $nota = NFeParser::parse((string) $conteudo);
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Não foi possível ler a NF-e: ' . $e->getMessage());
    }

    if (!empty($nota['chave']) && financeiro_ja_processada($nota['chave'])) {
        $reg = financeiro_registro_listar()[$nota['chave']];
        $quando = isset($reg['importado_em']) ? substr($reg['importado_em'], 0, 10) : '';
        financeiro_redirect('warning', "Esta nota (chave {$nota['chave']}) já foi importada em {$quando}. Não foi carregada de novo para evitar duplicidade.");
    }

    $_SESSION['financeiro_revisao'] = $nota;
    header('Location: financeiro.php');
    exit;
}

// -------- Cancelar a revisão em andamento --------
if ($acao === 'cancelar') {
    unset($_SESSION['financeiro_revisao']);
    header('Location: financeiro.php');
    exit;
}

// -------- Importar: monta o JSON revisado e envia ao Cardápio Web --------
if ($acao === 'importar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nota = $_SESSION['financeiro_revisao'] ?? null;
    if (!$nota) {
        financeiro_redirect('danger', 'Nenhuma nota em revisão. Envie o XML novamente.');
    }

    $campo = fn(string $k) => trim((string) ($_POST[$k] ?? ''));

    $account = $campo('account');
    if ($account === '') {
        financeiro_redirect('danger', 'Selecione a Conta (obrigatório).');
    }
    // O usuário digita em reais (ex.: 3.135,58); aqui vira despesa negativa com ponto.
    $value = financeiro_valor_para_envio($campo('value'));
    if ($value === null) {
        financeiro_redirect('danger', 'Valor inválido.');
    }

    $lancamento = [
        'account'         => $account,
        'category'        => $campo('category'),
        'cost_center'     => $campo('cost_center'),
        'supplier'        => $campo('supplier'),
        'payment_method'  => $campo('payment_method'),
        'description'     => $campo('description'),
        'observation'     => $campo('observation'),
        'value'           => $value,
        'due_date'        => $campo('due_date'),
        'competence_date' => $campo('competence_date'),
        'settlement_date' => $campo('settlement_date'),
    ];

    try {
        $api = financeiro_api();
        $api->importarLancamentos([$lancamento]);
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Falha ao enviar ao Cardápio Web: ' . $e->getMessage());
    }

    // Sucesso: marca a nota como processada (idempotência) e limpa a revisão.
    if (!empty($nota['chave'])) {
        financeiro_marcar_processada($nota['chave'], [
            'numero'     => $nota['numero'] ?? '',
            'fornecedor' => $nota['fornecedor']['nome'] ?? '',
            'valor'      => $lancamento['value'],
        ]);
    }
    // Aprende a classificação deste fornecedor para pré-preencher as próximas.
    financeiro_regra_salvar(
        $nota['fornecedor']['cnpj'] ?? '',
        $nota['fornecedor']['nome'] ?? '',
        $lancamento
    );
    unset($_SESSION['financeiro_revisao']);
    financeiro_redirect('success', 'Lançamento enviado com sucesso ao Cardápio Web! Confira em Contas a pagar.');
}

header('Location: financeiro.php');
exit;
