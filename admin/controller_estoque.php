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
        $novo = estoque_movimentar($pdo, $id, $tipo, $qtd, 'manual', $obs, estoque_responsavel_atual());
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
    $aliases = estoque_aliases_map($pdo);
    $linhas = [];
    foreach ($nota['itens'] as $it) {
        $casa = estoque_casar_item($it['ean'] ?? '', $it['descricao'] ?? '', $cache, $aliases);
        $q    = (float) str_replace(',', '.', (string) ($it['quantidade'] ?? '0'));
        $vtot = (float) str_replace(',', '.', (string) ($it['valor'] ?? '0'));   // vProd (total da linha)
        $linhas[] = [
            'descricao' => $it['descricao'] ?? '',
            'ean'       => preg_replace('/\D/', '', (string) ($it['ean'] ?? '')),
            'quantidade'=> $q,
            'unidade'   => $it['unidade'] ?? '',
            'valor_unit'=> $q > 0 ? $vtot / $q : 0.0,
            'item_id'   => $casa['item_id'],
            'match'     => $casa['match'],
            'embalagem' => estoque_qtde_da_descricao((string) ($it['descricao'] ?? '')) ?? 0,
        ];
    }
    $chave = $nota['chave'] ?? '';
    $jaLancada = $chave !== '' ? estoque_nota_processada($pdo, $chave) : null;
    $_SESSION['estoque_entrada'] = [
        'fornecedor' => $nota['fornecedor']['nome'] ?? '',
        'numero'     => $nota['numero'] ?? '',
        'chave'      => $chave,
        'duplicada'  => $jaLancada,   // data do lançamento anterior, se houver
        'linhas'     => $linhas,
    ];
    header('Location: estoque_entrada.php');
    exit;
}

// ---- Entrada por CUPOM (foto): Gemini extrai os itens ----
if ($acao === 'entrada_cupom' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/model_financeiro.php';      // reusa a chave/IA do Gemini
    require_once __DIR__ . '/lib/gemini_client.php';
    if (!financeiro_ia_configurada()) {
        estoque_redirect('danger', 'A IA (Gemini) não está configurada.', 'estoque_entrada.php');
    }
    if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
        estoque_redirect('danger', 'Selecione uma foto válida do cupom.', 'estoque_entrada.php');
    }
    try {
        @set_time_limit(300);   // leitura por IA pode demorar (+retentativas)
        $tmp  = $_FILES['foto']['tmp_name'];
        [$mime, $b64] = GeminiClient::imagemParaBase64($tmp, mime_content_type($tmp) ?: 'image/jpeg');
        $r    = financeiro_gemini()->extrairItensCupom($b64, $mime);
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'Falha ao ler o cupom: ' . $e->getMessage(), 'estoque_entrada.php');
    }
    $cache = estoque_listar($pdo);
    $aliases = estoque_aliases_map($pdo);
    $linhas = [];
    foreach (($r['itens'] ?? []) as $it) {
        $desc = trim((string) ($it['descricao'] ?? ''));
        if ($desc === '') { continue; }
        $casa = estoque_casar_item('', $desc, $cache, $aliases);   // cupom não traz barcode confiável
        $linhas[] = [
            'descricao'  => $desc,
            'ean'        => '',
            'quantidade' => (float) str_replace(',', '.', (string) ($it['quantidade'] ?? '1')),
            'unidade'    => $it['unidade'] ?? '',
            'valor_unit' => (float) str_replace(',', '.', (string) ($it['valor_unit'] ?? '0')),
            'item_id'    => $casa['item_id'],
            'match'      => $casa['match'],
            'embalagem'  => estoque_qtde_da_descricao($desc) ?? 0,
        ];
    }
    if (!$linhas) {
        estoque_redirect('warning', 'Não consegui identificar itens nesse cupom. Tente uma foto mais nítida.', 'estoque_entrada.php');
    }
    $_SESSION['estoque_entrada'] = ['fornecedor' => '', 'numero' => '', 'origem' => 'cupom', 'linhas' => $linhas];
    header('Location: estoque_entrada.php');
    exit;
}

// ---- Entrada: confirma as linhas revisadas e dá entrada no estoque ----
if ($acao === 'entrada_confirmar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rev = $_SESSION['estoque_entrada'] ?? null;
    if (!$rev) { estoque_redirect('danger', 'Nada para confirmar.', 'estoque_entrada.php'); }
    $origem = ($rev['origem'] ?? 'xml') === 'cupom' ? 'cupom' : 'xml';

    $itemIds = $_POST['item_id'] ?? [];
    $qtds    = $_POST['quantidade'] ?? [];
    $eans    = $_POST['ean'] ?? [];
    $descs   = $_POST['descricao'] ?? [];
    $vunits  = $_POST['valor_unit'] ?? [];
    $mults   = $_POST['mult'] ?? [];   // linhas que devem multiplicar pela embalagem
    $aplicadas = 0;

    try {
        foreach ($itemIds as $i => $sel) {
            $qtd = (float) str_replace(',', '.', (string) ($qtds[$i] ?? '0'));
            if ($qtd <= 0 || $sel === 'ignorar') { continue; }
            $ean   = preg_replace('/\D/', '', (string) ($eans[$i] ?? ''));
            $vunit = (float) str_replace(',', '.', (string) ($vunits[$i] ?? '0'));

            if ($sel === 'novo') {
                $nomeNovo = trim((string) ($descs[$i] ?? 'Novo item'));
                $embNovo  = estoque_qtde_da_descricao($nomeNovo);   // fardo: qtde na descrição
                $id = estoque_criar($pdo, [
                    'nome'          => $nomeNovo,
                    'fornecedor'    => (string) ($rev['fornecedor'] ?? ''),   // puxa o fornecedor da nota
                    'codigo_compra' => $ean,   // veio da nota = código de compra (fardo)
                    'peso_gramas'   => $embNovo !== null ? (string) $embNovo : '',
                    'estoque_atual' => 0,
                ]);
            } else {
                $id = (int) $sel;
                if ($id <= 0) { continue; }
                // O código da nota é o de COMPRA (fardo), não o da unidade — o
                // quiosque escaneia a unidade, que é um código diferente.
                if ($ean !== '') { estoque_definir_codigo_compra($pdo, $id, $ean); }
                // Fardo: multiplica a quantidade pela qtde da embalagem do item;
                // o preço por unidade é o da nota dividido pela embalagem.
                if (isset($mults[$i])) {
                    // A qtde do fardo vem da DESCRIÇÃO DA NOTA (ex.: "COM 20 UN").
                    $emb = (int) (estoque_qtde_da_descricao((string) ($descs[$i] ?? '')) ?? 0);
                    if ($emb > 1) {
                        $qtd = $qtd * $emb;
                        if ($vunit > 0) { $vunit = $vunit / $emb; }
                        estoque_atualizar_embalagem($pdo, $id, $emb);   // aprende o tamanho do fardo
                    }
                }
            }
            estoque_movimentar($pdo, $id, 'entrada', $qtd, $origem, '', estoque_responsavel_atual());
            // Atualiza o preço unitário do item com o da nota/cupom (mantém atualizado).
            if ($vunit > 0) { estoque_atualizar_preco($pdo, $id, $vunit); }
            // Aprende: liga a descrição desta linha ao item escolhido, para casar
            // automaticamente da próxima vez (mesmo fornecedor = mesma descrição).
            estoque_alias_salvar($pdo, (string) ($descs[$i] ?? ''), $id);
            $aplicadas++;
        }
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'Falha ao dar entrada: ' . $e->getMessage(), 'estoque_entrada.php');
    }

    // Registra a nota como processada (dedup: reenviar o mesmo XML vai avisar).
    if (($rev['chave'] ?? '') !== '') {
        estoque_nota_registrar($pdo, (string) $rev['chave'], (string) ($rev['fornecedor'] ?? ''));
    }
    unset($_SESSION['estoque_entrada']);
    estoque_redirect('success', "Entrada concluída: $aplicadas item(ns) atualizados.");
}

// ---- Auditoria: concilia a contagem contra o saldo e registra Δ + responsável ----
if ($acao === 'auditoria' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $contagem = $_POST['contagem'] ?? [];
    // Responsável pela contagem: o admin logado ou um colaborador escolhido.
    $respSel = (string) ($_POST['responsavel_id'] ?? '');
    if ($respSel === 'admin' || $respSel === '') {
        $resp = estoque_responsavel_atual();
    } else {
        $resp = estoque_colaborador_nome($pdo, (int) $respSel) ?: estoque_responsavel_atual();
    }
    $ajustados = 0; $comDiferenca = 0;
    $fmt = fn($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
    try {
        foreach ($contagem as $itemId => $valor) {
            $v = trim((string) $valor);
            if ($v === '') { continue; }   // em branco não altera
            $contado = (float) str_replace(',', '.', str_replace('.', '', $v));
            $item = estoque_buscar($pdo, (int) $itemId);
            if (!$item) { continue; }
            $antes = (float) $item['estoque_atual'];
            $delta = $contado - $antes;
            $ajustados++;
            if (abs($delta) < 0.0005) { continue; }   // bateu: nada a registrar
            $comDiferenca++;
            $sinal = $delta > 0 ? '+' : '−';
            $obs = 'Auditoria: sistema ' . $fmt($antes) . ' → contagem ' . $fmt($contado)
                 . ' (' . $sinal . $fmt(abs($delta)) . ')';
            estoque_movimentar($pdo, (int) $itemId, 'ajuste', $contado, 'auditoria', $obs, $resp);
        }
    } catch (\Throwable $e) {
        estoque_redirect('danger', 'Falha na auditoria: ' . $e->getMessage(), 'estoque_auditoria.php');
    }
    estoque_redirect('success', "Auditoria salva: $ajustados conferido(s), $comDiferenca com diferença registrada.", 'estoque.php');
}

// ---- Descartar a entrada em revisão ----
if ($acao === 'entrada_cancelar') {
    unset($_SESSION['estoque_entrada']);
    estoque_redirect('info', 'Entrada descartada.', 'estoque_entrada.php');
}

// ---- Colaboradores: criar / trocar PIN / excluir ----
if ($acao === 'colab_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int) ($_POST['id'] ?? 0);
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $pin  = (string) ($_POST['pin'] ?? '');
    try {
        if ($id > 0) {
            if ($pin !== '') { estoque_colaborador_atualizar_pin($pdo, $id, $pin); }
            estoque_redirect('success', 'PIN atualizado.', 'estoque_colaboradores.php');
        } else {
            estoque_colaborador_criar($pdo, $nome, $pin);
            estoque_redirect('success', 'Colaborador cadastrado.', 'estoque_colaboradores.php');
        }
    } catch (\Throwable $e) {
        estoque_redirect('danger', $e->getMessage(), 'estoque_colaboradores.php');
    }
}
if ($acao === 'colab_excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) { estoque_colaborador_excluir($pdo, $id); }
    estoque_redirect('success', 'Colaborador removido.', 'estoque_colaboradores.php');
}

// ---- Detectar qtde por embalagem a partir das descrições (lote) ----
if ($acao === 'detectar_embalagem') {
    $n = estoque_detectar_embalagem_todos($pdo);
    estoque_redirect('success', "Qtde por embalagem detectada em $n item(ns) pela descrição.");
}

// ---- Excluir (soft delete) ----
if ($acao === 'excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) { estoque_deletar($pdo, $id); }
    estoque_redirect('success', 'Item removido.');
}

header('Location: estoque.php');
exit;
