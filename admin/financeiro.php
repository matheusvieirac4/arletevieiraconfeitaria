<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';

$configurado = financeiro_configurado();

$flash = null;
if (isset($_SESSION['financeiro_flash'])) {
    $flash = $_SESSION['financeiro_flash'];
    unset($_SESSION['financeiro_flash']);
}

// Resultado do teste de conexão (guardado na sessão pelo controller).
$teste = null;
if (isset($_SESSION['financeiro_teste'])) {
    $teste = $_SESSION['financeiro_teste'];
    unset($_SESSION['financeiro_teste']);
}

$page_title = 'Financeiro';
$active = 'financeiro';
$extra_css = '
        .status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .mono { font-family: monospace; font-size: 0.85rem; }';
require __DIR__ . '/_header.php';
?>
        <h1 class="mb-2">Financeiro — Contas a pagar</h1>
        <p class="text-muted mb-4">
            Importe notas fiscais (XML) e lance como contas a pagar no Cardápio Web,
            sem digitar uma por uma. As contas a receber continuam sendo geradas pelo sistema de pedidos.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <!-- Status da integração -->
        <div class="card mb-4" style="max-width: 760px;">
            <div class="card-header fw-semibold">Status da integração</div>
            <div class="card-body">
                <?php if ($configurado): ?>
                    <p class="mb-3"><span class="status-dot bg-success"></span> Configurado.</p>
                    <form method="post" action="controller_financeiro.php?acao=testar" class="d-inline">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Testar conexão com o Cardápio Web</button>
                    </form>
                    <?php if ($teste): ?>
                        <div class="mt-3">
                            <?php if ($teste['ok']): ?>
                                <div class="alert alert-success mb-0">
                                    Conexão OK! O sistema conseguiu autenticar e ler seus cadastros:
                                    <ul class="mb-0 mt-2">
                                        <li>Contas: <strong><?= (int) $teste['contas'] ?></strong></li>
                                        <li>Categorias: <strong><?= (int) $teste['categorias'] ?></strong></li>
                                        <li>Fornecedores: <strong><?= (int) $teste['fornecedores'] ?></strong></li>
                                        <li>Formas de pagamento: <strong><?= (int) $teste['formas'] ?></strong></li>
                                        <li>Centros de custo: <strong><?= (int) $teste['centros'] ?></strong></li>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger mb-0">
                                    Falha: <span class="mono"><?= htmlspecialchars($teste['erro']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="mb-2"><span class="status-dot bg-warning"></span> Ainda não configurado.</p>
                    <p class="mb-1">Para ativar, crie o arquivo <span class="mono">admin/config_financeiro.php</span>
                       a partir do modelo <span class="mono">admin/config_financeiro.exemplo.php</span> e preencha:</p>
                    <ul class="mb-0">
                        <li><span class="mono">company_id</span> — id da empresa (header <span class="mono">companyid</span>)</li>
                        <li><span class="mono">refresh_token</span> — da resposta do login em <span class="mono">/auth/token</span>; <strong>segredo</strong></li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Importação (próximos passos: parser de XML + tela de revisão) -->
        <div class="card" style="max-width: 760px;">
            <div class="card-header fw-semibold">Importar nota fiscal (XML)</div>
            <div class="card-body">
                <?php if ($configurado): ?>
                    <p class="text-muted mb-0">Em construção — o envio de XML e a tela de revisão entram no próximo passo.</p>
                <?php else: ?>
                    <p class="text-muted mb-0">Configure a integração acima para liberar a importação.</p>
                <?php endif; ?>
            </div>
        </div>
<?php require __DIR__ . '/_footer.php'; ?>
