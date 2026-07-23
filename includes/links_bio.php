<?php

function links_bio_arquivo_json()
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir . '/links_bio.json';
}

function links_bio_padrao()
{
    return [
        [
            'slug' => 'whatsapp',
            'rotulo' => 'Fazer pedido no WhatsApp',
            'url' => 'https://wa.me/554820133000?text=Ola! Vim pelo link da bio e gostaria de fazer um pedido.',
            'estilo' => 'primary',
            'ordem' => 1,
            'ativo' => true
        ],
        [
            'slug' => 'cardapio',
            'rotulo' => 'Nosso cardapio',
            'url' => 'https://www.canva.com/design/DAF7SGBajfw/ipM9CTEle_Py8uU2EF1w1w/view?utm_content=DAF7SGBajfw',
            'estilo' => 'secondary',
            'ordem' => 2,
            'ativo' => true
        ],
        [
            'slug' => 'ifood',
            'rotulo' => 'Nosso iFood',
            'url' => 'https://www.ifood.com.br/delivery/sao-jose-sc/arlete-vieira-confeitaria-rocado/539f6976-7007-4dae-88c3-950df000ea1f',
            'estilo' => 'secondary',
            'ordem' => 3,
            'ativo' => true
        ],
        [
            'slug' => 'corporativos',
            'rotulo' => 'Presentes corporativos',
            'url' => 'https://www.arletevieiraconfeitaria.com.br/corporativos.php',
            'estilo' => 'secondary',
            'ordem' => 4,
            'ativo' => true
        ],
        [
            'slug' => 'site',
            'rotulo' => 'Nosso Site',
            'url' => 'https://www.arletevieiraconfeitaria.com.br/',
            'estilo' => 'secondary',
            'ordem' => 5,
            'ativo' => true
        ],
        [
            'slug' => 'mapa',
            'rotulo' => 'Como Chegar',
            'url' => 'https://www.google.com/maps/dir//Arlete+Vieira+Confeitaria,+R.+Jos%C3%A9+Francisco+Cunha,+246+-+Ro%C3%A7ado,+S%C3%A3o+Jos%C3%A9+-+SC,+88108-120/@-27.5956923,-48.6245152,486m/data=!3m2!1e3!4b1!4m9!4m8!1m0!1m5!1m1!1s0x952749e11abb0997:0x516350bda9bafae6!2m2!1d-48.6209571!2d-27.5964081!3e0?entry=ttu&g_ep=EgoyMDI2MDUwMi4wIKXMDSoASAFQAw%3D%3D',
            'estilo' => 'secondary',
            'ordem' => 6,
            'ativo' => true
        ]
    ];
}

function links_bio_listar()
{
    $path = links_bio_arquivo_json();
    if (!is_file($path)) {
        $padrao = links_bio_padrao();
        links_bio_salvar_lista($padrao);
        return $padrao;
    }
    $raw = file_get_contents($path);
    $lista = json_decode($raw, true);
    if (!is_array($lista) || count($lista) === 0) {
        $padrao = links_bio_padrao();
        links_bio_salvar_lista($padrao);
        return $padrao;
    }
    return $lista;
}

function links_bio_salvar_lista(array $lista)
{
    $path = links_bio_arquivo_json();
    $json = json_encode(array_values($lista), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

function links_bio_normalizar_slug($slug)
{
    $slug = strtolower(trim((string) $slug));
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
        return null;
    }
    return $slug;
}

function links_bio_validar_url($url)
{
    $url = trim((string) $url);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    return $url;
}

function links_bio_buscar_por_slug($slug)
{
    foreach (links_bio_listar() as $item) {
        if (isset($item['slug']) && $item['slug'] === $slug) {
            return $item;
        }
    }
    return null;
}

function links_bio_slug_em_uso($slug, $ignorarSlug = null)
{
    foreach (links_bio_listar() as $item) {
        if ($ignorarSlug !== null && isset($item['slug']) && $item['slug'] === $ignorarSlug) {
            continue;
        }
        if (isset($item['slug']) && $item['slug'] === $slug) {
            return true;
        }
    }
    return false;
}

function links_bio_ativos_ordenados()
{
    $lista = array_filter(links_bio_listar(), function ($item) {
        return !empty($item['ativo']);
    });
    usort($lista, function ($a, $b) {
        $oa = isset($a['ordem']) ? (int) $a['ordem'] : 0;
        $ob = isset($b['ordem']) ? (int) $b['ordem'] : 0;
        if ($oa === $ob) {
            return strcmp(isset($a['rotulo']) ? $a['rotulo'] : '', isset($b['rotulo']) ? $b['rotulo'] : '');
        }
        return $oa < $ob ? -1 : 1;
    });
    return array_values($lista);
}

function links_bio_mapa_urls()
{
    $mapa = [];
    foreach (links_bio_listar() as $item) {
        if (!empty($item['ativo']) && isset($item['slug'], $item['url'])) {
            $mapa[$item['slug']] = $item['url'];
        }
    }
    return $mapa;
}

function links_bio_mapa_rotulos()
{
    $mapa = [];
    foreach (links_bio_listar() as $item) {
        if (isset($item['slug'], $item['rotulo'])) {
            $mapa[$item['slug']] = $item['rotulo'];
        }
    }
    return $mapa;
}
