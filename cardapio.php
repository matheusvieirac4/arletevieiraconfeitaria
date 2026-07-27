<?php
// /cardapio.php          -> landing "folder": capa + apresentação + visão + vitrine + contato
// /cardapio.php?cat=slug -> renderiza a categoria (router genérico, também em modo catálogo)
$cat_slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['cat'] ?? '')));

if ($cat_slug !== '') {
    $catalogo_modo = true;   // via router = modo catálogo (folder, header enxuto)
    require __DIR__ . '/includes/categoria_pagina.php';
    return;
}

// ---------------------------------------------------------------- Landing
$catalogo_modo = true;   // sem menu do site: navega só entre landing e categorias
$page_title = 'Cardápio | Arlete Vieira Confeitaria & Doceria';
$page_description = 'O cardápio da Arlete Vieira Confeitaria & Doceria — 27 anos adoçando momentos em São José/SC. Docinhos, salgadinhos, bolos, cupcakes, bem-casados e combos.';
$page_keywords = 'cardápio, confeitaria, docinhos, salgadinhos, bolos, cupcakes, combos, São José, SC, Arlete Vieira';
$page_image = 'https://arletevieiraconfeitaria.com.br/img/imagens/background-4.png';

include __DIR__ . '/includes/top.php';
require_once __DIR__ . '/includes/catalogo_render.php';

$categorias = catalogo_pronto($pdo) ? catalogo_categorias_listar($pdo, false) : [];

/** Capa da categoria: imagem_capa -> 1ª foto de produto -> placeholder. */
function catalogo_capa_categoria(PDO $pdo, array $cat): string
{
    if (!empty($cat['imagem_capa'])) { return ltrim($cat['imagem_capa'], '/'); }
    foreach (catalogo_produtos_listar($pdo, ['categoria_id' => (int) $cat['id']]) as $p) {
        if (!empty($p['imagem'])) { return ltrim($p['imagem'], '/'); }
    }
    return 'img/imagens/doces/sem-imagem.jpg';
}
?>
<style>
:root{
    --paper:#f8f0e2; --paper2:#f2e8d6; --ink:#3f342c; --ink-soft:#7a6c5f;
    --gold:#b0894e; --gold-line:#c9ad76; --maroon:#8f1d2e;
}
.folder-bg{ background:#e6d9c1; padding:34px 14px 0; }
.folder{ max-width:1060px; margin:0 auto; background:var(--paper);
    background-image:radial-gradient(rgba(176,137,78,.05) 1px, transparent 1px); background-size:22px 22px;
    position:relative; box-shadow:0 14px 60px rgba(70,45,20,.26); }
.folder::before{ content:""; position:absolute; top:16px; left:16px; right:16px; bottom:16px;
    border:1px solid #d8bf90; pointer-events:none; z-index:2; }
.folder::after{ content:""; position:absolute; top:20px; left:20px; right:20px; bottom:20px;
    border:1px solid rgba(176,137,78,.35); pointer-events:none; z-index:2; }

.pg{ padding:4.6rem 9%; position:relative; }
.fl-orn{ display:flex; align-items:center; justify-content:center; gap:16px; color:var(--gold); margin:0 auto 1.4rem; }
.fl-orn::before,.fl-orn::after{ content:""; height:1px; width:70px; background:linear-gradient(90deg,transparent,var(--gold-line)); }
.fl-orn::after{ background:linear-gradient(90deg,var(--gold-line),transparent); }
.fl-orn span{ font-size:1.1rem; }
.fl-eyebrow{ text-align:center; color:var(--gold); letter-spacing:.34em; text-transform:uppercase; font-size:.74rem; font-weight:600; margin-bottom:.8rem; }
.fl-title{ font-family:'Lora',serif; font-weight:700; color:var(--ink); text-align:center; font-size:clamp(1.9rem,4vw,2.7rem); line-height:1.14; margin:0 0 1rem; }
.fl-lead{ color:var(--ink-soft); font-size:1.05rem; line-height:1.9; }

/* Capa */
.pg-cover{ text-align:center; padding-top:5rem; padding-bottom:4rem; background:var(--paper2); }
.pg-cover .kicker{ color:var(--gold); letter-spacing:.42em; text-transform:uppercase; font-size:.78rem; margin-bottom:1.6rem; }
.pg-cover .logo{ width:230px; max-width:64%; margin:0 auto 1.4rem; display:block; }
.pg-cover .word{ font-family:'Lora',serif; font-weight:700; color:var(--maroon); font-size:clamp(2.6rem,7vw,4.4rem); line-height:1; margin:.4rem 0 .6rem; letter-spacing:.01em; }
.pg-cover .sub{ color:var(--ink-soft); font-size:1rem; letter-spacing:.05em; }
.pg-cover .scroll{ display:inline-block; margin-top:2.4rem; color:var(--gold); font-size:1.3rem; text-decoration:none; animation:flb 2s infinite; }
@keyframes flb{0%,100%{transform:translateY(0)}50%{transform:translateY(9px)}}

/* História */
.pg-story .row-imgs img{ width:100%; border:6px solid #fff; box-shadow:0 10px 30px rgba(70,45,20,.18); }
.fl-lead.first::first-letter{ font-family:'Lora',serif; font-size:3.3em; float:left; line-height:.72; color:var(--maroon); margin:.06em .12em 0 0; font-weight:700; }

/* Valores */
.pg-values{ background:var(--paper2); }
.fl-value{ text-align:center; padding:1rem .6rem; }
.fl-value .ic{ width:62px; height:62px; border-radius:50%; border:1px solid var(--gold-line); color:var(--gold);
    display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; font-size:1.35rem; }
.fl-value h3{ font-family:'Lora',serif; color:var(--ink); font-size:1.18rem; margin-bottom:.45rem; }
.fl-value p{ color:var(--ink-soft); font-size:.95rem; line-height:1.65; margin:0; }

/* Frase */
.pg-quote{ text-align:center; }
.pg-quote blockquote{ font-family:'Lora',serif; font-style:italic; color:var(--ink); font-size:clamp(1.3rem,3vw,1.75rem);
    line-height:1.65; max-width:760px; margin:0 auto 1.4rem; }
.pg-quote .sig img{ height:66px; }
.pg-quote .who{ display:block; color:var(--gold); letter-spacing:.24em; text-transform:uppercase; font-size:.76rem; margin-top:.5rem; }

/* Vitrine */
.fl-tiles .tile{ display:block; text-decoration:none; }
.fl-tiles .frame{ position:relative; overflow:hidden; border:5px solid #fff; box-shadow:0 8px 22px rgba(70,45,20,.18); }
.fl-tiles .frame img{ width:100%; aspect-ratio:1/1; object-fit:cover; display:block; transition:transform .6s ease; }
.fl-tiles .tile:hover .frame img{ transform:scale(1.07); }
.fl-tiles .cap{ text-align:center; font-family:'Lora',serif; color:var(--ink); font-size:1.02rem; letter-spacing:.03em;
    text-transform:uppercase; margin-top:.7rem; }

/* CTA */
.pg-cta{ text-align:center; background:var(--maroon);
    background-image:linear-gradient(rgba(143,29,46,.94),rgba(112,18,34,.96)); color:#fff; }
.pg-cta .fl-eyebrow{ color:#e9c4cb; }
.pg-cta h2{ font-family:'Lora',serif; color:#fff !important; font-size:clamp(1.9rem,4vw,2.7rem); margin-bottom:.8rem; }
.pg-cta p{ color:#f7e7ea !important; font-size:1.05rem; margin-bottom:1.8rem; }
.pg-cta .fl-orn{ color:#e9c4cb; }
.pg-cta .fl-orn::before{ background:linear-gradient(90deg,transparent,rgba(233,196,203,.7)); }
.pg-cta .fl-orn::after{ background:linear-gradient(90deg,rgba(233,196,203,.7),transparent); }
.pg-cta .wa{ display:inline-flex; align-items:center; gap:.6rem; background:#fff; color:var(--maroon); font-weight:700;
    border-radius:50px; padding:.95rem 2.3rem; font-size:1.06rem; text-decoration:none; box-shadow:0 10px 26px rgba(0,0,0,.22); transition:transform .12s; }
.pg-cta .wa:hover{ transform:translateY(-2px); color:var(--maroon); }

/* Informações */
.pg-info{ background:var(--paper2); }
.fl-info{ text-align:center; padding:1rem; }
.fl-info .ic{ color:var(--gold); font-size:1.3rem; margin-bottom:.55rem; }
.fl-info h4{ color:var(--ink); font-size:.78rem; letter-spacing:.18em; text-transform:uppercase; margin-bottom:.5rem; }
.fl-info p{ color:var(--ink-soft); font-size:.95rem; line-height:1.6; margin:0; }
.fl-info a{ color:var(--maroon); text-decoration:none; }
.fl-foot{ text-align:center; color:var(--ink-soft); font-size:.8rem; letter-spacing:.05em; padding:1.6rem 0 .4rem; }

@media(max-width:600px){ .pg{ padding:3.4rem 8%; } .folder-bg{ padding:16px 8px 0; } }
</style>

<div class="folder-bg">
  <div class="folder">

    <!-- CAPA -->
    <section class="pg pg-cover">
        <div class="kicker">Confeitaria &amp; Doceria</div>
        <img src="img/logo.png" alt="Arlete Vieira Confeitaria &amp; Doceria" class="logo">
        <div class="fl-orn"><span>&#10086;</span></div>
        <div class="word">Cardápio</div>
        <div class="sub">Há 27 anos adoçando momentos &middot; São José/SC</div>
        <a href="#historia" class="scroll" aria-label="Ver mais"><i class="fas fa-chevron-down"></i></a>
    </section>

    <!-- HISTÓRIA (breve) -->
    <section id="historia" class="pg pg-story text-center">
        <div class="fl-eyebrow">Nossa história</div>
        <div class="fl-orn"><span>&#10086;</span></div>
        <p class="fl-lead mx-auto mb-0" style="max-width:720px;">Há 27 anos, o que começou com bolos de aniversário feitos em casa virou a confeitaria que adoça as festas de São José e região — tudo artesanal, com o mesmo carinho do primeiro dia.</p>
    </section>

    <!-- VALORES -->
    <section class="pg pg-values">
        <div class="fl-eyebrow">O que nos move</div>
        <h2 class="fl-title">Excelência em cada detalhe</h2>
        <div class="fl-orn"><span>&#10086;</span></div>
        <div class="row g-4 mt-2">
            <div class="col-md-4"><div class="fl-value">
                <div class="ic"><i class="fas fa-hand-holding-heart"></i></div>
                <h3>Artesanal de verdade</h3>
                <p>Produção em pequenos lotes, feita à mão, sem atalhos — do recheio à finalização.</p>
            </div></div>
            <div class="col-md-4"><div class="fl-value">
                <div class="ic"><i class="fas fa-seedling"></i></div>
                <h3>Ingredientes selecionados</h3>
                <p>Escolhemos com carinho o que entra em cada receita, para um sabor que se lembra.</p>
            </div></div>
            <div class="col-md-4"><div class="fl-value">
                <div class="ic"><i class="fas fa-birthday-cake"></i></div>
                <h3>Feito pro seu momento</h3>
                <p>Do bento individual à festa completa, personalizamos no tema da sua ocasião.</p>
            </div></div>
        </div>
    </section>

    <!-- VITRINE -->
    <section id="vitrine" class="pg">
        <div class="fl-eyebrow">Escolha e peça pelo WhatsApp</div>
        <h2 class="fl-title">Nosso Cardápio</h2>
        <div class="fl-orn"><span>&#10086;</span></div>
        <?php if (!$categorias): ?>
            <p class="text-center fl-lead py-4">Cardápio em atualização. Fale conosco no WhatsApp!</p>
        <?php else: ?>
        <div class="row g-4 fl-tiles mt-2">
            <?php foreach ($categorias as $c):
                $url = catalogo_folder_do_slug($c['slug']);
                $capa = catalogo_capa_categoria($pdo, $c); ?>
                <div class="col-6 col-md-4">
                    <a class="tile" href="<?= htmlspecialchars($url) ?>">
                        <div class="frame"><img src="<?= htmlspecialchars($capa) ?>" alt="<?= htmlspecialchars($c['nome']) ?>"></div>
                        <div class="cap"><?= htmlspecialchars($c['nome']) ?></div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- FRASE -->
    <section class="pg pg-quote">
        <div class="fl-orn"><span>&#10086;</span></div>
        <blockquote>"Cada doce que fazemos é uma expressão do nosso amor e dedicação. Obrigada por nos permitir adoçar seus momentos especiais!"</blockquote>
        <div class="sig"><img src="img/signature.png" alt="Assinatura de Arlete Vieira"></div>
        <span class="who">Arlete Vieira</span>
    </section>

    <!-- CTA -->
    <section class="pg pg-cta">
        <div class="fl-eyebrow">Vamos combinar?</div>
        <h2>Vamos adoçar a sua festa</h2>
        <div class="fl-orn"><span>&#10086;</span></div>
        <p>Faça sua encomenda com a gente — respondemos rapidinho.</p>
        <a href="<?= htmlspecialchars($whatsapp_pedido_url) ?>" class="wa"
           onclick="return gtag_report_conversion('<?= htmlspecialchars($whatsapp_pedido_url, ENT_QUOTES) ?>');">
           <i class="fab fa-whatsapp"></i> Fazer meu pedido
        </a>
    </section>

    <!-- INFORMAÇÕES -->
    <section class="pg pg-info">
        <div class="row">
            <div class="col-md-3 fl-info">
                <div class="ic"><i class="fas fa-map-marker-alt"></i></div>
                <h4>Onde estamos</h4>
                <p>Rua José Francisco Cunha, 246<br>Roçado — São José/SC</p>
            </div>
            <div class="col-md-3 fl-info">
                <div class="ic"><i class="fas fa-clock"></i></div>
                <h4>Atendimento</h4>
                <p>Segunda a Sábado<br>9h às 17h30</p>
            </div>
            <div class="col-md-3 fl-info">
                <div class="ic"><i class="fas fa-calendar-check"></i></div>
                <h4>Encomendas</h4>
                <p>Peça com antecedência.<br>Docinhos a partir de 25 un &middot; salgados a partir de 60 un</p>
            </div>
            <div class="col-md-3 fl-info">
                <div class="ic"><i class="fab fa-whatsapp"></i></div>
                <h4>Contato</h4>
                <p><a href="tel:+554820133000">(48) 2013-3000</a><br><a href="https://www.instagram.com/arletevieiraconfeitaria" target="_blank">@arletevieiraconfeitaria</a></p>
            </div>
        </div>
        <div class="fl-foot">Arlete Vieira Confeitaria &amp; Doceria &middot; <?= date('Y') ?></div>
    </section>

  </div>
</div>
        </div><!-- /.main -->

<?php include __DIR__ . '/includes/footer.php'; ?>
