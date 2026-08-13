<?php
// Captura em FILA: abre a câmera direto e vai empilhando fotos de cupom/nota na
// caixa de entrada do Financeiro, sem esperar a IA. Você fotografa várias no
// corre e valida tudo junto depois, no PC (financeiro.php).
//
// É esta URL que vale colocar num atalho na tela inicial do iPhone.
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';

$naFila = count(financeiro_fila_listar());
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Foto pra fila</title>
<style>
    html, body { height:100%; margin:0; background:#000; color:#fff; overflow:hidden; font-family:system-ui,sans-serif; }
    #video, #canvas { position:fixed; inset:0; width:100vw; height:100vh; object-fit:contain; background:#000; }
    #canvas { display:none; }
    .barra { position:fixed; left:0; right:0; bottom:0; z-index:10; display:flex; gap:12px; justify-content:center;
             align-items:center; padding:20px; background:linear-gradient(transparent, rgba(0,0,0,.75)); }
    .barra button, .barra a { font-size:1.15rem; font-weight:700; padding:16px 22px; border-radius:14px; border:none; text-decoration:none; }
    .b-cap { width:80px; height:80px; border-radius:50%; background:#fff; border:5px solid #b9c0c8; }
    .b-cap:active { transform:scale(.94); }
    .b-ok { background:#28a745; color:#fff; }
    .b-sec { background:#2a3038; color:#fff; }
    .topo { position:fixed; top:0; left:0; right:0; z-index:10; padding:14px 16px; background:rgba(0,0,0,.55);
            display:flex; justify-content:space-between; align-items:center; }
    .topo h1 { font-size:1.05rem; margin:0; }
    .contador { position:fixed; top:56px; left:0; right:0; z-index:9; text-align:center; }
    .contador span { display:inline-block; background:rgba(0,0,0,.6); padding:6px 14px; border-radius:999px; font-size:.95rem; }
    #toast { position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); z-index:30; display:none;
             background:rgba(0,0,0,.85); padding:22px 26px; border-radius:16px; font-size:1.2rem; text-align:center; }
    #toast.erro { background:rgba(176,42,42,.92); }
    .spinner { display:inline-block; width:22px; height:22px; border:3px solid rgba(255,255,255,.35);
               border-top-color:#fff; border-radius:50%; animation:girar .8s linear infinite; vertical-align:middle; margin-right:8px; }
    @keyframes girar { to { transform:rotate(360deg); } }
</style>
</head>
<body>
<div class="topo">
    <h1>Foto pra fila</h1>
    <a href="financeiro.php" class="b-sec" style="padding:8px 14px;border-radius:10px;font-weight:600;">Concluir</a>
</div>
<div class="contador"><span id="lbl-fila"><?= $naFila ?> na fila</span></div>

<video id="video" playsinline autoplay muted></video>
<canvas id="canvas"></canvas>

<div class="barra" id="barra-live">
    <button class="b-cap" id="btn-capturar" title="Tirar foto"></button>
</div>
<div class="barra" id="barra-preview" style="display:none;">
    <button class="b-sec" id="btn-refazer">↺ Refazer</button>
    <button class="b-ok" id="btn-usar">Adicionar à fila</button>
</div>

<div id="toast"></div>

<script>
(function () {
    const video   = document.getElementById('video');
    const canvas  = document.getElementById('canvas');
    const toast   = document.getElementById('toast');
    const lblFila = document.getElementById('lbl-fila');
    let stream = null;
    let naFila = <?= (int) $naFila ?>;

    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
        .then(function (s) { stream = s; video.srcObject = s; })
        .catch(function (e) { document.querySelector('.topo h1').textContent = 'Câmera indisponível: ' + e; });

    function mostrarToast(html, erro) {
        toast.className = erro ? 'erro' : '';
        toast.innerHTML = html;
        toast.style.display = 'block';
    }
    function esconderToast() { toast.style.display = 'none'; }

    function voltarLive() {
        canvas.style.display = 'none'; video.style.display = 'block';
        document.getElementById('barra-preview').style.display = 'none';
        document.getElementById('barra-live').style.display = 'flex';
    }

    document.getElementById('btn-capturar').onclick = function () {
        const w = video.videoWidth, h = video.videoHeight;
        if (!w) { return; }
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);
        video.style.display = 'none'; canvas.style.display = 'block';
        document.getElementById('barra-live').style.display = 'none';
        document.getElementById('barra-preview').style.display = 'flex';
    };
    document.getElementById('btn-refazer').onclick = voltarLive;

    document.getElementById('btn-usar').onclick = function () {
        mostrarToast('<span class="spinner"></span>Enviando…', false);
        canvas.toBlob(function (blob) {
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('foto', new File([blob], 'fila.jpg', { type: 'image/jpeg' }));
            fetch('controller_financeiro.php?acao=fila_add', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (j) {
                    if (!j.ok) { throw new Error(j.erro || 'Falha ao enviar.'); }
                    naFila = j.total;
                    lblFila.textContent = naFila + ' na fila';
                    mostrarToast('✓ Adicionada — ' + naFila + ' na fila', false);
                    voltarLive();
                    setTimeout(esconderToast, 900);
                })
                .catch(function (e) {
                    mostrarToast('⚠️ ' + e.message + '<br><small>Toque para tentar de novo</small>', true);
                    toast.onclick = function () { esconderToast(); toast.onclick = null; };
                });
        }, 'image/jpeg', 0.85);
    };
})();
</script>
</body>
</html>
