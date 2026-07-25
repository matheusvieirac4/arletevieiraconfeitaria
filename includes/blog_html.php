<?php
// Funções puras de tratamento do HTML do blog, compartilhadas entre o admin
// (gravação) e a página pública (renderização). Sem acesso a banco.

/**
 * Sanitiza o HTML vindo do editor: mantém só uma lista branca de tags/atributos.
 * Remove script/style/eventos on*, javascript:, iframes etc. Sem dependências
 * externas (usa DOMDocument, nativo do PHP).
 */
function blog_sanitizar_html(string $html): string {
    $html = trim($html);
    if ($html === '') { return ''; }

    $tagsOk = [
        'p','br','strong','b','em','i','u','s','h2','h3','h4',
        'ul','ol','li','blockquote','a','img','figure','figcaption',
        'pre','code','span','hr',
    ];
    $attrsOk = [
        'a'   => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'span'=> [],
    ];

    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    // Força UTF-8 e evita o DOM injetar <html>/<body>.
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__raiz__">' . $html . '</div>',
                   LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $raiz = $doc->getElementById('__raiz__');
    if (!$raiz) { return ''; }

    // Percorre todos os nós (cópia da lista, pois vamos remover durante o loop).
    $todos = iterator_to_array($doc->getElementsByTagName('*'));
    foreach ($todos as $el) {
        if ($el->nodeName === 'div' && $el->getAttribute('id') === '__raiz__') { continue; }
        $tag = strtolower($el->nodeName);
        if (!in_array($tag, $tagsOk, true)) {
            // Tag proibida: remove o elemento inteiro (com o conteúdo).
            if ($el->parentNode) { $el->parentNode->removeChild($el); }
            continue;
        }
        // Filtra atributos: só os permitidos para a tag.
        $permitidos = $attrsOk[$tag] ?? [];
        foreach (iterator_to_array($el->attributes ?: []) as $attr) {
            $nome = strtolower($attr->name);
            $val  = trim($attr->value);
            if (!in_array($nome, $permitidos, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }
            // Bloqueia URLs perigosas em href/src.
            if (($nome === 'href' || $nome === 'src')
                && preg_match('/^\s*(javascript|data|vbscript):/i', $val)) {
                $el->removeAttribute($attr->name);
            }
        }
        // Links externos: abre em nova aba com segurança.
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    // Serializa só o conteúdo interno da raiz.
    $out = '';
    foreach (iterator_to_array($raiz->childNodes) as $filho) {
        $out .= $doc->saveHTML($filho);
    }
    return trim($out);
}

/** Gera um resumo em texto puro a partir do HTML (para listagem e meta). */
function blog_resumo_de_html(string $html, int $limite = 180): string {
    // Espaço no fim de cada bloco para não grudar "TítuloTexto".
    $html = preg_replace('/<\/(p|h2|h3|h4|li|blockquote|div|figcaption)>/i', ' ', $html);
    $texto = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if (mb_strlen($texto) <= $limite) { return $texto; }
    return rtrim(mb_substr($texto, 0, $limite - 1)) . '…';
}
