<?php
// Página pública de uma categoria do catálogo. O arquivo que inclui este
// partial deve definir antes:
//   $cat_slug (obrigatório) e, opcionalmente, $page_title/$page_description/$page_image.
// Mantém o wrapper do tema Porto (top + hero/grade/modal + rodapé).

$cat_slug = $cat_slug ?? '';
// Modo definido pelo chamador: cardapio.php?cat= liga o modo catálogo (folder);
// as páginas do site (doces.php etc.) deixam desligado = padrão do site.
$catalogo_modo = !empty($catalogo_modo);

// SEO: se o chamador não definiu, deriva do nome da categoria.
if (empty($page_title)) {
    require_once __DIR__ . '/../includes/banco.php';
    require_once __DIR__ . '/../admin/model_catalogo.php';
    $c = catalogo_categoria_por_slug($pdo, $cat_slug);
    $nome = $c['nome'] ?? 'Cardápio';
    $page_title = $nome . ' | Arlete Vieira Confeitaria & Doceria';
    $page_description = $page_description ?? ($nome . ' da Arlete Vieira Confeitaria & Doceria — encomendas para festas e eventos em São José/SC.');
}

include __DIR__ . '/top.php';

require_once __DIR__ . '/catalogo_render.php';
if (!catalogo_render_categoria($pdo, $cat_slug, $catalogo_modo)) {
    echo '<div class="container py-6 text-center"><h2 class="py-5 text-color-dark">Categoria não encontrada.</h2>'
       . '<a href="index.php" class="btn btn-primary">Voltar ao início</a></div>';
}
?>
        </div>

    <?php if ($catalogo_modo): // rodapé do folder ?>
    <div style="background:#f2e8d6;border-top:1px solid #e2cfa6;text-align:center;padding:2rem 1rem;">
        <a href="cardapio.php" style="color:#8f1d2e;text-decoration:none;font-weight:700;letter-spacing:.06em;">&larr; Voltar ao cardápio</a>
        <div style="color:#7a6c5f;font-size:.76rem;letter-spacing:.1em;text-transform:uppercase;margin-top:.8rem;">Arlete Vieira Confeitaria &amp; Doceria</div>
    </div>
    <?php else: // transição do site (onda escura), como nas outras páginas ?>
    <section class="section section-with-shape-divider bg-dark border-0 m-0">
        <div class="shape-divider shape-divider-reverse-x" style="height: 116px;">
            <svg version="1.1" xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" viewBox="0 0 1920 116" preserveAspectRatio="xMinYMin">
                <path fill="#FFF" d="M453,92c11.7-4.87,28.46-11.43,49-18c42.29-13.52,76.36-19.33,115-25c51.58-7.57,100.28-14.72,171-20c24.87-1.86,82.88-5.76,158-6c69.99-0.23,122.54,2.82,159,5c51.18,3.06,95.17,5.69,155,14c71.5,9.94,115.42,21.02,127,24c33.7,8.68,61.62,17.79,82,25C1130.33,91.33,791.67,91.67,453,92z"/>
                <rect y="90" fill="#FFF" width="1920" height="26"/>
            </svg>
        </div>
    </section>
    <?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
