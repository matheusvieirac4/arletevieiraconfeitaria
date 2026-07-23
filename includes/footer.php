			<footer id="footer" class="border-top-0 bg-dark pt-5 mt-0">
				<div class="container mt-4">
					<div class="row">
						<!-- Coluna 1: Contato -->
						<div class="col-md-6 mb-4 mb-md-0 text-center text-md-start">
							<h5 class="text-color-light font-weight-bold mb-2">MATRIZ</h5>
							<p class="text-color-grey text-3-5 mb-1">
								<i class="fas fa-map-marker-alt text-color-primary me-2"></i>
								Rua José Francisco Cunha, 246, Roçado, São José - SC
							</p>
							<p class="text-color-grey text-3-5 mb-0">
								<i class="fas fa-envelope text-color-primary me-2"></i>
								contato@arletevieiraconfeitaria.com.br
							</p>
							<p class="text-color-grey text-3-5 mb-0">
								<i class="fas fa-phone-alt text-color-primary me-2"></i>
								(48) 2013-3000
							</p>
							<p class="text-color-grey text-3-5 mb-0">
								<i class="fas fa-clock text-color-primary me-2"></i>
								Seg a Sáb: 09h00 às 17h30
							</p>
							<div class="mt-3">
								<a href="https://www.instagram.com/arletevieiraconfeitaria" target="_blank"><i class="fab fa-instagram fa-lg me-2"></i></a>
								<a href="https://wa.me/554820133000" target="_blank"><i class="fab fa-whatsapp fa-lg me-2"></i></a>
								<a href="https://facebook.com/arletevieiraconfeitaria" target="_blank"><i class="fab fa-facebook fa-lg"></i></a>
							</div>
							<div class="mt-2">
								<a href="https://maps.google.com/?q=Rua+José+Francisco+Cunha,+246,+Roçado,+São+José+-+SC" target="_blank" class="btn btn-sm btn-outline-light mt-2">Como chegar</a>
							</div>
						</div>
						<!-- Coluna 2: Mapa e Links -->
						<div class="col-md-6 text-center">
							<iframe src="https://www.google.com/maps?q=Rua+José+Francisco+Cunha,+246,+Roçado,+São+José+-+SC&output=embed" width="100%" height="180" style="border:0; border-radius:8px;" allowfullscreen="" loading="lazy"></iframe>
							<div class="mt-3">
								<a href="sobrenos.php" class="text-color-grey text-3-5 me-3">Sobre nós</a>
								<a href="blog.php" class="text-color-grey text-3-5 me-3">Blog</a>
								<a href="corporativos.php" class="text-color-grey text-3-5 me-3">Presentes Corporativos</a>
								<a href="https://wa.me/554820133000?text=Olá! Vim do site e gostaria de mais informações!" class="text-color-grey text-3-5">Faça seu pedido</a>
							</div>
						</div>
					</div>
				</div>
				<div class="footer-copyright bg-dark pt-4 pb-5">
					<div class="container">
						<div class="row">
							<div class="col text-center">
								<p class="text-color-grey text-3-5">Arlete Vieira Doceria © 2025. Todos os direitos reservados.</p>
							</div>
						</div>
					</div>
				</div>
			</footer>
			
		</div>


		<!-- Vendor -->
		<script data-cfasync="false" src="https://www.okler.net/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script>
		<script src="vendor/plugins/js/plugins.min.js"></script>

		<!-- Theme Base, Components and Settings -->
		<script src="js/theme.js"></script>

		

		<!-- Theme Initialization Files -->
		<script src="js/theme.init.js"></script>

		<!-- Botão Flutuante WhatsApp com Popup estilo card -->
		<a href="#" class="whatsapp-float" id="whatsappOpen" aria-label="Fale conosco pelo WhatsApp">
			<i class="fab fa-whatsapp"></i>
		</a>
		<div id="whatsappPopup" class="whatsapp-popup" style="display:none;">
			<div class="whatsapp-popup-content">
				<button class="whatsapp-popup-close" id="whatsappClose" aria-label="Fechar popup">&times;</button>
				<div class="whatsapp-popup-header">
					<i class="fab fa-whatsapp"></i>
					<div>
						<strong>Iniciar uma conversa</strong>
						<div class="whatsapp-popup-sub">Olá! Clique no contato abaixo para iniciar uma conversa no <b>WhatsApp</b></div>
					</div>
				</div>
				<div class="whatsapp-popup-info">Nossa equipe geralmente responde em alguns minutos.</div>
				<a href="https://wa.me/554820133000" target="_blank" class="whatsapp-popup-card" aria-label="Conversar com a Matriz no WhatsApp" onclick="return gtag_report_conversion_popup('https://wa.me/554820133000');">
					<img src="img/logo.png" alt="Logo Arlete Vieira Confeitaria" class="whatsapp-popup-logo">
					<div class="whatsapp-popup-card-content">
						<span class="whatsapp-popup-card-title">Matriz - São José</span>
						<span class="whatsapp-popup-card-desc">Arlete Vieira Confeitaria</span>
					</div>
					<i class="fab fa-whatsapp whatsapp-popup-card-icon"></i>
				</a>
			</div>
		</div>
		<script>
		function gtag_report_conversion_popup(url) {
			var callback = function () {
				if (typeof(url) != 'undefined') {
					window.location = url;
				}
			};
			gtag('event', 'conversion', {
				'send_to': 'AW-16484252467/kHKhCPae7cAcELP2prQ9',
				'value': 1.0,
				'currency': 'BRL',
				'event_callback': callback
			});
			return false;
		}
		document.getElementById('whatsappOpen').onclick = function(e) {
			e.preventDefault();
			document.getElementById('whatsappPopup').style.display = 'flex';
		};
		document.getElementById('whatsappClose').onclick = function() {
			document.getElementById('whatsappPopup').style.display = 'none';
		};
		window.onclick = function(event) {
			var popup = document.getElementById('whatsappPopup');
			if (event.target === popup) {
				popup.style.display = 'none';
			}
		};
		</script>

	</body>
</html>
