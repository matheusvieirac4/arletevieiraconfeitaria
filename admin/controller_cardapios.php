<?php
session_start();
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}

require_once 'model_cardapios.php';

$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$id = isset($_GET['id']) ? $_GET['id'] : (isset($_POST['id']) ? $_POST['id'] : null);

function cardapios_redirect($tipo, $texto)
{
    $_SESSION['cardapios_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: cardapios.php');
    exit;
}

if ($acao === 'inserir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim((string) (isset($_POST['nome']) ? $_POST['nome'] : ''));
    $arquivo = cardapios_normalizar_arquivo(isset($_POST['arquivo']) ? $_POST['arquivo'] : '');

    if ($nome === '') {
        cardapios_redirect('danger', 'Informe o nome do cardapio.');
    }
    if ($arquivo === null) {
        cardapios_redirect('danger', 'Nome do arquivo invalido. Use apenas letras, numeros, hifen e underscore (ex: av_completo.pdf).');
    }
    if (cardapios_arquivo_em_uso($arquivo)) {
        cardapios_redirect('danger', 'Ja existe um cardapio com esse nome de arquivo.');
    }

    $lista = cardapios_listar();
    $lista[] = [
        'id' => bin2hex(random_bytes(8)),
        'nome' => $nome,
        'arquivo' => $arquivo,
        'criado_em' => date('c')
    ];

    if (!cardapios_salvar_lista($lista)) {
        cardapios_redirect('danger', 'Nao foi possivel salvar a lista de cardapios.');
    }
    cardapios_redirect('success', 'Cardapio cadastrado. Envie o PDF para publicar o link.');
}

if ($acao === 'editar' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $nome = trim((string) (isset($_POST['nome']) ? $_POST['nome'] : ''));
    if ($nome === '') {
        cardapios_redirect('danger', 'Informe o nome do cardapio.');
    }

    $lista = cardapios_listar();
    $encontrou = false;
    foreach ($lista as &$item) {
        if (isset($item['id']) && $item['id'] === $id) {
            $item['nome'] = $nome;
            $encontrou = true;
            break;
        }
    }
    unset($item);

    if (!$encontrou) {
        cardapios_redirect('danger', 'Cardapio nao encontrado.');
    }
    if (!cardapios_salvar_lista($lista)) {
        cardapios_redirect('danger', 'Nao foi possivel salvar as alteracoes.');
    }
    cardapios_redirect('success', 'Nome atualizado.');
}

if ($acao === 'excluir' && $id) {
    $item = cardapios_buscar_por_id($id);
    if (!$item) {
        cardapios_redirect('danger', 'Cardapio nao encontrado.');
    }

    $lista = array_values(array_filter(cardapios_listar(), function ($row) use ($id) {
        return !isset($row['id']) || $row['id'] !== $id;
    }));

    if (!cardapios_salvar_lista($lista)) {
        cardapios_redirect('danger', 'Nao foi possivel excluir o cardapio.');
    }

    if (isset($item['arquivo'])) {
        $path = cardapios_caminho_pdf($item['arquivo']);
        if (is_file($path)) {
            unlink($path);
        }
    }
    cardapios_redirect('success', 'Cardapio excluido.');
}

if ($acao === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $item = cardapios_buscar_por_id($id);
    if (!$item || !isset($item['arquivo'])) {
        cardapios_redirect('danger', 'Cardapio nao encontrado.');
    }

    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        cardapios_redirect('danger', 'Selecione um arquivo PDF valido.');
    }

    $tmp = $_FILES['pdf']['tmp_name'];
    $mime = mime_content_type($tmp);
    $ext = strtolower(pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf' || ($mime !== false && $mime !== 'application/pdf' && $mime !== 'application/octet-stream')) {
        cardapios_redirect('danger', 'Apenas arquivos PDF sao permitidos.');
    }

    $destino = cardapios_caminho_pdf($item['arquivo']);
    if (!move_uploaded_file($tmp, $destino)) {
        cardapios_redirect('danger', 'Falha ao enviar o PDF. Verifique permissoes da pasta cardapios/.');
    }
    cardapios_redirect('success', 'PDF atualizado com sucesso.');
}

header('Location: cardapios.php');
exit;
