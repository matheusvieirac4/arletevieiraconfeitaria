<?php
require_once 'model.php';
$id = $_GET['id'] ?? null;
$post = $id ? blog_buscar_post($pdo, $id) : null;
$acao = $id ? 'editar' : 'inserir';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title><?= $id ? 'Editar' : 'Novo' ?> Post</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">
    <h1 class="mb-4"><?= $id ? 'Editar' : 'Novo' ?> Post</h1>
    <form method="post" action="controller.php?acao=<?= $acao ?><?= $id ? '&id=' . $id : '' ?>" enctype="multipart/form-data">
        <div class="mb-3">
            <label class="form-label">Título</label>
            <input type="text" name="titulo" class="form-control" value="<?= htmlspecialchars($post['titulo'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Conteúdo</label>
            <textarea name="conteudo" class="form-control" rows="6" required><?= htmlspecialchars($post['conteudo'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Conteúdo Resumido</label>
            <textarea name="conteudo_resumido" class="form-control" rows="2" required><?= htmlspecialchars($post['conteudo_resumido'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Conteúdo 2</label>
            <textarea name="conteudo_dois" class="form-control" rows="4"><?= htmlspecialchars($post['conteudo_dois'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Conteúdo 3</label>
            <textarea name="conteudo_tres" class="form-control" rows="4"><?= htmlspecialchars($post['conteudo_tres'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Conteúdo 4</label>
            <textarea name="conteudo_quatro" class="form-control" rows="4"><?= htmlspecialchars($post['conteudo_quatro'] ?? '') ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Categoria</label>
            <input type="text" name="categoria" class="form-control" value="<?= htmlspecialchars($post['categoria'] ?? '') ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Imagem (upload)</label>
            <input type="file" name="imagem" class="form-control">
            <?php if (!empty($post['imagem'])): ?>
                <img src="../img/imagens/blog/<?= htmlspecialchars($post['imagem']) ?>" alt="Imagem atual" style="max-width:120px; margin-top:8px;">
                <input type="hidden" name="imagem_atual" value="<?= htmlspecialchars($post['imagem']) ?>">
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary">Salvar</button>
        <a href="index.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>
</body>
</html> 