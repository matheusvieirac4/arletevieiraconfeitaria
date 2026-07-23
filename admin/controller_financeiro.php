<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';
require_once __DIR__ . '/lib/nfe_parser.php';
require_once __DIR__ . '/lib/nfe_chave.php';

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

// -------- QR Code (NFC-e): chave de acesso -> nota parcial -> revisão --------
if ($acao === 'qr' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $bruto = isset($_POST['chave']) ? (string) $_POST['chave'] : '';
    $chave = NFeChave::extrairDeTexto($bruto);
    if ($chave === null) {
        financeiro_redirect('danger', 'Não encontrei uma chave de acesso válida no QR Code.');
    }
    try {
        $nota = NFeChave::parse($chave);
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'QR Code inválido: ' . $e->getMessage());
    }

    if (financeiro_ja_processada($nota['chave'])) {
        $reg = financeiro_registro_listar()[$nota['chave']];
        $quando = isset($reg['importado_em']) ? substr($reg['importado_em'], 0, 10) : '';
        financeiro_redirect('warning', "Esta nota (chave {$nota['chave']}) já foi importada em {$quando}.");
    }

    $_SESSION['financeiro_revisao'] = $nota;
    header('Location: financeiro.php');
    exit;
}

// -------- IA: texto livre ou foto de cupom -> lançamento -> revisão --------
if (($acao === 'texto' || $acao === 'cupom') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!financeiro_ia_configurada()) {
        financeiro_redirect('danger', 'A IA (Gemini) não está configurada. Adicione gemini_api_key em config_financeiro.php.');
    }
    try {
        $api = financeiro_api();
        $ctx = financeiro_contexto_cadastros($api);
        $ia  = financeiro_gemini();

        if ($acao === 'texto') {
            $texto = trim((string) ($_POST['texto'] ?? ''));
            if ($texto === '') {
                financeiro_redirect('danger', 'Escreva a compra no campo de texto.');
            }
            $lanc = $ia->extrairTexto($texto, $ctx);
            $origem = 'Texto (IA)';
        } else {
            if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
                financeiro_redirect('danger', 'Selecione uma foto válida do cupom.');
            }
            $tmp  = $_FILES['foto']['tmp_name'];
            $mime = mime_content_type($tmp) ?: 'image/jpeg';
            $b64  = base64_encode((string) file_get_contents($tmp));
            $lanc = $ia->extrairImagem($b64, $mime, $ctx, trim((string) ($_POST['texto'] ?? '')));
            $origem = 'Foto do cupom (IA)';
        }
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Falha na leitura pela IA: ' . $e->getMessage());
    }

    // Monta a nota no formato da revisão (sem chave — não deduplica texto/foto).
    $_SESSION['financeiro_revisao'] = [
        'chave'             => '',
        'numero'            => '',
        'serie'             => '',
        'emissao'           => $lanc['competence_date'] ?? '',
        'natureza_operacao' => $origem,
        'fornecedor'        => ['nome' => $lanc['supplier'] ?? '', 'cnpj' => ''],
        'valor_total'       => $lanc['value'] ?? '', // financeiro_valor_br() mostra sem sinal
        'parcelas'          => [],
        'itens'             => [],
        'avisos'            => ['Extraído por IA — confira todos os campos antes de enviar.'],
        'lancamento'        => $lanc,
    ];
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
