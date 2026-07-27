<?php
include "includes/banco.php";

// ====== CATEGORIAS DO CARDÁPIO (menu dinâmico) ======
require_once __DIR__ . '/../admin/model_catalogo.php';
$cat_menu = [];
try {
    if (catalogo_pronto($pdo)) { $cat_menu = catalogo_categorias_listar($pdo, false); }
} catch (\Throwable $e) { $cat_menu = []; }
$cat_files = array_map(function ($cm) { return basename(strtok(catalogo_pagina_do_slug($cm['slug']), '?')); }, $cat_menu);
$catMenuAtivo = in_array(basename($_SERVER['PHP_SELF']), array_merge($cat_files, ['cardapio.php']), true);

// ====== CONFERE PÁGINA E ATIVA MENU ======
function isActive($page) {
	return (basename($_SERVER['PHP_SELF']) === $page) ? 'active' : '';
  }

// ====== CONSULTA POSTS EXISTENTES ======
$stmt = $pdo->query("SELECT id, titulo, conteudo, conteudo_resumido, categoria, criado_em, imagem FROM posts ORDER BY criado_em DESC");
$posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
// SEO dinâmico
$page_title = $page_title ?? 'Arlete Vieira Confeitaria & Doceria | Bolos, Doces e Salgados em São José - SC';
$page_description = $page_description ?? 'Bolos, doces, salgados e presentes corporativos em São José/SC. Encomende para festas, empresas e eventos.';
$page_keywords = $page_keywords ?? 'confeitaria, bolos, doces, salgados, presentes corporativos, São José, SC, encomendas';
$page_url = $page_url ?? 'https://arletevieiraconfeitaria.com.br/'.basename($_SERVER['PHP_SELF']);
$page_image = $page_image ?? 'https://arletevieiraconfeitaria.com.br/img/imagens/metatag-img.jpg';
$page_title = str_replace('Arlete Vieira Confeitaria & Doceria', 'Arlete Vieira Confeitaria & Doceria', $page_title);
$page_description = str_replace('Arlete Vieira Confeitaria', 'Arlete Vieira Confeitaria & Doceria', $page_description);
$page_keywords = str_replace('Arlete Vieira Confeitaria', 'Arlete Vieira Confeitaria & Doceria', $page_keywords);
$page_url = str_replace('Arlete Vieira Confeitaria', 'Arlete Vieira Confeitaria & Doceria', $page_url);
$page_image = str_replace('Arlete Vieira Confeitaria', 'Arlete Vieira Confeitaria & Doceria', $page_image);

$whatsapp_pedido_url = 'https://wa.me/554820133000?text=Olá! Vim do site e gostaria de mais informações!';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1.0, shrink-to-fit=no">
    <title><?= htmlspecialchars($page_title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($page_description) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($page_keywords) ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars($page_url) ?>">
    <meta name="author" content="Arlete Vieira Confeitaria">
    <meta property="og:title" content="<?= htmlspecialchars($page_title) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($page_description) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($page_image) ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($page_url) ?>" />
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="Arlete Vieira Confeitaria & Doceria" />
    <meta property="og:locale" content="pt_BR" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= htmlspecialchars($page_title) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($page_description) ?>" />
    <meta name="twitter:image" content="<?= htmlspecialchars($page_image) ?>" />
    <link rel="icon" href="img/favicon.png"/>
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
    <!-- Schema.org Bakery -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "Bakery",
      "name": "Arlete Vieira Confeitaria & Doceria",
      "image": "https://arletevieiraconfeitaria.com.br/img/logo.png",
      "url": "https://arletevieiraconfeitaria.com.br",
      "telephone": "+55 48 2013-3000",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Rua José Francisco Cunha, 246",
        "addressLocality": "São José",
        "addressRegion": "SC",
        "addressCountry": "BR"
      },
      "servesCuisine": "Doces, Bolos, Salgados, Doces Finos",
      "priceRange": "R$"
    }
    </script>
  	<!-- Google Tag Manager -->
	<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
	new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
	j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
	'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
	})(window,document,'script','dataLayer','GTM-M4M5DMZD');</script>
	<!-- End Google Tag Manager -->
    <!-- Google tag (gtag.js) - Google Ads -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-16484252467"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', 'AW-16484252467');
    function gtag_report_conversion(url) {
        var callback = function () {
            if (typeof(url) != 'undefined') {
                window.location = url;
            }
        };
        gtag('event', 'conversion', {
            'send_to': 'AW-16484252467/ol-zCPzfgMEcELP2prQ9',
            'value': 1.0,
            'currency': 'BRL',
            'event_callback': callback
        });
        return false;
    }
    </script>
    <!-- Meta Pixel Code -->
    <script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '797458779023974');
    fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none"
    src="https://www.facebook.com/tr?id=797458779023974&ev=PageView&noscript=1"
    /></noscript>
    <!-- End Meta Pixel Code -->
    <!-- Web Fonts  -->
    <link id="googleFonts" href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700%7CLora:400,500,700&display=swap" rel="stylesheet" type="text/css">
    <!-- Vendor CSS -->
    <link rel="stylesheet" href="vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendor/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="vendor/animate/animate.compat.css">
    <link rel="stylesheet" href="vendor/simple-line-icons/css/simple-line-icons.min.css">
    <link rel="stylesheet" href="vendor/owl.carousel/assets/owl.carousel.min.css">
    <link rel="stylesheet" href="vendor/owl.carousel/assets/owl.theme.default.min.css">
    <link rel="stylesheet" href="vendor/magnific-popup/magnific-popup.min.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="css/theme.css">
    <link rel="stylesheet" href="css/theme-elements.css">
    <link rel="stylesheet" href="css/theme-blog.css">
    <link rel="stylesheet" href="css/theme-shop.css">
    <!-- Demo CSS -->
    <link rel="stylesheet" href="css/demos/demo-restaurant.css">
    <!-- Skin CSS -->
    <link id="skinCSS" rel="stylesheet" href="css/skins/skin-restaurant.css">
    <!-- Theme Custom CSS -->
    <link rel="stylesheet" href="css/custom.css">
</head>
<body>
	<!-- Google Tag Manager (noscript) -->
	<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-M4M5DMZD"
	height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
	<!-- End Google Tag Manager (noscript) -->
    <div class="body">
			<?php if (empty($catalogo_modo)): ?>
			<header id="header" class="header-effect-shrink" data-plugin-options="{'stickyEnabled': true, 'stickyEffect': 'shrink', 'stickyEnableOnBoxed': true, 'stickyEnableOnMobile': false, 'stickyChangeLogo': true, 'stickyStartAt': 120, 'stickyHeaderContainerHeight': 85}">
				<div class="header-body border-top-0">
					<div class="header-top header-top-default header-top-borders border-bottom-0 bg-dark">
						<div class="container">
							<div class="header-row">
								<div class="header-column justify-content-between">
									<div class="header-row">
										<div class="d-flex align-items-center w-100 w-sm-50pct w-md-100pct">
											<span class="text-color-light text-2 d-sm-none d-md-block">Faça sua encomenda: </span>
											<a class="text-color-light text-decoration-none font-weight-bold text-2-5 opacity-hover-9 ms-2" href="tel:+4820133000">(48) 2013-3000</a>
										</div>
										<div class="w-50pct w-md-50pct w-lg-100pct d-none d-sm-block">
											<span class="d-flex align-items-center justify-content-end text-color-light font-weight-semibold text-2-5">
												<i class="icon icon-clock font-weight-bold me-2"></i>
												Seg - Sab 10:00 - 17:30
											</span>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="header-container container" style="height: 110px;">
						<div class="header-row">
							<div class="header-column">
								<div class="header-row">
									<div class="header-logo">
										<a href="index.php">
											<img alt="Porto" width="175" src="img/logo.png">
										</a>
									</div>
								</div>
							</div>
							<div class="header-column justify-content-end w-100">
								<div class="header-row">
									<div class="header-nav header-nav-links order-2 order-lg-1">
										<div class="header-nav-main header-nav-main-text-capitalize header-nav-main-effect-1 header-nav-main-sub-effect-1">
											<nav class="collapse">
												<ul class="nav nav-pills" id="mainNav">
													<li>
														<a class="nav-link <?php echo isActive('index.php'); ?>" href="index.php">
															Início
														</a>
													</li>
																											<?php if ($cat_menu): ?>
														<li class="dropdown">
															<a class="nav-link dropdown-toggle<?php echo $catMenuAtivo ? ' active' : ''; ?>" href="cardapio.php">
																Cardápio <i class="fas fa-angle-down ms-1 cardapio-caret" style="font-size:.72em;vertical-align:middle;"></i>
															</a>
															<ul class="dropdown-menu">
																<li><a class="dropdown-item<?php echo (basename($_SERVER['PHP_SELF']) === 'cardapio.php') ? ' active' : ''; ?>" href="cardapio.php">Cardápio completo</a></li>
																<?php foreach ($cat_menu as $cm): $cmUrl = catalogo_pagina_do_slug($cm['slug']); ?>
																<li>
																	<a class="dropdown-item<?php echo isActive(basename(strtok($cmUrl, '?'))) ? ' active' : ''; ?>" href="<?= htmlspecialchars($cmUrl) ?>"><?= htmlspecialchars($cm['nome']) ?></a>
																</li>
																<?php endforeach; ?>
															</ul>
														</li>
														<?php else: ?>
														<li>
															<a class="nav-link <?php echo isActive('doces.php'); ?>" href="doces.php">Doces</a>
														</li>
														<?php endif; ?>
													<li>
														<a class="nav-link <?php echo isActive('corporativos.php'); ?>" href="corporativos.php">
															Presentes Corporativos
														</a>
													</li>
													<li>
														<a class="nav-link <?php echo isActive('blog.php'); ?>" href="blog.php">
															Blog
														</a>
													</li>
													<li>
														<a class="nav-link <?php echo isActive('sobrenos.php'); ?>" href="sobrenos.php">
															Sobre Nós
														</a>
													</li>
													<li class="d-lg-none">
														<a class="nav-link" href="<?= htmlspecialchars($whatsapp_pedido_url) ?>" onclick="return gtag_report_conversion('<?= htmlspecialchars($whatsapp_pedido_url, ENT_QUOTES) ?>');">
															Faça seu pedido!
														</a>
													</li>
												</ul>
											</nav>
										</div>
									</div>
								</div>
							</div>
							<div class="header-column header-column-search justify-content-end align-items-center d-flex w-auto flex-row">
								<a style="background-color: #a51d32 !important; border-color: #ffffff !important;" href="<?= htmlspecialchars($whatsapp_pedido_url) ?>" onclick="return gtag_report_conversion('<?= htmlspecialchars($whatsapp_pedido_url, ENT_QUOTES) ?>');" class="btn btn-dark custom-btn-style-1 font-weight-semibold text-3 ws-nowrap ms-4 d-none d-lg-block"><span>Faça seu pedido!</span></a>
								<a target="_blank" href="https://www.instagram.com/arletevieiraconfeitaria" class="text-decoration-none text-color-dark text-color-hover-primary text-5 mx-4">
									<i class="fab fa-instagram"></i>
								</a>
								<button class="btn header-btn-collapse-nav" data-bs-toggle="collapse" data-bs-target=".header-nav-main nav" aria-expanded="false">
									<i class="fas fa-bars"></i>
								</button>
							</div>
						</div>
					</div>
				</div>
			</header>
			<?php else: ?>
<style>
#catalogo-header { position:sticky; top:0; z-index:1000; background:#f8f0e2; box-shadow:0 2px 16px rgba(70,45,20,.10); }
#catalogo-header .ch-inner { display:flex; align-items:center; justify-content:space-between; padding:.55rem 1.1rem; max-width:1200px; margin:0 auto; }
#catalogo-header .ch-logo img { height:54px; width:auto; }
#catalogo-header .ch-side { flex:1; display:flex; align-items:center; }
#catalogo-header .ch-side.right { justify-content:flex-end; }
#catalogo-header .ch-site { color:#b0894e; text-decoration:none; font-weight:700; font-size:.72rem; letter-spacing:.14em; text-transform:uppercase; }
#catalogo-header .ch-ig { color:#8f1d2e; font-size:1.35rem; }
#catalogo-header .ch-nav { border-top:1px solid #e2cfa6; background:#f2e8d6; }
#catalogo-header .ch-nav-inner { display:flex; gap:.25rem; overflow-x:auto; white-space:nowrap; padding:.45rem .6rem; max-width:1200px; margin:0 auto; justify-content:center; -ms-overflow-style:none; scrollbar-width:none; }
#catalogo-header .ch-nav-inner::-webkit-scrollbar { display:none; }
#catalogo-header .ch-nav a { color:#7a6c5f; text-decoration:none; font-size:.76rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; padding:.35rem .85rem; border-radius:20px; transition:all .15s; }
#catalogo-header .ch-nav a:hover { color:#8f1d2e; }
#catalogo-header .ch-nav a.active { background:#8f1d2e; color:#fff; }
@media(max-width:600px){ #catalogo-header .ch-nav-inner{ justify-content:flex-start; } #catalogo-header .ch-logo img{ height:44px; } #catalogo-header .ch-site{ font-size:0; } #catalogo-header .ch-site::before{ content:'‹'; font-size:1.4rem; } }
</style>
<header id="catalogo-header">
    <div class="ch-inner">
        <div class="ch-side"><a href="index.php" class="ch-site" title="Voltar ao site">‹ Site</a></div>
        <a href="cardapio.php" class="ch-logo"><img src="img/logo.png" alt="Arlete Vieira Confeitaria & Doceria"></a>
        <div class="ch-side right"><a href="https://www.instagram.com/arletevieiraconfeitaria" target="_blank" class="ch-ig" aria-label="Instagram"><i class="fab fa-instagram"></i></a></div>
    </div>
    <?php if (!empty($cat_menu)): ?>
    <nav class="ch-nav">
        <div class="ch-nav-inner">
            <a href="cardapio.php" class="<?= (basename($_SERVER['PHP_SELF']) === 'cardapio.php' && empty($_GET['cat'])) ? 'active' : '' ?>">Cardápio</a>
            <?php $catAtual = (string) ($_GET['cat'] ?? ''); foreach ($cat_menu as $cm): ?>
                <a href="<?= htmlspecialchars(catalogo_folder_do_slug($cm['slug'])) ?>" class="<?= $catAtual === $cm['slug'] ? 'active' : '' ?>"><?= htmlspecialchars($cm['nome']) ?></a>
            <?php endforeach; ?>
        </div>
    </nav>
    <?php endif; ?>
</header>
<?php endif; ?>
			<div role="main" class="main">	