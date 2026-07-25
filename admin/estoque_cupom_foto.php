<?php
// Captura de foto do cupom com a câmera ao vivo (não abre seletor de arquivo).
// Tira a foto -> envia para entrada_cupom -> cai na tela de revisão.
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';
if (!estoque_pronto($pdo)) { header('Location: estoque.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Foto do cupom</title>
<style>
    html, body { height:100%; margin:0; background:#000; color:#fff; overflow:hidden; font-family:system-ui,sans-serif; }
    #video, #canvas { position:fixed; inset:0; width:100vw; height:100vh; object-fit:contain; background:#000; }
    #canvas { display:none; }
    .barra { position:fixed; left:0; right:0; bottom:0; z-index:10; display:flex; gap:12px; justify-content:center;
             padding:20px; background:linear-gradient(transparent, rgba(0,0,0,.7)); }
    .barra button, .barra a { font-size:1.2rem; font-weight:700; padding:16px 22px; border-radius:14px; border:none; text-decoration:none; }
    .b-cap { width:80px; height:80px; border-radius:50%; background:#fff; border:5px solid #b9c0c8; }
    .b-ok { background:#28a745; color:#fff; }
    .b-sec { background:#2a3038; color:#fff; }
    .topo { position:fixed; top:0; left:0; right:0; z-index:10; padding:14px 16px; background:rgba(0,0,0,.5);
            display:flex; justify-content:space-between; align-items:center; }
    .topo h1 { font-size:1.05rem; margin:0; }
    #status { position:fixed; inset:0; z-index:20; display:none; align-items:center; justify-content:center;
              background:rgba(0,0,0,.85); font-size:1.3rem; text-align:center; padding:24px; }
</style>
</head>
<body>
<div class="topo">
    <h1>Foto do cupom</h1>
    <a href="estoque.php" class="b-sec" style="padding:8px 14px;border-radius:10px;font-weight:600;">Sair</a>
</div>

<video id="video" playsinline autoplay muted></video>
<canvas id="canvas"></canvas>

<div class="barra" id="barra-live">
    <button class="b-cap" id="btn-capturar" title="Tirar foto"></button>
</div>
<div class="barra" id="barra-preview" style="display:none;">
    <button class="b-sec" id="btn-refazer">↺ Refazer</button>
    <button class="b-ok" id="btn-usar">Usar esta foto</button>
</div>

<div id="status">Lendo o cupom com a IA… aguarde</div>

<form id="form-cupom" method="post" action="controller_estoque.php?acao=entrada_cupom" enctype="multipart/form-data" style="display:none;">
    <input type="file" name="foto" id="foto">
</form>

<script>
(function () {
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    let stream = null;

    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
        .then(function (s) { stream = s; video.srcObject = s; })
        .catch(function (e) { document.querySelector('.topo h1').textContent = 'Câmera indisponível: ' + e; });

    document.getElementById('btn-capturar').onclick = function () {
        const w = video.videoWidth, h = video.videoHeight;
        if (!w) { return; }
        canvas.width = w; canvas.height = h;
        canvas.getContext('2d').drawImage(video, 0, 0, w, h);
        video.style.display = 'none'; canvas.style.display = 'block';
        document.getElementById('barra-live').style.display = 'none';
        document.getElementById('barra-preview').style.display = 'flex';
    };
    document.getElementById('btn-refazer').onclick = function () {
        canvas.style.display = 'none'; video.style.display = 'block';
        document.getElementById('barra-preview').style.display = 'none';
        document.getElementById('barra-live').style.display = 'flex';
    };
    document.getElementById('btn-usar').onclick = function () {
        document.getElementById('status').style.display = 'flex';
        canvas.toBlob(function (blob) {
            const file = new File([blob], 'cupom.jpg', { type: 'image/jpeg' });
            const dt = new DataTransfer(); dt.items.add(file);
            document.getElementById('foto').files = dt.files;
            if (stream) { stream.getTracks().forEach(t => t.stop()); }
            document.getElementById('form-cupom').submit();
        }, 'image/jpeg', 0.9);
    };
})();
</script>
</body>
</html>
