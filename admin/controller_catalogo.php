<?php
// Controller do Catálogo: salva/exclui categorias, subcategorias e produtos
// (com faixas de preço e upload de imagem). Segue o padrão de flash+toast.
require_once __DIR__ . '/_auth.php';
require_once 'model_catalogo.php';

$acao = $_GET['acao'] ?? '';

function catalogo_redirect(string $tipo, string $texto, string $para = 'catalogo.php'): void
{
    $_SESSION['catalogo_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: ' . $para);
    exit;
}

/**
 * Sobe uma imagem opcional do $_FILES para img/imagens/catalogo/.
 * Retorna o caminho web (/img/imagens/catalogo/...) ou null se nada enviado.
 * Lança em caso de arquivo inválido.
 */
function catalogo_upload_imagem(string $campo, string $prefixo): ?string
{
    if (empty($_FILES[$campo]) || $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $arq = $_FILES[$campo];
    if ($arq['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException('Falha no upload da imagem.'); }
    if ($arq['size'] > 6 * 1024 * 1024) { throw new RuntimeException('Imagem acima de 6 MB.'); }

    $info = @getimagesize($arq['tmp_name']);
    $tiposOk = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($tiposOk[$info[2]])) {
        throw new RuntimeException('Arquivo não é uma imagem válida (JPG, PNG, GIF ou WEBP).');
    }
    $ext = $tiposOk[$info[2]];
    $dir = __DIR__ . '/../img/imagens/catalogo';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $nome = $prefixo . '_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($arq['tmp_name'], $dir . '/' . $nome)) {
        throw new RuntimeException('Não foi possível salvar a imagem.');
    }
    return '/img/imagens/catalogo/' . $nome;
}

// ---------------------------------------------------------------- Categorias

if ($acao === 'categoria_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    if (trim((string) ($_POST['nome'] ?? '')) === '') {
        catalogo_redirect('danger', 'O nome da categoria é obrigatório.');
    }
    try {
        $dados = $_POST;
        $dados['ativo'] = isset($_POST['ativo']) ? 1 : 0;
        $capa = catalogo_upload_imagem('imagem_capa_arquivo', 'catcapa');
        if ($capa !== null) { $dados['imagem_capa'] = $capa; }
        catalogo_categoria_salvar($pdo, $dados);
        catalogo_redirect('success', $id > 0 ? 'Categoria atualizada.' : 'Categoria criada.');
    } catch (\Throwable $e) {
        catalogo_redirect('danger', 'Falha ao salvar: ' . $e->getMessage());
    }
}

if ($acao === 'categoria_excluir') {
    $id = (int) ($_GET['id'] ?? 0);
    try {
        catalogo_categoria_excluir($pdo, $id);
        catalogo_redirect('success', 'Categoria excluída (com subcategorias e produtos).');
    } catch (\Throwable $e) {
        catalogo_redirect('danger', 'Falha ao excluir: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------- Subcategorias

if ($acao === 'sub_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $catId = (int) ($_POST['categoria_id'] ?? 0);
    $volta = 'catalogo.php?cat=' . $catId;
    if (trim((string) ($_POST['nome'] ?? '')) === '' || $catId <= 0) {
        catalogo_redirect('danger', 'Informe o nome da subcategoria.', $volta);
    }
    try {
        $dados = $_POST;
        $dados['ativo'] = isset($_POST['ativo']) ? 1 : 0;
        catalogo_subcategoria_salvar($pdo, $dados);
        catalogo_redirect('success', 'Subcategoria salva.', $volta);
    } catch (\Throwable $e) {
        catalogo_redirect('danger', 'Falha ao salvar: ' . $e->getMessage(), $volta);
    }
}

if ($acao === 'sub_excluir') {
    $id    = (int) ($_GET['id'] ?? 0);
    $catId = (int) ($_GET['categoria_id'] ?? 0);
    $volta = 'catalogo.php?cat=' . $catId;
    try {
        catalogo_subcategoria_excluir($pdo, $id);
        catalogo_redirect('success', 'Subcategoria excluída.', $volta);
    } catch (\Throwable $e) {
        catalogo_redirect('danger', 'Falha ao excluir: ' . $e->getMessage(), $volta);
    }
}

// ------------------------------------------------------------------ Produtos

if ($acao === 'produto_salvar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = (int) ($_POST['id'] ?? 0);
    $subId = (int) ($_POST['subcategoria_id'] ?? 0);
    $catId = (int) ($_POST['categoria_id'] ?? 0);   // só para o redirect
    $volta = $catId > 0 ? 'catalogo.php?cat=' . $catId : 'catalogo.php';
    if (trim((string) ($_POST['nome'] ?? '')) === '' || $subId <= 0) {
        catalogo_redirect('danger', 'Nome e subcategoria são obrigatórios.', $volta);
    }
    try {
        $dados = $_POST;
        $dados['ativo'] = isset($_POST['ativo']) ? 1 : 0;
        $img = catalogo_upload_imagem('imagem_arquivo', 'prod');
        if ($img !== null) { $dados['imagem'] = $img; }

        // Faixas de preço: arrays paralelos faixa_rotulo[]/faixa_valor[]/faixa_cheio[]/faixa_obs[].
        $precos = [];
        $rots  = $_POST['faixa_rotulo'] ?? [];
        $vals  = $_POST['faixa_valor']  ?? [];
        $cheios= $_POST['faixa_cheio']  ?? [];
        $obses = $_POST['faixa_obs']    ?? [];
        foreach ($vals as $i => $v) {
            $precos[] = [
                'rotulo'      => $rots[$i]   ?? '',
                'valor'       => $v,
                'valor_cheio' => $cheios[$i] ?? '',
                'obs'         => $obses[$i]  ?? '',
            ];
        }
        $dados['precos'] = $precos;

        $novo = catalogo_produto_salvar($pdo, $dados);
        catalogo_redirect('success', $id > 0 ? 'Produto atualizado.' : 'Produto cadastrado.',
            'catalogo_produto.php?id=' . $novo);
    } catch (\Throwable $e) {
        catalogo_redirect('danger', 'Falha ao salvar: ' . $e->getMessage(), $volta);
    }
}

if ($acao === 'produto_excluir') {
    $id    = (int) ($_GET['id'] ?? 0);
    $catId = (int) ($_GET['categoria_id'] ?? 0);
    $volta = $catId > 0 ? 'catalogo.php?cat=' . $catId : 'catalogo.php';
    try {
        catalogo_produto_excluir($pdo, $id);
        catalogo_redirect('success', 'Produto excluído.', $volta);
    } catch (\Throwable $e) {
        catalogo_redirect('danger', 'Falha ao excluir: ' . $e->getMessage(), $volta);
    }
}

catalogo_redirect('danger', 'Ação inválida.');
