<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';

if (!estoque_pronto($pdo)) {
    $page_title = 'Colaboradores'; $active = 'estoque';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Colaboradores</h1>';
    estoque_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$colabs = estoque_colaboradores_listar($pdo);
$flash = $_SESSION['estoque_flash'] ?? null;
unset($_SESSION['estoque_flash']);

$kToken = estoque_kiosk_token();
$kBase  = (!empty($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$kUrl   = $kToken !== '' ? $kBase . '/admin/estoque_kiosk.php?token=' . $kToken : '';

$page_title = 'Colaboradores';
$active = 'estoque';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Colaboradores do quiosque</h1>
            <a href="estoque.php" class="btn btn-outline-secondary btn-sm">&larr; Estoque</a>
        </div>

        <div class="card mb-4" style="max-width:820px;">
            <div class="card-header fw-semibold">Acesso do quiosque (sem login)</div>
            <div class="card-body">
                <p class="text-muted">Abra este link no celular da porta <strong>uma vez</strong> — o aparelho fica autorizado para sempre e o quiosque abre sem login. Quem está logado como admin também pode abrir o quiosque normalmente. A baixa continua exigindo nome + PIN.</p>
                <?php if ($kUrl): ?>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" id="kiosk-url" value="<?= htmlspecialchars($kUrl) ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('kiosk-url').value); showToast('Link copiado.', 'success');">Copiar</button>
                    </div>
                    <a href="controller_estoque.php?acao=kiosk_token_gerar" class="btn btn-outline-danger btn-sm js-confirm"
                       data-msg="Gerar um link novo? O link atual e os aparelhos já autorizados deixam de funcionar.">Gerar novo link (revoga o atual)</a>
                <?php else: ?>
                    <a href="controller_estoque.php?acao=kiosk_token_gerar" class="btn btn-primary">Gerar link do quiosque</a>
                <?php endif; ?>
            </div>
        </div>

        <p class="text-muted">Quem pode dar baixa no quiosque. Cada um escolhe o nome e digita o <strong>PIN de 4 dígitos</strong> antes de retirar.</p>


        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header fw-semibold">Novo colaborador</div>
                    <div class="card-body">
                        <form method="post" action="controller_estoque.php?acao=colab_salvar">
                            <div class="mb-3">
                                <label class="form-label">Nome</label>
                                <input type="text" name="nome" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">PIN (4 dígitos)</label>
                                <input type="text" name="pin" class="form-control" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="ex.: 1234" required>
                            </div>
                            <button class="btn btn-primary">Cadastrar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 bg-white">
                            <thead><tr><th>Nome</th><th>Trocar PIN</th><th class="text-end">Ações</th></tr></thead>
                            <tbody>
                            <?php if (!$colabs): ?>
                                <tr><td colspan="3" class="text-muted text-center py-4">Nenhum colaborador ainda.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($colabs as $c): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($c['nome']) ?></td>
                                    <td>
                                        <form method="post" action="controller_estoque.php?acao=colab_salvar" class="d-flex gap-2" style="max-width:230px;">
                                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                            <input type="text" name="pin" class="form-control form-control-sm" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="novo PIN" required>
                                            <button class="btn btn-outline-primary btn-sm">OK</button>
                                        </form>
                                    </td>
                                    <td class="text-end">
                                        <a href="controller_estoque.php?acao=colab_excluir&id=<?= (int) $c['id'] ?>" class="btn btn-outline-danger btn-sm js-confirm"
                                           data-msg="Remover <?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>?">Remover</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
