<?php
$page_title = 'Blog | Arlete Vieira Confeitaria & Doceria';
$page_description = 'Dicas, novidades, cases e inspirações do universo da confeitaria, presentes corporativos e eventos na Arlete Vieira Confeitaria & Doceria.';
$page_keywords = 'blog, confeitaria, doceria, dicas, novidades, presentes corporativos, eventos, São José, SC, Arlete Vieira Confeitaria & Doceria';
$page_image = 'https://arletevieiraconfeitaria.com.br/img/imagens/background-3.png';
include "includes/top.php" ?>

<div role="main" class="main">
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

					<div class="row" id="posts">
						<div class="col-lg-3 order-lg-2">
							<aside class="sidebar">
								<h5 class="font-weight-semi-bold pt-4">Categorias</h5>
								<ul class="nav nav-list  sort-source flex-column mb-5" data-sort-id="posts">
                                    <li class="nav-item active" data-option-value="*"><a class="nav-link active" href="#">Todos</a></li>
                                    <li class="nav-item" data-option-value=".Bolos"><a class="nav-link" href="#">Bolos</a></li>
									<li class="nav-item" data-option-value=".Casamentos"><a class="nav-link" href="#">Casamentos</a></li>
									<li class="nav-item" data-option-value=".Doces"><a class="nav-link" href="#">Doces</a></li>
									<li class="nav-item" data-option-value=".Eventos"><a class="nav-link" href="#">Eventos Corporativos</a></li>
									<li class="nav-item" data-option-value=".Presentes"><a class="nav-link" href="#">Presentes Corporativos</a></li>
									<li class="nav-item" data-option-value=".Salgados"><a class="nav-link" href="#">Salgados</a></li>
								</ul>
								<h5 class="font-weight-semi-bold pt-4">Nosso Blog</h5>
								<p>Inspire-se com os doces momentos que já criamos! Aqui você encontra nossos serviços, bolos personalizados, doces finos, eventos especiais, casamentos inesquecíveis e ideias encantadoras para presentear com sabor. </p>
							</aside>
						</div>
						<div class="col-lg-9 order-lg-1 sort-destination-loader sort-destination-loader-showing ">
							<div class="blog-posts portfolio-list sort-destination" data-sort-id="posts">

								<div class="row px-3">
									<?php if (count($posts) > 0): ?>
										<?php foreach ($posts as $post): ?>
											<div class="col-sm-6 col-lg-6 isotope-item <?= htmlspecialchars($post['categoria']) ?>">
												<article class="post post-medium border-0 pb-0 mb-5">
													<div class="post-image">
														<a href="blog-post.php?id=<?= htmlspecialchars($post['id']) ?>">
															<img src="img/imagens/blog/<?= htmlspecialchars($post['imagem']) ?>" class="img-fluid img-thumbnail img-thumbnail-no-borders rounded-0" alt="<?= htmlspecialchars($post['titulo']) ?> - Arlete Vieira Confeitaria" loading="lazy" />
														</a>
													</div>

													<div class="post-content">

														<h2 class="font-weight-semibold text-5 line-height-6 mt-3 mb-2"><a style="color:#484848;" href="blog-post.php?id=<?= htmlspecialchars($post['id']) ?>"><?= htmlspecialchars($post['titulo']) ?></a></h2>
														<p><?= htmlspecialchars($post['conteudo_resumido']) ?></p>

														<div class="post-meta">
															<span><i class="far fa-user"></i> Por <a style="color:#ccc;">Matheus Vieira</a> </span>
															<span><i class="far fa-folder"></i> <a style="color:#ccc;"><?= htmlspecialchars($post['categoria']) ?></a></span>
															<span class="d-block mt-2"><a href="blog-post.php?id=<?= htmlspecialchars($post['id']) ?>" class="btn btn-xs btn-light text-1 text-uppercase">Ler Mais</a></span>
														</div>

													</div>
												</article>
											</div>
										<?php endforeach; ?>
									<?php else: ?>
										<p>Nenhum post encontrado.</p>
									<?php endif; ?>	
								</div>

							</div>
						</div>
					</div>

				</div>

			</div>
			

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
