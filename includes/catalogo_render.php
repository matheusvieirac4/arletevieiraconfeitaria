<?php
// Renderizador público de uma categoria do catálogo (tema Porto). Reaproveita
// a grade isotope + filtros das páginas atuais e adiciona o modal do produto
// com foto, descrição, tabela de preços e CTA de WhatsApp.
require_once __DIR__ . '/../admin/model_catalogo.php';

/** Número do WhatsApp para os CTAs de pedido. */
function catalogo_wa_numero(): string { return '554820133000'; }

/**
 * Monta o payload JS de um produto para o modal.
 */
function catalogo_produto_payload(array $p): array
{
    $precos = [];
    foreach ($p['precos'] as $f) {
        $precos[] = [
            'rotulo' => $f['rotulo'] ?? '',
            'valor'  => catalogo_moeda($f['valor']),
            'cheio'  => !empty($f['valor_cheio']) ? catalogo_moeda($f['valor_cheio']) : '',
            'obs'    => $f['obs'] ?? '',
        ];
    }
    $ap = catalogo_preco_a_partir($p);
    return [
        'nome'      => $p['nome'],
        'imagem'    => ltrim($p['imagem'] ?: 'img/imagens/doces/sem-imagem.jpg', '/'),
        'descricao' => $p['descricao'] ?? '',
        'instrucao' => $p['instrucao_pedido'] ?? '',
        'rotulo'    => $p['preco_rotulo'] ?? '',
        'apartir'   => $ap !== null ? catalogo_moeda($ap) : '',
        'precos'    => $precos,
    ];
}

/**
 * Renderiza o corpo público da categoria pelo slug. Retorna false se não existe.
 * $modo = true  -> modo catálogo/folder (creme, header enxuto), via cardapio.php?cat=
 * $modo = false -> página do SITE (hero do tema Porto, header/menu do site) — padrão.
 */
function catalogo_render_categoria(PDO $pdo, string $slug, bool $modo = false, string $fallbackBg = 'img/imagens/background-4.png')
{
    // Segurança no deploy: se as tabelas ainda não existem (setup não rodou),
    // degrada suave em vez de estourar 500.
    if (!catalogo_pronto($pdo)) { return false; }
    $cat = catalogo_categoria_por_slug($pdo, $slug);
    if (!$cat || !$cat['ativo']) { return false; }

    $subsTodas = catalogo_subcategorias_listar($pdo, (int) $cat['id'], false); // só ativas
    $produtos  = catalogo_produtos_listar($pdo, ['categoria_id' => (int) $cat['id']]);

    // Só mantém subcategorias que têm produto.
    $temProduto = [];
    foreach ($produtos as $p) { $temProduto[(int) $p['subcategoria_id']] = true; }
    $subs = array_values(array_filter($subsTodas, fn($s) => !empty($temProduto[(int) $s['id']])));

    // Produtos por subcategoria + separação sabores × decorações (layout especial).
    $porSub = [];
    foreach ($produtos as $p) { $porSub[(int) $p['subcategoria_id']][] = $p; }
    $subsDeco = array_values(array_filter($subs, fn($s) => $s['slug'] === 'decoracoes'));
    $subsSab  = array_values(array_filter($subs, fn($s) => $s['slug'] !== 'decoracoes'));
    $layoutBolos = $subsDeco && $subsSab;   // ex.: Bolos = sabores (lista) + decorações (fotos)

    // Imagem de capa como fundo do hero.
    $bg = !empty($cat['imagem_capa']) ? ltrim($cat['imagem_capa'], '/') : $fallbackBg;
    $nomeUp = mb_strtoupper($cat['nome']);
    $ancora = 'cat-' . $cat['slug'];
    $wa = catalogo_wa_numero();

    // Mapa de payloads para o modal.
    $payloads = [];
    foreach ($produtos as $p) { $payloads[(int) $p['id']] = catalogo_produto_payload($p); }
    ?>
    <?php if ($modo): // modo catálogo (folder, creme) ?>
    <div class="cat-page">
    <section class="cat-hero">
        <div class="container">
            <a href="cardapio.php" class="cat-hero-back">&larr; Cardápio</a>
            <div class="cat-orn"><span>&#10086;</span></div>
            <h1 class="cat-hero-title"><?= htmlspecialchars($cat['nome']) ?></h1>
            <?php if (!empty($cat['descricao'])): ?><p class="cat-hero-sub"><?= htmlspecialchars($cat['descricao']) ?></p><?php endif; ?>
        </div>
    </section>
    <?php else: // página do site (tema Porto) ?>
    <section class="section section-with-shape-divider section-height-3 overlay overlay-show border-0 m-0" data-plugin-parallax data-plugin-options="{'speed': 1.5, 'parallaxHeight': '120%', 'fadeIn': true}" data-image-src="<?= htmlspecialchars($bg) ?>">
        <div class="container pt-3 pb-5 mb-5">
            <div class="row mb-3">
                <div class="col">
                    <ul class="breadcrumb d-block text-center custom-font-secondary text-6 font-weight-medium positive-ls-3">
                        <li><a style="color:#000;" href="index.php" class="text-decoration-none opacity-hover-8">INÍCIO</a></li>
                        <li style="color:#fff;" class="active text-color-primary"><?= htmlspecialchars($nomeUp) ?></li>
                    </ul>
                    <h1 class="d-block text-color-light font-weight-bold text-center text-12 positive-ls-1 line-height-2 mb-0"><?= htmlspecialchars($nomeUp) ?></h1>
                </div>
            </div>
        </div>
        <div class="shape-divider shape-divider-bottom shape-divider-reverse-y" style="height: 116px;">
            <svg version="1.1" xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" viewBox="0 0 1920 116" preserveAspectRatio="xMinYMin">
                <path fill="#FFF" d="M453,92c11.7-4.87,28.46-11.43,49-18c42.29-13.52,76.36-19.33,115-25c51.58-7.57,100.28-14.72,171-20c24.87-1.86,82.88-5.76,158-6c69.99-0.23,122.54,2.82,159,5c51.18,3.06,95.17,5.69,155,14c71.5,9.94,115.42,21.02,127,24c33.7,8.68,61.62,17.79,82,25C1130.33,91.33,791.67,91.67,453,92z"/>
                <rect y="90" fill="#FFF" width="1920" height="26"/>
            </svg>
        </div>
    </section>
    <?php endif; ?>

    <div id="<?= $ancora ?>" class="container py-2">
        <?php if (!$produtos): ?>
            <p class="text-center text-4 py-5">Em breve nossos produtos desta categoria por aqui. Fale conosco no WhatsApp!</p>
        <?php elseif ($layoutBolos): // Bolos: sabores (lista) + decorações (fotos) ?>
            <ul class="nav nav-pills justify-content-center bolos-filter">
                <li class="active" data-f="all">Todos</li>
                <li data-f="sabores">Sabores</li>
                <li data-f="decoracoes">Decorações</li>
            </ul>
            <div class="bolos-block" data-block="sabores">
                <?php foreach ($subsSab as $s): $lista = $porSub[(int) $s['id']] ?? []; if (!$lista) { continue; }
                    $g0 = catalogo_preco_a_partir($lista[0]); ?>
                    <div class="sabor-grupo">
                        <div class="sabor-grupo-h">
                            <span class="g-nome"><?= htmlspecialchars($s['nome']) ?></span>
                            <?php if ($g0 !== null): ?><span class="g-preco">a partir de <?= catalogo_moeda($g0) ?></span><?php endif; ?>
                        </div>
                        <div class="sabor-itens">
                            <?php foreach ($lista as $p): ?>
                                <a href="#" class="sabor-item" onclick="catalogoAbrir(<?= (int) $p['id'] ?>);return false;"><?= htmlspecialchars($p['nome']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="sabor-obs">Toque em um recheio para ver massas, tamanhos e valores. Combine com a decoração da sua preferência.</p>
            </div>
            <div class="bolos-block" data-block="decoracoes">
                <div class="row g-3 justify-content-center pt-2">
                    <?php foreach ($subsDeco as $s): foreach ($porSub[(int) $s['id']] ?? [] as $p):
                        $img = $p['imagem'] ?: 'img/imagens/doces/sem-imagem.jpg'; ?>
                        <div class="col-6 col-sm-6 col-md-4 col-lg-3 mb-4">
                            <a href="#" class="cat-card" role="button" onclick="catalogoAbrir(<?= (int) $p['id'] ?>);return false;">
                                <span class="cat-card-photo"><img src="<?= htmlspecialchars(ltrim($img, '/')) ?>" alt="<?= htmlspecialchars($p['nome']) ?>"></span>
                                <span class="cat-card-cap"><span class="nm"><?= htmlspecialchars($p['nome']) ?></span></span>
                            </a>
                        </div>
                    <?php endforeach; endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <?php $usarFiltro = count($subs) > 1; ?>
            <?php if ($usarFiltro): ?>
            <ul class="nav nav-pills sort-source sort-source-style-3 justify-content-center" data-sort-id="catalogo" data-option-key="filter" data-plugin-options="{'layoutMode': 'fitRows', 'filter': '*'}">
                <li class="nav-item active" data-option-value="*"><a class="nav-link text-1 text-uppercase active" href="#">Todos</a></li>
                <?php foreach ($subs as $s): ?>
                    <li class="nav-item" data-option-value=".sub-<?= htmlspecialchars($s['slug']) ?>"><a class="nav-link text-1 text-uppercase" href="#"><?= htmlspecialchars($s['nome']) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="<?= $usarFiltro ? 'sort-destination-loader sort-destination-loader-showing ' : '' ?>mt-4 pt-2">
                <div class="row portfolio-list<?= $usarFiltro ? ' sort-destination' : '' ?> justify-content-center" data-sort-id="catalogo">
                    <?php foreach ($produtos as $p):
                        $ap = catalogo_preco_a_partir($p);
                        $img = $p['imagem'] ?: 'img/imagens/doces/sem-imagem.jpg'; ?>
                        <div class="col-6 col-sm-6 col-md-6 col-lg-3 mb-4<?= $usarFiltro ? ' isotope-item sub-' . htmlspecialchars($p['sub_slug']) : '' ?>">
                            <a href="#" class="cat-card" role="button" onclick="catalogoAbrir(<?= (int) $p['id'] ?>);return false;">
                                <span class="cat-card-photo">
                                    <img src="<?= htmlspecialchars(ltrim($img, '/')) ?>" alt="<?= htmlspecialchars($p['nome']) ?>">
                                </span>
                                <span class="cat-card-cap">
                                    <span class="nm"><?= htmlspecialchars($p['nome']) ?></span>
                                    <span class="pr"><?= $ap !== null ? 'a partir de ' . catalogo_moeda($ap) : 'Consulte' ?></span>
                                </span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($modo): ?></div><!-- /.cat-page --><?php endif; ?>

    <!-- Modal do produto -->
    <style>
    <?php if ($modo): ?>
    /* Página de categoria no estilo folder (creme + serifada) — só no modo catálogo. */
    body { background:#f2e8d6; }   /* nada de branco sobrando em páginas curtas */
    .cat-page { background:#f8f0e2; background-image:radial-gradient(rgba(176,137,78,.05) 1px, transparent 1px); background-size:22px 22px; min-height:62vh; }
    @media (max-width:767.98px) { .cat-page { min-height:calc(100vh - 118px); } }
    .cat-hero { position:relative; text-align:center; background:#f2e8d6; border-bottom:1px solid #e2cfa6; padding:2.6rem 1rem 2.1rem; }
    .cat-hero-back { position:absolute; left:22px; top:24px; color:#b0894e; text-decoration:none; font-weight:700; font-size:.74rem; letter-spacing:.12em; text-transform:uppercase; }
    .cat-hero-back:hover { color:#8f1d2e; }
    .cat-hero .cat-orn { color:#b0894e; margin-bottom:.5rem; font-size:1.05rem; }
    .cat-hero-title { font-family:'Lora',serif; font-weight:700; color:#8f1d2e; font-size:clamp(2rem,5vw,3rem); line-height:1.1; margin:0; }
    .cat-hero-sub { color:#7a6c5f; margin:.5rem auto 0; max-width:620px; font-size:1rem; }
    @media(max-width:575.98px){ .cat-hero-back{ left:14px; top:16px; font-size:0; } .cat-hero-back::before{ content:'\2190'; font-size:1.2rem; } }
    <?php endif; ?>
    /* Card do produto: foto emoldurada + legenda embaixo (nome/preço). */
    .cat-card { display:block; text-decoration:none !important; }
    .cat-card-photo { display:block; overflow:hidden; border:5px solid #fff; border-radius:2px; box-shadow:0 8px 20px rgba(70,45,20,.16); background:#f3eded; }
    .cat-card-photo img { width:100%; aspect-ratio:1/1; object-fit:cover; display:block; transition:transform .55s ease; }
    .cat-card:hover .cat-card-photo img { transform:scale(1.06); }
    .cat-card-cap { display:block; text-align:center; margin-top:.6rem; }
    .cat-card-cap .nm { display:block; font-family:'Lora',serif; color:#3f342c; font-size:1rem; font-weight:600; line-height:1.2; }
    .cat-card-cap .pr { display:block; color:#a51d32; font-size:.82rem; margin-top:.12rem; }
    /* Celular: 2 cards por linha (garante mesmo com override do tema). */
    @media (max-width:575.98px) {
        .portfolio-list > [class*="col-"],
        .portfolio-list.sort-destination > .isotope-item { width:50% !important; flex:0 0 50% !important; max-width:50% !important; }
        .cat-card-cap .nm { font-size:.88rem; }
        .cat-card-cap .pr { font-size:.75rem; }
    }
    /* Bolos: filtro Todos/Sabores/Decorações + sabores em lista. */
    .bolos-filter { list-style:none; display:flex; gap:.5rem; justify-content:center; flex-wrap:wrap; padding:0; margin:1.6rem 0 2rem; }
    .bolos-filter li { cursor:pointer; padding:.4rem 1.15rem; border:1px solid #d8c39a; border-radius:30px; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#8a7a5f; transition:all .15s; }
    .bolos-filter li:hover { color:#8f1d2e; }
    .bolos-filter li.active { background:#8f1d2e; border-color:#8f1d2e; color:#fff; }
    .sabor-grupo { max-width:780px; margin:0 auto 1.8rem; }
    .sabor-grupo-h { display:flex; justify-content:space-between; align-items:baseline; gap:1rem; border-bottom:1px solid #e2cfa6; padding-bottom:.45rem; margin-bottom:.85rem; }
    .sabor-grupo-h .g-nome { font-family:'Lora',serif; font-size:1.25rem; color:#3f342c; font-weight:700; }
    .sabor-grupo-h .g-preco { color:#8f1d2e; font-size:.85rem; font-weight:600; white-space:nowrap; }
    .sabor-itens { display:flex; flex-wrap:wrap; gap:.5rem; }
    .sabor-item { display:inline-block; padding:.45rem .95rem; border:1px solid #e6ddcb; border-radius:8px; background:#fff; color:#3f342c; text-decoration:none; font-size:.9rem; transition:all .15s; }
    .sabor-item:hover { border-color:#8f1d2e; color:#8f1d2e; background:#fff; text-decoration:none; }
    .sabor-obs { text-align:center; color:#9a8f80; font-size:.85rem; font-style:italic; margin:1.6rem auto 0; max-width:640px; }
    #modal-produto .modal-content { border:0; border-radius:16px; overflow:hidden; box-shadow:0 30px 70px rgba(0,0,0,.35); }
    #modal-produto .mp-close { position:absolute; top:14px; right:14px; z-index:5; width:38px; height:38px; border:0; border-radius:50%;
        background:rgba(255,255,255,.9); color:#333; font-size:20px; line-height:1; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,.15); }
    #modal-produto .mp-close:hover { background:#fff; }
    #modal-produto .mp-img-col { min-height:340px; background:#f3eded url('img/imagens/doces/sem-imagem.jpg') center/cover no-repeat; }
    #modal-produto .mp-img-col img { width:100%; height:100%; object-fit:cover; display:block; }
    #modal-produto .mp-body { padding:2.2rem 2.2rem 2.4rem; }
    #modal-produto .mp-nome { font-family:inherit; font-weight:800; letter-spacing:.02em; text-transform:uppercase; color:#2b2b2b; font-size:1.7rem; line-height:1.1; margin:0 0 .35rem; }
    #modal-produto .mp-apartir { color:#a51d32; font-weight:700; font-size:1.05rem; margin:0 0 1rem; }
    #modal-produto .mp-desc { color:#555; font-size:.98rem; margin:0 0 1.1rem; }
    #modal-produto .mp-precos { margin:0 0 1rem; }
    #modal-produto .mp-preco { display:flex; align-items:baseline; justify-content:space-between; gap:1rem; padding:.7rem 0; border-bottom:1px solid #efe9ea; }
    #modal-produto .mp-preco:last-child { border-bottom:0; }
    #modal-produto .mp-preco .rot { color:#333; font-weight:600; font-size:1rem; }
    #modal-produto .mp-preco .obs { display:block; color:#9a9a9a; font-weight:400; font-size:.8rem; margin-top:.1rem; }
    #modal-produto .mp-preco .val { white-space:nowrap; font-weight:800; color:#2b2b2b; font-size:1.05rem; }
    #modal-produto .mp-preco .val .de { display:block; text-align:right; font-weight:500; font-size:.8rem; color:#b0a9a9; text-decoration:line-through; }
    #modal-produto .mp-instr { color:#8a8a8a; font-size:.85rem; font-style:italic; margin:.2rem 0 1.4rem; }
    #modal-produto .mp-wa { display:inline-flex; align-items:center; gap:.6rem; background:#25d366; color:#fff; font-weight:700;
        border-radius:50px; padding:.85rem 1.8rem; font-size:1.02rem; text-decoration:none; box-shadow:0 8px 20px rgba(37,211,102,.35); transition:background .2s, transform .1s; }
    #modal-produto .mp-wa:hover { background:#1fb457; color:#fff; transform:translateY(-1px); }
    @media (max-width:767px){ #modal-produto .mp-img-col{ min-height:220px; } #modal-produto .mp-body{ padding:1.5rem; } #modal-produto .mp-nome{ font-size:1.4rem; } }
    </style>
    <div class="modal fade" id="modal-produto" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <button type="button" class="mp-close" data-bs-dismiss="modal" aria-label="Fechar">&times;</button>
          <div class="row g-0">
            <div class="col-md-5 mp-img-col">
              <img id="mp-img" src="" alt="">
            </div>
            <div class="col-md-7">
              <div class="mp-body">
                <h3 id="mp-nome" class="mp-nome"></h3>
                <div id="mp-apartir" class="mp-apartir"></div>
                <p id="mp-desc" class="mp-desc"></p>
                <div id="mp-precos" class="mp-precos"></div>
                <div id="mp-instrucao" class="mp-instr"></div>
                <a id="mp-wa" href="#" target="_blank" class="mp-wa"
                   onclick="return (typeof gtag_report_conversion==='function') ? gtag_report_conversion(this.href) : true;">
                   <i class="fab fa-whatsapp"></i> Pedir no WhatsApp
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
    const CATALOGO_PRODUTOS = <?= json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const CATALOGO_WA = <?= json_encode($wa) ?>;
    function catalogoAbrir(id) {
        const p = CATALOGO_PRODUTOS[id];
        if (!p) { return; }
        document.getElementById('mp-img').src = p.imagem;
        document.getElementById('mp-img').alt = p.nome;
        document.getElementById('mp-nome').textContent = p.nome;
        document.getElementById('mp-apartir').textContent = p.apartir ? ('A partir de ' + p.apartir + (p.rotulo ? ' (' + p.rotulo + ')' : '')) : '';
        const desc = document.getElementById('mp-desc');
        desc.textContent = p.descricao || ''; desc.style.display = p.descricao ? '' : 'none';
        const box = document.getElementById('mp-precos');
        box.innerHTML = '';
        (p.precos || []).forEach(function (f) {
            const row = document.createElement('div');
            row.className = 'mp-preco';
            const val = f.cheio ? ('<span class="de">' + f.cheio + '</span>' + f.valor) : f.valor;
            row.innerHTML = '<span class="rot">' + (f.rotulo || '') + (f.obs ? '<span class="obs">' + f.obs + '</span>' : '') + '</span>'
                          + '<span class="val">' + val + '</span>';
            box.appendChild(row);
        });
        const instr = document.getElementById('mp-instrucao');
        instr.textContent = p.instrucao || ''; instr.style.display = p.instrucao ? '' : 'none';
        document.getElementById('mp-wa').href = 'https://wa.me/' + CATALOGO_WA + '?text=' + encodeURIComponent('Olá! Gostaria de encomendar: ' + p.nome + '.');
        const el = document.getElementById('modal-produto');
        if (window.bootstrap && bootstrap.Modal) { bootstrap.Modal.getOrCreateInstance(el).show(); }
        else { el.classList.add('show'); el.style.display = 'block'; }
    }
    // Filtro de Bolos: Todos / Sabores / Decorações (mostra/esconde os blocos).
    document.querySelectorAll('.bolos-filter li').forEach(function (li) {
        li.addEventListener('click', function () {
            document.querySelectorAll('.bolos-filter li').forEach(function (x) { x.classList.remove('active'); });
            li.classList.add('active');
            var f = li.getAttribute('data-f');
            document.querySelectorAll('.bolos-block').forEach(function (b) {
                b.style.display = (f === 'all' || b.getAttribute('data-block') === f) ? '' : 'none';
            });
        });
    });
    </script>
    <?php
    return true;
}
