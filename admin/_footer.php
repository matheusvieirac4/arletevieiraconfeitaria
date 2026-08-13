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

<!-- Confirmação reutilizável do painel (substitui o confirm() nativo). -->
<!-- Uso: em qualquer <a>, ponha class="js-confirm" data-msg="Pergunta?". -->
<button id="confirm-trigger" class="d-none" data-bs-toggle="modal" data-bs-target="#modal-confirm"></button>
<div class="modal fade" id="modal-confirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="confirm-msg"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <a href="#" id="confirm-ok" class="btn btn-danger">Confirmar</a>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    const el = e.target.closest('.js-confirm');
    if (!el) { return; }
    e.preventDefault();
    document.getElementById('confirm-msg').textContent = el.dataset.msg || 'Tem certeza?';
    const ok = document.getElementById('confirm-ok');
    ok.href = el.getAttribute('href') || '#';
    ok.onclick = null;                       // link normal segue o href
    document.getElementById('confirm-trigger').click();
}, true);

// Mesma confirmação, mas para <form class="js-confirm-form" data-msg="...">.
document.addEventListener('submit', function (e) {
    const form = e.target.closest('.js-confirm-form');
    if (!form || form.dataset.confirmado === '1') { return; }
    e.preventDefault();
    document.getElementById('confirm-msg').textContent = form.dataset.msg || 'Tem certeza?';
    const ok = document.getElementById('confirm-ok');
    ok.href = '#';
    ok.onclick = function (ev) {
        ev.preventDefault();
        form.dataset.confirmado = '1';
        form.submit();
    };
    document.getElementById('confirm-trigger').click();
}, true);
</script>

<!-- Toasts (canto superior direito, 3s, entra/sai). Substitui os alerts no corpo. -->
<style>
    #toast-container { position: fixed; top: 16px; right: 16px; z-index: 1080; display: flex; flex-direction: column; gap: 10px; max-width: 360px; }
    .toast-msg { padding: 12px 16px; border-radius: 10px; color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.18);
                 opacity: 0; transform: translateX(115%); transition: opacity .3s ease, transform .35s ease; font-size: .95rem; }
    .toast-msg.show { opacity: 1; transform: translateX(0); }
    .toast-success { background: #2e7d5b; } .toast-danger { background: #c0392b; }
    .toast-warning { background: #b8860b; } .toast-info { background: #2c6fb5; }
</style>
<div id="toast-container"></div>
<script>
function showToast(msg, tipo) {
    const cont = document.getElementById('toast-container');
    if (!cont || !msg) { return; }
    tipo = tipo || 'info';
    const t = document.createElement('div');
    t.className = 'toast-msg toast-' + tipo;
    t.textContent = msg;
    t.style.cursor = 'pointer';
    cont.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    const fechar = function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 400); };
    t.addEventListener('click', fechar);           // clique fecha
    setTimeout(fechar, tipo === 'danger' ? 6000 : 3000);   // erro fica mais tempo
}
</script>
<?php if (!empty($flash) && is_array($flash) && !empty($flash['texto'])): ?>
<script>showToast(<?= json_encode($flash['texto'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($flash['tipo'] ?? 'info') ?>);</script>
<?php endif; ?>

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
