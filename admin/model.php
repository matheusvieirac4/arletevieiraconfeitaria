<?php
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/../includes/blog_html.php';   // blog_sanitizar_html, blog_resumo_de_html

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