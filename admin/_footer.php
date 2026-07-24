<?php
// Rodapé compartilhado do painel admin (tema AdminKit). Fecha os containers
// abertos em _header.php. Scripts específicos da página vêm ANTES deste include.
?>
            </div>
        </main>

        <footer class="footer">
            <div class="container-fluid">
                <div class="row text-muted">
                    <div class="col-6 text-start">
                        <span class="text-muted">Arlete Vieira Confeitaria</span>
                    </div>
                    <div class="col-6 text-end">
                        <span class="text-muted">Painel administrativo</span>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@adminkit/core@3/dist/js/app.js"></script>
<script>if (window.feather) { feather.replace(); }</script>
<script>
// Loading automático: ao enviar qualquer formulário POST do admin, o botão de
// submit vira um spinner e trava, evitando clique duplo / duplicidade.
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || form.method.toLowerCase() !== 'post' || form.dataset.noLoading === '1') { return; }
    const btn = form.querySelector('button[type="submit"], input[type="submit"]');
    if (!btn || btn.disabled) { return; }
    btn.dataset.htmlOriginal = btn.innerHTML;
    btn.classList.add('is-loading');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enviando…';
}, true);
</script>
</body>
</html>
