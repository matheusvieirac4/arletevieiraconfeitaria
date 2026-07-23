<?php

function cardapios_pasta_pdfs()
{
    $dir = dirname(__DIR__) . '/cardapios';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function cardapios_arquivo_json()
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/cardapios_links.json';
}

function cardapios_listar()
{
    $path = cardapios_arquivo_json();
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $lista = json_decode($raw, true);
    return is_array($lista) ? $lista : [];
}

function cardapios_salvar_lista(array $lista)
{
    $path = cardapios_arquivo_json();
    $json = json_encode(array_values($lista), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function cardapios_normalizar_arquivo($nome)
{
    $nome = trim((string) $nome);
    $nome = str_replace(['\\', '/'], '', $nome);
    if ($nome === '') {
        return null;
    }
    if (!preg_match('/\.pdf$/i', $nome)) {
        $nome .= '.pdf';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*\.pdf$/i', $nome)) {
        return null;
    }
    return strtolower($nome);
}

function cardapios_buscar_por_id($id)
{
    foreach (cardapios_listar() as $item) {
        if (isset($item['id']) && $item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

function cardapios_arquivo_em_uso($arquivo, $ignorarId = null)
{
    foreach (cardapios_listar() as $item) {
        if ($ignorarId !== null && isset($item['id']) && $item['id'] === $ignorarId) {
            continue;
        }
        if (isset($item['arquivo']) && $item['arquivo'] === $arquivo) {
            return true;
        }
    }
    return false;
}

function cardapios_caminho_pdf($arquivo)
{
    return cardapios_pasta_pdfs() . '/' . $arquivo;
}

function cardapios_pdf_existe($arquivo)
{
    $path = cardapios_caminho_pdf($arquivo);
    return is_file($path) && is_readable($path);
}

/**
 * Caminho web da pasta cardapios/ (ex: /arletevieira/cardapios/ no WAMP, /cardapios/ em producao na raiz).
 */
function cardapios_caminho_web()
{
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $projeto = realpath(dirname(__DIR__));
    if ($docRoot && $projeto && strpos($projeto, $docRoot) === 0) {
        $rel = str_replace('\\', '/', substr($projeto, strlen($docRoot)));
        $rel = '/' . trim($rel, '/');
        return ($rel === '/' ? '' : $rel) . '/cardapios/';
    }
    return '/cardapios/';
}

function cardapios_host_publico()
{
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'www.arletevieiraconfeitaria.com.br';
    // Sem www o site na HostGator nao serve /cardapios/; links publicos usam sempre www.
    if (preg_match('/^arletevieiraconfeitaria\.com\.br$/i', $host)) {
        return 'www.arletevieiraconfeitaria.com.br';
    }
    return $host;
}

function cardapios_url_publica($arquivo)
{
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $proto . '://' . cardapios_host_publico() . cardapios_caminho_web() . rawurlencode($arquivo);
}

function cardapios_info_arquivo($arquivo)
{
    $path = cardapios_caminho_pdf($arquivo);
    if (!is_file($path)) {
        return ['existe' => false, 'tamanho' => 0, 'atualizado_em' => null];
    }
    return [
        'existe' => true,
        'tamanho' => filesize($path),
        'atualizado_em' => date('d/m/Y H:i', filemtime($path))
    ];
}
