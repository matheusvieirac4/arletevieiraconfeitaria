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

// -------- Salvar credenciais pelo painel (sem mexer em arquivo no servidor) --------
if ($acao === 'salvar_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = array_keys(financeiro_config_campos());
    $novos = [];
    foreach ($campos as $c) {
        if (isset($_POST[$c])) { $novos[$c] = $_POST[$c]; }
    }
    if (!financeiro_config_salvar($novos)) {
        financeiro_redirect('danger', 'Não consegui gravar as credenciais. Verifique a permissão da pasta admin/data/.');
    }
    financeiro_redirect('success', 'Credenciais atualizadas. A bolinha ao lado do título mostra se a conexão voltou.');
}

// -------- Status da conexão em JSON (chamado sozinho ao abrir a página) --------
// Mesma checagem do "testar", mas sem redirect/flash: alimenta a bolinha ao
// lado do título. Fica em GET porque é só leitura.
if ($acao === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $api = financeiro_api();
        echo json_encode([
            'ok'           => true,
            'contas'       => count(financeiro_extrair_lista($api->listarContas())),
            'categorias'   => count(financeiro_extrair_lista($api->listarCategorias())),
            'fornecedores' => count(financeiro_extrair_lista($api->listarFornecedores())),
            'formas'       => count(financeiro_extrair_lista($api->listarFormasPagamento())),
            'centros'      => count(financeiro_extrair_lista($api->listarCentrosCusto())),
        ], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
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

    // Nome do fornecedor não vem na chave — busca pelo CNPJ (BrasilAPI, grátis).
    $forn = financeiro_consultar_cnpj($nota['fornecedor']['cnpj']);
    if ($forn) {
        $nome = $forn['fantasia'] !== '' ? $forn['fantasia'] : $forn['razao'];
        $nota['fornecedor']['nome']     = $nome;
        $nota['lancamento']['supplier'] = $nome;
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
            @set_time_limit(300);   // leitura por IA pode demorar (+retentativas)
            $tmp  = $_FILES['foto']['tmp_name'];
            [$mime, $b64] = GeminiClient::imagemParaBase64($tmp, mime_content_type($tmp) ?: 'image/jpeg');
            $lanc = $ia->extrairImagem($b64, $mime, $ctx, trim((string) ($_POST['texto'] ?? '')));
            $origem = 'Foto do cupom (IA)';
        }
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Falha na leitura pela IA: ' . $e->getMessage());
    }

    // CNPJ extraído (se houver) casa o fornecedor por documento e evita duplicata.
    $cnpjForn = $lanc['supplier_cnpj'] ?? '';
    unset($lanc['supplier_cnpj']);

    // Monta a nota no formato da revisão (sem chave — não deduplica texto/foto).
    $_SESSION['financeiro_revisao'] = [
        'chave'             => '',
        'numero'            => '',
        'serie'             => '',
        'emissao'           => $lanc['competence_date'] ?? '',
        'natureza_operacao' => $origem,
        'fornecedor'        => ['nome' => $lanc['supplier'] ?? '', 'cnpj' => $cnpjForn],
        'valor_total'       => $lanc['value'] ?? '', // financeiro_valor_br() mostra sem sinal
        'parcelas'          => [],
        'itens'             => [],
        'avisos'            => ['Extraído por IA — confira todos os campos antes de enviar.'],
        'lancamento'        => $lanc,
    ];
    header('Location: financeiro.php');
    exit;
}

// -------- Fila SEFAZ: revisar uma nota recebida --------
if ($acao === 'pendente_revisar') {
    $ch = isset($_GET['chave']) ? preg_replace('/\D/', '', (string) $_GET['chave']) : '';
    $nota = financeiro_pendentes_listar()[$ch] ?? null;
    if (!$nota) {
        financeiro_redirect('danger', 'Nota recebida não encontrada (pode já ter sido tratada).');
    }
    unset($nota['recebido_em']);
    $_SESSION['financeiro_revisao'] = $nota;
    header('Location: financeiro.php');
    exit;
}

// -------- Fila SEFAZ: descartar uma nota recebida --------
if ($acao === 'pendente_descartar') {
    $ch = isset($_GET['chave']) ? preg_replace('/\D/', '', (string) $_GET['chave']) : '';
    financeiro_pendente_remover($ch);
    financeiro_redirect('success', 'Nota recebida descartada.');
}

// -------- Rodar o puxador do SEFAZ manualmente (botão) --------
if ($acao === 'sefaz_puxar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $espera = financeiro_sefaz_espera_restante();
    if ($espera > 0) {
        $min = (int) ceil($espera / 60);
        financeiro_redirect('warning', "O SEFAZ limita uma consulta por hora. Aguarde mais {$min} minuto(s) — o cron continua buscando sozinho.");
    }
    try {
        $r = financeiro_sefaz_puxar();
        financeiro_redirect('success', "Busca no SEFAZ concluída — {$r['novas']} nota(s) nova(s). (cStat {$r['cStat']}: {$r['xMotivo']})");
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Falha ao buscar no SEFAZ: ' . $e->getMessage());
    }
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

    // Anti-duplicidade: já enviamos igual (fornecedor+valor+data) nos últimos 7 dias?
    if (empty($_POST['forcar'])) {
        $dup = financeiro_enviado_duplicado($lancamento['supplier'], $lancamento['value'], $lancamento['due_date']);
        if ($dup !== null) {
            // Preserva o que o usuário preencheu e volta para a revisão pedindo confirmação.
            $_SESSION['financeiro_revisao']['lancamento'] = $lancamento;
            $_SESSION['financeiro_revisao']['valor_total'] = $lancamento['value'];
            $_SESSION['financeiro_forcar'] = true;
            financeiro_redirect('warning', "Já existe um lançamento igual (mesmo fornecedor, valor e data) enviado em {$dup}. Se for intencional, clique em \"Enviar\" novamente.");
        }
    }

    try {
        $api = financeiro_api();
        // Fornecedor novo? cadastra com CNPJ antes, para casamento futuro por documento.
        $cnpjForn = preg_replace('/\D/', '', (string) ($_POST['supplier_cnpj'] ?? ''));
        if ($cnpjForn !== '' && $lancamento['supplier'] !== '') {
            financeiro_fornecedor_garantir($api, $lancamento['supplier'], $cnpjForn);
        }
        $api->importarLancamentos([$lancamento]);
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Falha ao enviar ao Cardápio Web: ' . $e->getMessage());
    }

    // Registra o envio: serve para a checagem de duplicidade das próximas e
    // alimenta o histórico no fim da página.
    financeiro_enviado_registrar($lancamento['supplier'], $lancamento['value'], $lancamento['due_date'], [
        'descricao' => $lancamento['description'] ?? '',
        'categoria' => $lancamento['category'] ?? '',
        'centro'    => $lancamento['cost_center'] ?? '',
        'conta'     => $lancamento['account'] ?? '',
        'forma'     => $lancamento['payment_method'] ?? '',
        'numero'    => $nota['numero'] ?? '',
    ]);

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
    // Se veio da fila de recebidas do SEFAZ, tira de lá.
    if (!empty($nota['chave'])) {
        financeiro_pendente_remover($nota['chave']);
    }
    unset($_SESSION['financeiro_revisao']);
    financeiro_redirect('success', 'Lançamento enviado com sucesso ao Cardápio Web! Confira em Contas a pagar.');
}

// -------- Dar baixa numa conta a pagar já existente (recorrência) --------
// Em vez de criar um lançamento novo, marca como paga a conta que já estava no
// Cardápio Web. Evita duplicar aluguel/luz/água etc. já lançados por recorrência.
if ($acao === 'pagar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nota = $_SESSION['financeiro_revisao'] ?? null;
    $tid  = (int) ($_POST['transaction_id'] ?? 0);
    $campo = fn(string $k) => trim((string) ($_POST[$k] ?? ''));
    $contaNome = $campo('account');
    $formaNome = $campo('payment_method');
    $dataPg    = $campo('settlement_date') ?: date('Y-m-d');

    if ($tid <= 0) {
        financeiro_redirect('danger', 'Não identifiquei a conta a marcar como paga. Recarregue e tente de novo.');
    }
    if ($contaNome === '' || $formaNome === '') {
        financeiro_redirect('danger', 'Para dar baixa, selecione a Conta e a Forma de pagamento.');
    }

    // Valores em formato BR: positivo ou null.
    $valorOpc = function (string $k): ?float {
        $s = trim((string) ($_POST[$k] ?? ''));
        if ($s === '') { return null; }
        $n = abs((float) str_replace(',', '.', str_replace('.', '', $s)));
        return $n > 0 ? $n : null;
    };
    $novoValor = $valorOpc('original_value');   // valor-base corrigido (ex.: 647)
    $extra = [
        'interest' => $valorOpc('interest'),   // juros
        'fine'     => $valorOpc('fine'),        // multa
        'discount' => $valorOpc('discount'),    // desconto
    ];

    try {
        $api = financeiro_api();
        $contaId = financeiro_id_por_nome($api->listarContas(), $contaNome);
        $formaId = financeiro_id_por_nome($api->listarFormasPagamento(), $formaNome);
        if (!$contaId) { financeiro_redirect('danger', "Conta \"{$contaNome}\" não encontrada no Cardápio Web."); }
        if (!$formaId) { financeiro_redirect('danger', "Forma de pagamento \"{$formaNome}\" não encontrada no Cardápio Web."); }

        // 1) Corrige o valor-base ANTES da baixa, se o usuário informou e mudou.
        //    Se a edição falhar, aborta (não dá baixa com valor errado).
        if ($novoValor !== null) {
            $atual = $api->obterTransacao($tid);
            $valAtual = abs((float) ($atual['original_value'] ?? $atual['value'] ?? 0));
            if (abs($valAtual - $novoValor) > 0.005) {
                $api->atualizarValorTransacao($tid, $atual, $novoValor);
            }
        }

        // 2) Baixa (com juros/multa/desconto).
        $api->pagarTransacao($tid, $contaId, $formaId, $dataPg, $extra);
    } catch (\Throwable $e) {
        financeiro_redirect('danger', 'Falha ao marcar como paga: ' . $e->getMessage());
    }

    // Registra no histórico como baixa (não é lançamento novo).
    financeiro_enviado_registrar(
        (string) ($nota['fornecedor']['nome'] ?? $campo('supplier')),
        (string) ($nota['valor_total'] ?? ''),
        $dataPg,
        ['descricao' => 'Baixa de conta existente (recorrência)', 'conta' => $contaNome, 'forma' => $formaNome, 'baixa' => true]
    );
    // Idempotência: a nota do comprovante fica marcada como processada.
    if (!empty($nota['chave'])) {
        financeiro_marcar_processada($nota['chave'], [
            'numero'     => $nota['numero'] ?? '',
            'fornecedor' => $nota['fornecedor']['nome'] ?? '',
            'valor'      => $nota['valor_total'] ?? '',
        ]);
        financeiro_pendente_remover($nota['chave']);
    }
    unset($_SESSION['financeiro_revisao']);
    financeiro_redirect('success', 'Conta marcada como paga no Cardápio Web — sem duplicar. Confira em Contas a pagar.');
}

header('Location: financeiro.php');
exit;
