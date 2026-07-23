<?php
require_once __DIR__ . '/../includes/banco.php';

function blog_listar_posts($pdo) {
    $stmt = $pdo->query("SELECT * FROM posts ORDER BY criado_em DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function blog_buscar_post($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function blog_inserir_post($pdo, $dados) {
    $sql = "INSERT INTO posts (titulo, conteudo, conteudo_dois, conteudo_tres, conteudo_quatro, conteudo_resumido, categoria, criado_em, imagem) VALUES (:titulo, :conteudo, :conteudo_dois, :conteudo_tres, :conteudo_quatro, :conteudo_resumido, :categoria, NOW(), :imagem)";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':titulo' => $dados['titulo'],
        ':conteudo' => $dados['conteudo'],
        ':conteudo_dois' => $dados['conteudo_dois'],
        ':conteudo_tres' => $dados['conteudo_tres'],
        ':conteudo_quatro' => $dados['conteudo_quatro'],
        ':conteudo_resumido' => $dados['conteudo_resumido'],
        ':categoria' => $dados['categoria'],
        ':imagem' => $dados['imagem'] ?? null
    ]);
}

function blog_atualizar_post($pdo, $id, $dados) {
    $sql = "UPDATE posts SET titulo = :titulo, conteudo = :conteudo, conteudo_dois = :conteudo_dois, conteudo_tres = :conteudo_tres, conteudo_quatro = :conteudo_quatro, conteudo_resumido = :conteudo_resumido, categoria = :categoria, imagem = :imagem WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':titulo' => $dados['titulo'],
        ':conteudo' => $dados['conteudo'],
        ':conteudo_dois' => $dados['conteudo_dois'],
        ':conteudo_tres' => $dados['conteudo_tres'],
        ':conteudo_quatro' => $dados['conteudo_quatro'],
        ':conteudo_resumido' => $dados['conteudo_resumido'],
        ':categoria' => $dados['categoria'],
        ':imagem' => $dados['imagem'] ?? null,
        ':id' => $id
    ]);
}

function blog_deletar_post($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id");
    return $stmt->execute([':id' => $id]);
} 