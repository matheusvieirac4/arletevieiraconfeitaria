<?php
require_once __DIR__ . '/../includes/banco.php';

/**
 * A coluna conteudo_html (HTML do editor) existe? Fica em cache por execução.
 * Permite o código funcionar ANTES e DEPOIS do ALTER TABLE, sem quebrar nada.
 */
function blog_tem_coluna_html($pdo): bool {
    static $tem = null;
    if ($tem === null) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM posts LIKE 'conteudo_html'");
            $tem = $stmt && $stmt->fetch() !== false;
        } catch (\Throwable $e) {
            $tem = false;
        }
    }
    return $tem;
}

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
    $texto = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    if (mb_strlen($texto) <= $limite) { return $texto; }
    return rtrim(mb_substr($texto, 0, $limite - 1)) . '…';
}

function blog_listar_posts($pdo) {
    $stmt = $pdo->query("SELECT * FROM posts ORDER BY criado_em DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function blog_buscar_post($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Normaliza os dados vindos do formulário para gravação.
 * Se veio HTML do editor (conteudo_html), ele é a fonte da verdade: sanitiza,
 * deriva o resumo se estiver vazio e zera os campos-parágrafo legados.
 */
function blog_preparar_dados(array $dados): array {
    $htmlBruto = trim((string) ($dados['conteudo_html'] ?? ''));
    if ($htmlBruto !== '') {
        $html = blog_sanitizar_html($htmlBruto);
        $dados['conteudo_html'] = $html;
        // conteudo (texto puro) vira espelho do HTML, p/ busca e compatibilidade.
        $dados['conteudo'] = trim(strip_tags($html));
        foreach (['conteudo_dois','conteudo_tres','conteudo_quatro'] as $c) { $dados[$c] = ''; }
        if (trim((string) ($dados['conteudo_resumido'] ?? '')) === '') {
            $dados['conteudo_resumido'] = blog_resumo_de_html($html);
        }
    }
    foreach (['conteudo','conteudo_dois','conteudo_tres','conteudo_quatro','conteudo_resumido','conteudo_html'] as $c) {
        if (!isset($dados[$c])) { $dados[$c] = ''; }
    }
    return $dados;
}

function blog_inserir_post($pdo, $dados) {
    $dados = blog_preparar_dados($dados);
    $temHtml = blog_tem_coluna_html($pdo);
    $cols = ['titulo','conteudo','conteudo_dois','conteudo_tres','conteudo_quatro','conteudo_resumido','categoria','imagem'];
    if ($temHtml) { $cols[] = 'conteudo_html'; }
    $ph = array_map(fn($c) => ":$c", $cols);
    $sql = "INSERT INTO posts (" . implode(',', $cols) . ", criado_em) VALUES (" . implode(',', $ph) . ", NOW())";
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($cols as $c) { $params[":$c"] = $c === 'imagem' ? ($dados['imagem'] ?? null) : ($dados[$c] ?? ''); }
    return $stmt->execute($params);
}

function blog_atualizar_post($pdo, $id, $dados) {
    $dados = blog_preparar_dados($dados);
    $temHtml = blog_tem_coluna_html($pdo);
    $cols = ['titulo','conteudo','conteudo_dois','conteudo_tres','conteudo_quatro','conteudo_resumido','categoria','imagem'];
    if ($temHtml) { $cols[] = 'conteudo_html'; }
    $set = implode(', ', array_map(fn($c) => "$c = :$c", $cols));
    $sql = "UPDATE posts SET $set WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $params = [':id' => $id];
    foreach ($cols as $c) { $params[":$c"] = $c === 'imagem' ? ($dados['imagem'] ?? null) : ($dados[$c] ?? ''); }
    return $stmt->execute($params);
}

function blog_deletar_post($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
    return $stmt->execute([':id' => $id]);
} 