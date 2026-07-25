<?php
require_once __DIR__ . '/_auth.php';
require_once 'model.php';
$posts = blog_listar_posts($pdo);

$page_title = 'Posts do Blog';
$active = 'blog';
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-4">Posts do Blog</h1>
        <a href="form.php" class="btn btn-success mb-3">Novo Post</a>
        <table class="table table-bordered table-striped bg-white">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Título</th>
                    <th>Categoria</th>
                    <th>Criado em</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
                <tr>
                    <td><?= htmlspecialchars($post['id']) ?></td>
                    <td><?= htmlspecialchars($post['titulo']) ?></td>
                    <td><?= htmlspecialchars($post['categoria']) ?></td>
                    <td><?= htmlspecialchars($post['criado_em']) ?></td>
                    <td>
                        <a href="form.php?id=<?= (int) $post['id'] ?>" class="btn btn-primary btn-sm">Editar</a>
                        <a href="controller.php?acao=deletar&id=<?= (int) $post['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
<?php require __DIR__ . '/_footer.php'; ?>
