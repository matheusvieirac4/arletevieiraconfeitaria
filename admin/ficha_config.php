<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_ficha.php';

$page_title = 'Precificação';
$active = 'ficha_produtos';

if (!ficha_pronto($pdo)) {
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Precificação</h1>';
    ficha_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$cfg = ficha_config_get($pdo);
$v = fn($n) => rtrim(rtrim(number_format((float) $n, 6, ',', ''), '0'), ',');
$totalIfood  = $cfg['taxa_cartao'] + $cfg['taxa_ifood'] + $cfg['taxa_imposto'] + $cfg['taxa_pgto_app'];
$totalDireta = $cfg['taxa_cartao'] + $cfg['taxa_imposto'] + $cfg['taxa_pgto_app'];

$flash = $_SESSION['ficha_flash'] ?? null;
unset($_SESSION['ficha_flash']);
require __DIR__ . '/_header.php';
?>
        <div class="d-flex align-items-center gap-2 mb-3">
            <a href="ficha_produtos.php" class="btn btn-outline-secondary btn-sm">&larr; Produtos</a>
            <h1 class="mb-0 fs-3">Precificação do negócio</h1>
        </div>
        <p class="text-muted">Estes valores valem para <strong>todos os produtos</strong> e alimentam o cálculo de Preço Direta, Preço iFood e margem de contribuição. Os markups aqui são o <strong>padrão</strong> — cada produto pode sobrescrever.</p>

        <form method="post" action="controller_ficha.php?acao=config_salvar" style="max-width:720px;">
            <div class="card mb-3">
                <div class="card-header"><strong>Custos e taxas</strong></div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Custos fixos + variáveis (overhead) %</label>
                        <div class="input-group">
                            <input type="text" name="overhead_pct" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['overhead_pct']) ?>">
                            <span class="input-group-text">%</span>
                        </div>
                        <div class="form-text">Custo + fixo/var = custo do prato ÷ (1 − este %). Ex.: 50,64%.</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Cartão crédito %</label>
                        <div class="input-group"><input type="text" name="taxa_cartao" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['taxa_cartao']) ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">iFood %</label>
                        <div class="input-group"><input type="text" name="taxa_ifood" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['taxa_ifood']) ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Imposto %</label>
                        <div class="input-group"><input type="text" name="taxa_imposto" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['taxa_imposto']) ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Pgto via app %</label>
                        <div class="input-group"><input type="text" name="taxa_pgto_app" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['taxa_pgto_app']) ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-12 text-muted small">
                        Total de taxas — <strong>iFood:</strong> <?= number_format($totalIfood, 2, ',', '.') ?>% (todas) ·
                        <strong>Direta:</strong> <?= number_format($totalDireta, 2, ',', '.') ?>% (sem a taxa do iFood).
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Markups padrão</strong></div>
                <div class="card-body row g-3">
                    <div class="col-6 col-md-4">
                        <label class="form-label">Markup Direta %</label>
                        <div class="input-group"><input type="text" name="markup_direta_padrao" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['markup_direta_padrao']) ?>"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label">Markup iFood %</label>
                        <div class="input-group"><input type="text" name="markup_ifood_padrao" class="form-control text-end" inputmode="decimal" value="<?= $v($cfg['markup_ifood_padrao']) ?>"><span class="input-group-text">%</span></div>
                    </div>
                </div>
            </div>

            <button class="btn btn-primary">Salvar configuração</button>
        </form>
<?php require __DIR__ . '/_footer.php'; ?>
