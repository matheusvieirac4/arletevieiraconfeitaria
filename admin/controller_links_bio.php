<?php
session_start();
if (!isset($_SESSION['admin_blog']) || $_SESSION['admin_blog'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/links_bio.php';

$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$slug = isset($_GET['slug']) ? $_GET['slug'] : (isset($_POST['slug']) ? $_POST['slug'] : null);

function links_bio_redirect($tipo, $texto)
{
    $_SESSION['links_bio_flash'] = ['tipo' => $tipo, 'texto' => $texto];
    header('Location: links_bio.php');
    exit;
}

if ($acao === 'inserir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $novoSlug = links_bio_normalizar_slug(isset($_POST['slug_novo']) ? $_POST['slug_novo'] : '');
    $rotulo = trim((string) (isset($_POST['rotulo']) ? $_POST['rotulo'] : ''));
    $url = links_bio_validar_url(isset($_POST['url']) ? $_POST['url'] : '');
    $estilo = (isset($_POST['estilo']) && $_POST['estilo'] === 'primary') ? 'primary' : 'secondary';
    $ordem = (int) (isset($_POST['ordem']) ? $_POST['ordem'] : 99);

    if ($novoSlug === null) {
        links_bio_redirect('danger', 'Identificador invalido. Use letras minusculas, numeros, hifen ou underscore.');
    }
    if ($rotulo === '') {
        links_bio_redirect('danger', 'Informe o texto do botao.');
    }
    if ($url === null) {
        links_bio_redirect('danger', 'URL invalida. Use um endereco completo com http ou https.');
    }
    if (links_bio_slug_em_uso($novoSlug)) {
        links_bio_redirect('danger', 'Ja existe um link com esse identificador.');
    }

    $lista = links_bio_listar();
    $lista[] = [
        'slug' => $novoSlug,
        'rotulo' => $rotulo,
        'url' => $url,
        'estilo' => $estilo,
        'ordem' => $ordem,
        'ativo' => true
    ];

    if (!links_bio_salvar_lista($lista)) {
        links_bio_redirect('danger', 'Nao foi possivel salvar o link.');
    }
    links_bio_redirect('success', 'Link adicionado.');
}

if ($acao === 'editar' && $_SERVER['REQUEST_METHOD'] === 'POST' && $slug) {
    $rotulo = trim((string) (isset($_POST['rotulo']) ? $_POST['rotulo'] : ''));
    $url = links_bio_validar_url(isset($_POST['url']) ? $_POST['url'] : '');
    $estilo = (isset($_POST['estilo']) && $_POST['estilo'] === 'primary') ? 'primary' : 'secondary';
    $ordem = (int) (isset($_POST['ordem']) ? $_POST['ordem'] : 0);
    $ativo = isset($_POST['ativo']);

    if ($rotulo === '') {
        links_bio_redirect('danger', 'Informe o texto do botao.');
    }
    if ($url === null) {
        links_bio_redirect('danger', 'URL invalida.');
    }

    $lista = links_bio_listar();
    $encontrou = false;
    foreach ($lista as &$item) {
        if (isset($item['slug']) && $item['slug'] === $slug) {
            $item['rotulo'] = $rotulo;
            $item['url'] = $url;
            $item['estilo'] = $estilo;
            $item['ordem'] = $ordem;
            $item['ativo'] = $ativo;
            $encontrou = true;
            break;
        }
    }
    unset($item);

    if (!$encontrou) {
        links_bio_redirect('danger', 'Link nao encontrado.');
    }
    if (!links_bio_salvar_lista($lista)) {
        links_bio_redirect('danger', 'Nao foi possivel salvar as alteracoes.');
    }
    links_bio_redirect('success', 'Link atualizado.');
}

if ($acao === 'excluir' && $slug) {
    $lista = array_values(array_filter(links_bio_listar(), function ($row) use ($slug) {
        return !isset($row['slug']) || $row['slug'] !== $slug;
    }));

    if (count($lista) === links_bio_listar()) {
        links_bio_redirect('danger', 'Link nao encontrado.');
    }
    if (!links_bio_salvar_lista($lista)) {
        links_bio_redirect('danger', 'Nao foi possivel excluir o link.');
    }
    links_bio_redirect('success', 'Link excluido.');
}

header('Location: links_bio.php');
exit;
