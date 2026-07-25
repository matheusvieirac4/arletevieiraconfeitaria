<?php
require_once __DIR__ . '/includes/banco.php';        // $pdo (antes do top.php)
require_once __DIR__ . '/includes/blog_html.php';    // blog_sanitizar_html

// ====== CONSULTA O POST ANTES do top.php, para o SEO refletir o post ======
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$post = null;
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$post) {
    http_response_code(404);
    $page_title = 'Post não encontrado | Arlete Vieira Confeitaria & Doceria';
    include "includes/top.php";
    echo '<div class="container py-5 my-5 text-center"><h1 class="mb-4">Post não encontrado</h1>'
       . '<a href="blog.php" class="btn btn-primary">Voltar ao blog</a></div>';
    include "includes/footer.php";
    exit;
}

// ====== SEO dinâmico, agora que o post existe ======
$page_title = $post['titulo'] . ' | Arlete Vieira Confeitaria & Doceria';
$page_description = $post['conteudo_resumido'] !== '' ? $post['conteudo_resumido'] : 'Conteúdo do blog da Arlete Vieira Confeitaria & Doceria.';
$page_keywords = 'blog, confeitaria, doceria, dicas, novidades, presentes corporativos, eventos, São José, SC, Arlete Vieira Confeitaria & Doceria';
$page_image = 'https://arletevieiraconfeitaria.com.br/img/imagens/blog/' . ($post['imagem'] ?: 'metatag-img.jpg');
$page_url = 'https://arletevieiraconfeitaria.com.br/blog-post.php?id=' . $id;
include "includes/top.php";
?>
                <section class="section section-with-shape-divider section-height-3 overlay overlay-show border-0 m-0" data-plugin-parallax data-plugin-options="{'speed': 1.5, 'parallaxHeight': '120%', 'fadeIn': true}" data-image-src="img/imagens/background-3.png">
                    <div class="container pt-3 pb-5 mb-5">
                        <div class="row mb-3">
                            <div class="col">
                                <ul class="breadcrumb d-block text-center custom-font-secondary text-6 font-weight-medium positive-ls-3">
                                    <li><a style="color: #000;" href="index.php" class="text-decoration-none opacity-hover-8">INÍCIO</a></li>
                                    <li style="color:#fff;" class="active text-color-primary">BLOG</li>
                                </ul>
                                <h1 class="d-block text-color-light font-weight-bold text-center text-12 positive-ls-1 line-height-2 mb-0">NOSSO BLOG</h1>
                            </div>
                        </div>
                    </div>
                    <a href="#posts" data-hash data-hash-offset="0" data-hash-offset-lg="100" data-hash-force="true" class="text-decoration-none text-color-dark text-color-hover-primary text-5-5 position-absolute transform3dx-n50 left-50pct bottom-5 mb-4 z-index-2">
                        <i class="icons icon-arrow-down font-weight-bold"></i>
                    </a>
                    <div class="shape-divider shape-divider-bottom shape-divider-reverse-y" style="height: 116px;">
                        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 1920 116" preserveAspectRatio="xMinYMin">
                            <path fill="#FFF" d="M453,92c11.7-4.87,28.46-11.43,49-18c42.29-13.52,76.36-19.33,115-25c51.58-7.57,100.28-14.72,171-20
                                c24.87-1.86,82.88-5.76,158-6c69.99-0.23,122.54,2.82,159,5c51.18,3.06,95.17,5.69,155,14c71.5,9.94,115.42,21.02,127,24
                                c33.7,8.68,61.62,17.79,82,25C1130.33,91.33,791.67,91.67,453,92z"/>
                            <rect y="90" fill="#FFF" width="1920" height="26"/>
                        </svg>
                    </div>
                </section>
                <div class="container py-4">
					<div class="row">
						<div class="col">
							<div class="blog-posts single-post">

								<article class="post post-large blog-single-post border-0 m-0 p-0">

									<div class="post-date ms-0">
										<span class="day"><?= htmlspecialchars(date('d', strtotime($post['criado_em']))) ?></span>
										<span class="month"><?= htmlspecialchars(date('M', strtotime($post['criado_em']))) ?></span>
									</div>

									<div class="post-content ms-0">

										<h2 class="font-weight-semi-bold" style="color:#484848"><?= htmlspecialchars($post['titulo']) ?></h2>

										<div class="post-meta">
											<span><i class="far fa-user"></i> Por <span class="text-color-primary">Matheus Vieira</span> </span>
											<span><i class="far fa-folder"></i> <a href="blog.php" class="text-decoration-none"><?= htmlspecialchars($post['categoria']) ?></a></span>
										</div><img src="img/imagens/blog/<?= htmlspecialchars($post['imagem']) ?>" class="img-fluid float-start me-4 mt-2" alt="<?= htmlspecialchars($post['titulo']) ?>" style="width:550px">
										<?php
										// Post novo (editor rich text): renderiza o HTML sanitizado.
										// Post antigo: cai no formato de 4 parágrafos em texto puro.
										$htmlPost = trim((string) ($post['conteudo_html'] ?? ''));
										if ($htmlPost !== '') {
										    echo '<div class="blog-conteudo">' . blog_sanitizar_html($htmlPost) . '</div>';
										} else {
										    foreach (['conteudo','conteudo_dois','conteudo_tres','conteudo_quatro'] as $campo) {
										        $t = trim((string) ($post[$campo] ?? ''));
										        if ($t !== '') { echo '<p>' . htmlspecialchars($t) . '</p>'; }
										    }
										}
										?>

										<div class="post-block mt-4 pt-2 post-author">
											<h4 class="mb-3">Autor</h4>
											<div class="img-thumbnail img-thumbnail-no-borders d-block pb-3">
												<a>
													<img src="img/avatars/avatar.jpg" alt="Foto do autor do post" loading="lazy">
												</a>
											</div>
											<p><strong class="name"><a href="#" class="text-4 pb-2 pt-2 d-block">Matheus Vieira</a></strong></p>
											<p>Matheus Vieira é gestor e comercial da Arlete Vieira Confeitaria, onde une criatividade, estratégia e amor por números para transformar doces em experiências inesquecíveis.</p>
										</div>
									</div>
								</article>

							</div>
						</div>
					</div>
				</div>
                <section class="parallax section section-text-light section-parallax" data-plugin-parallax="" data-plugin-options="{'speed': 1.5, 'parallaxHeight': '210%'}" data-image-src="img/parallax/parallax-2.jpg" style="position: relative; overflow: hidden;"><div class="parallax-background" style="background-image: url(&quot;img/parallax/parallax-2.jpg&quot;); background-size: cover; position: absolute; top: 0px; left: 0px; width: 100%; height: 210%; transform: translate3d(0px, -106.376px, 0px); background-position-x: 50%;"></div>
					<section class="call-to-action">
						<div class="container">
							<div class="row">
								<div class="col-sm-9 col-lg-9">
									<div class="call-to-action-content">
										<h3>Confira outras postagens no nosso blog e inspire-se com mais doces momentos!</h3>
									</div>
								</div>
								<div class="col-sm-3 col-lg-3">
									<div class="call-to-action-btn">
										<a href="blog.php" class="btn btn-modern text-2 btn-primary">Nosso Blog</a>
									</div>
								</div>
							</div>
						</div>
					</section>
				</section>

				<section class="section section-with-shape-divider bg-dark border-0 m-0">
					<a href="#header" data-hash data-hash-offset="0" data-hash-offset-lg="100" data-hash-force="true" class="text-decoration-none text-color-dark text-color-hover-primary position-absolute transform3dx-n50 left-50pct bottom-0 text-5 mb-5 z-index-2">
						<i class="icons icon-arrow-up font-weight-bold"></i>
					</a>
					<div class="shape-divider shape-divider-reverse-x" style="height: 116px;">
						<svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" viewBox="0 0 1920 116" preserveAspectRatio="xMinYMin">
							<path fill="#FFF" d="M453,92c11.7-4.87,28.46-11.43,49-18c42.29-13.52,76.36-19.33,115-25c51.58-7.57,100.28-14.72,171-20
								c24.87-1.86,82.88-5.76,158-6c69.99-0.23,122.54,2.82,159,5c51.18,3.06,95.17,5.69,155,14c71.5,9.94,115.42,21.02,127,24
								c33.7,8.68,61.62,17.79,82,25C1130.33,91.33,791.67,91.67,453,92z"/>
							<rect y="90" fill="#FFF" width="1920" height="26"/>
						</svg>
					</div>
				</section>
<?php include "includes/footer.php" ?>