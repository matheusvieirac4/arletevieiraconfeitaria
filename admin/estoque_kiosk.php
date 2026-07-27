<?php
// Quiosque de baixa de estoque — página full-screen para o celular na porta.
// Fluxo: escolhe o nome -> digita o PIN -> câmera lê o código -> confirma a
// quantidade -> sucesso -> volta para a lista de nomes. Cada baixa fica
// atribuída ao colaborador. ZXing lê código de barras EAN e QR.
require_once __DIR__ . '/_session.php';
require_once 'model_estoque.php';
// Acesso: admin logado OU token do quiosque (link/cookie). Sem nenhum, vai pro login.
if (!estoque_kiosk_autorizado()) { header('Location: login.php'); exit; }
if (!estoque_pronto($pdo)) { header('Location: estoque.php'); exit; }
// Admin logado vê o botão "Sair do quiosque"; aparelho por token não (não há
// pra onde sair — o estoque.php exigiria login).
$kioskAdmin = !empty($_SESSION['admin_blog']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Baixa de estoque</title>
<!-- PWA: permite "Adicionar à tela inicial" e abrir em tela cheia (sem barra do Chrome). -->
<link rel="manifest" href="estoque_kiosk_manifest.php">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#14171c">
<link rel="apple-touch-icon" href="../img/apple-touch-icon.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
    html, body { height: 100%; margin: 0; background: #14171c; color: #fff; overflow: hidden; }
    body { font-family: system-ui, sans-serif; }
    .kx-top { position: fixed; top: 0; left: 0; right: 0; z-index: 20; display: flex; justify-content: center;
              align-items: center; padding: 12px 16px; background: rgba(0,0,0,.45); }
    .kx-top h1 { font-size: 1.05rem; margin: 0; font-weight: 600; }
    #video { position: fixed; inset: 0; width: 100vw; height: 100vh; object-fit: cover; background:#000; }
    .kx-hint { position: fixed; bottom: 24px; left: 0; right: 0; text-align: center; z-index: 15;
               font-size: 1.1rem; color: #dfe3e8; text-shadow: 0 1px 4px #000; }
    .kx-overlay { position: fixed; inset: 0; z-index: 30; background: rgba(10,12,16,.97);
                  display: none; flex-direction: column; align-items: center; justify-content: center; padding: 20px; overflow:auto; }
    .kx-overlay.show { display: flex; }
    .kx-card { width: 100%; max-width: 560px; text-align: center; }
    .kx-foto { width: 190px; height: 190px; border-radius: 20px; object-fit: cover; background: #232830;
               display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;
               font-size: 4rem; color: #6c757d; }
    .kx-nome { font-size: 2rem; font-weight: 700; line-height: 1.15; margin-bottom: 6px; }
    .kx-saldo { color: #adb5bd; font-size: 1.1rem; margin-bottom: 22px; }
    .kx-qtd { display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 26px; }
    .kx-qtd button { width: 68px; height: 68px; border-radius: 50%; border: none; font-size: 2rem; font-weight: 700; background: #2a3038; color: #fff; }
    .kx-qtd input { width: 130px; height: 74px; text-align: center; font-size: 2.4rem; font-weight: 700; border-radius: 14px; border: 2px solid #3a424c; background: #1b1f26; color: #fff; }
    .btn-kx { font-size: 1.4rem; font-weight: 700; padding: 18px; border-radius: 16px; width: 100%; }
    .kx-check { font-size: 5rem; }
    .kx-grade { width: 100%; max-width: 620px; }
    .kx-grade .item { background: #1b1f26; border: 1px solid #2a3038; border-radius: 14px; padding: 14px; text-align: left; width: 100%; color: #fff; font-size: 1.15rem; margin-bottom: 10px; }
    .kx-busca { font-size: 1.3rem; padding: 16px; border-radius: 14px; }
    /* lista de nomes */
    .kx-nomes { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .kx-nomes button { background: #1b1f26; border: 2px solid #2a3038; color: #fff; border-radius: 16px;
                       padding: 26px 12px; font-size: 1.5rem; font-weight: 700; }
    /* teclado de PIN */
    .pin-dots { font-size: 2.6rem; letter-spacing: .4rem; margin-bottom: 20px; height: 40px; }
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; max-width: 340px; margin: 0 auto; }
    .pin-pad button { background: #1b1f26; border: 2px solid #2a3038; color: #fff; border-radius: 16px; padding: 22px 0; font-size: 2rem; font-weight: 700; }
    .shake { animation: shake .35s; }
    @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-10px)} 75%{transform:translateX(10px)} }
</style>
</head>
<body>
<script>
// Tela cheia de verdade no primeiro toque (Android/Chrome esconde a barra de
// endereço). iOS só entra em tela cheia via "Adicionar à tela inicial" (manifest).
(function () {
    function goFull() {
        var el = document.documentElement;
        var req = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen;
        var jaEsta = document.fullscreenElement || document.webkitFullscreenElement;
        if (req && !jaEsta) { try { req.call(el); } catch (e) {} }
        document.removeEventListener('touchend', goFull, true);
        document.removeEventListener('click', goFull, true);
    }
    document.addEventListener('touchend', goFull, true);
    document.addEventListener('click', goFull, true);
})();

// Mantém a TELA LIGADA (Screen Wake Lock) — ignora o timeout do Android enquanto
// o quiosque está aberto. Requer HTTPS (produção). Reaquire ao voltar pra tela.
(function () {
    var lock = null;
    async function segurar() {
        try {
            if ('wakeLock' in navigator && document.visibilityState === 'visible') {
                lock = await navigator.wakeLock.request('screen');
            }
        } catch (e) { /* sem suporte / negado: ignora */ }
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') { segurar(); }
    });
    document.addEventListener('touchend', segurar, false);   // reforça em gesto
    segurar();
})();
</script>

<div class="kx-top">
    <div class="d-flex gap-2">
        <button class="btn btn-light btn-sm" id="btn-buscar">🔍 Buscar item</button>
        <button class="btn btn-outline-light btn-sm" id="btn-cam">↺ Câmera</button>
        <button class="btn btn-outline-light btn-sm" id="btn-trocar">Trocar usuário</button>
    </div>
</div>

<video id="video" playsinline autoplay muted></video>
<div class="kx-hint" id="hint">Aponte o código de barras para a câmera</div>

<!-- Passo 1: escolher o colaborador -->
<div class="kx-overlay show" id="ov-nomes">
    <div class="kx-card kx-grade">
        <div class="kx-nome mb-3">Quem está retirando?</div>
        <div id="nomes-lista" class="kx-nomes"></div>
        <div id="nomes-vazio" class="kx-saldo d-none">Nenhum colaborador cadastrado. Cadastre em Estoque → Colaboradores.</div>
        <?php if ($kioskAdmin): ?>
        <a href="estoque.php" class="btn btn-outline-light btn-kx mt-3">Sair do quiosque</a>
        <?php endif; ?>
    </div>
</div>

<!-- Passo 2: PIN -->
<div class="kx-overlay" id="ov-pin">
    <div class="kx-card">
        <div class="kx-nome mb-1" id="pin-nome"></div>
        <div class="kx-saldo mb-2">Digite seu PIN de 4 dígitos</div>
        <div class="pin-dots" id="pin-dots">○ ○ ○ ○</div>
        <div class="pin-pad" id="pin-pad"></div>
        <button class="btn btn-outline-light btn-kx mt-3" id="pin-voltar" style="max-width:340px;margin:16px auto 0;">Voltar</button>
    </div>
</div>

<!-- Item encontrado (confirmar baixa) -->
<div class="kx-overlay" id="ov-item">
    <div class="kx-card">
        <div class="kx-foto" id="it-foto">📦</div>
        <div class="kx-nome" id="it-nome"></div>
        <div class="kx-saldo" id="it-saldo"></div>
        <div class="kx-qtd">
            <button type="button" id="q-menos">−</button>
            <input type="text" id="q-valor" inputmode="numeric" value="1">
            <button type="button" id="q-mais">+</button>
        </div>
        <button class="btn btn-success btn-kx mb-3" id="btn-confirmar">Confirmar baixa</button>
        <button class="btn btn-outline-light btn-kx" id="btn-cancelar">Cancelar</button>
    </div>
</div>

<!-- Código desconhecido (associar) -->
<div class="kx-overlay" id="ov-novo">
    <div class="kx-card kx-grade">
        <div class="kx-nome mb-1">Código não cadastrado</div>
        <div class="kx-saldo mb-3">Código <span id="nv-cod" class="text-info"></span> — a qual item pertence?</div>
        <input type="text" class="form-control kx-busca mb-3" id="nv-busca" placeholder="Buscar item pelo nome…">
        <div id="nv-lista"></div>
        <button class="btn btn-outline-light btn-kx mt-2" id="nv-cancelar">Cancelar</button>
    </div>
</div>

<!-- Busca manual (itens sem código) -->
<div class="kx-overlay" id="ov-busca">
    <div class="kx-card kx-grade">
        <div class="kx-nome mb-3">Buscar item</div>
        <input type="text" class="form-control kx-busca mb-3" id="bs-busca" placeholder="Digite o nome do item…">
        <div id="bs-lista"></div>
        <button class="btn btn-outline-light btn-kx mt-2" id="bs-cancelar">Cancelar</button>
    </div>
</div>

<!-- Sucesso -->
<div class="kx-overlay" id="ov-ok">
    <div class="kx-card">
        <div class="kx-check">✅</div>
        <div class="kx-nome mt-2" id="ok-msg"></div>
        <div class="kx-saldo" id="ok-saldo"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.19.1/umd/index.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js"></script>
<script>
(function () {
    const API = 'estoque_api.php';
    const hint = document.getElementById('hint');
    const ov = {
        nomes: document.getElementById('ov-nomes'),
        pin: document.getElementById('ov-pin'),
        item: document.getElementById('ov-item'),
        novo: document.getElementById('ov-novo'),
        busca: document.getElementById('ov-busca'),
        ok: document.getElementById('ov-ok'),
    };
    let leitor = null, facing = 'environment', pausado = true, ultimo = '', ultimoT = 0;
    let itemAtual = null, codigoPendente = '';
    let colaboradorId = null, colaboradorNome = '', pinBuffer = '', pinSel = null;
    let inatividade = null;

    function fmt(n) { return (Math.round(n * 1000) / 1000).toLocaleString('pt-BR'); }
    function mostrar(el) { Object.values(ov).forEach(o => o.classList.remove('show')); if (el) { el.classList.add('show'); } }

    // Volta para a captura (mesmo colaborador) — após cancelar um card.
    function voltarParaScan() { mostrar(null); pausado = false; resetInatividade(); }
    // Volta para a lista de nomes (encerra o colaborador) — após sucesso/timeout.
    function voltarParaNomes() {
        colaboradorId = null; colaboradorNome = ''; clearInatividade();
        pausado = true; mostrar(ov.nomes);
    }
    function resetInatividade() {
        clearInatividade();
        // Segurança: se ninguém interagir por 30s durante a captura, volta aos nomes.
        inatividade = setTimeout(voltarParaNomes, 30000);
    }
    function clearInatividade() { if (inatividade) { clearTimeout(inatividade); inatividade = null; } }

    // ---------- Passo 1: nomes ----------
    function carregarNomes() {
        fetch(API + '?acao=colaboradores', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                const lista = document.getElementById('nomes-lista');
                lista.innerHTML = '';
                const cs = d.colaboradores || [];
                document.getElementById('nomes-vazio').classList.toggle('d-none', cs.length > 0);
                cs.forEach(function (c) {
                    const b = document.createElement('button');
                    b.textContent = c.nome;
                    b.onclick = function () { abrirPin(c.id, c.nome); };
                    lista.appendChild(b);
                });
            });
    }

    // ---------- Passo 2: PIN ----------
    function abrirPin(id, nome) {
        pinSel = id; pinBuffer = '';
        document.getElementById('pin-nome').textContent = nome;
        renderDots();
        mostrar(ov.pin);
    }
    function renderDots() {
        let s = '';
        for (let i = 0; i < 4; i++) { s += (i < pinBuffer.length ? '●' : '○') + ' '; }
        document.getElementById('pin-dots').textContent = s.trim();
    }
    (function montarPad() {
        const pad = document.getElementById('pin-pad');
        const teclas = ['1','2','3','4','5','6','7','8','9','⌫','0',''];
        teclas.forEach(function (t) {
            const b = document.createElement('button');
            b.textContent = t;
            if (t === '') { b.style.visibility = 'hidden'; }
            b.onclick = function () {
                if (t === '⌫') { pinBuffer = pinBuffer.slice(0, -1); renderDots(); return; }
                if (t === '') { return; }
                if (pinBuffer.length >= 4) { return; }
                pinBuffer += t; renderDots();
                if (pinBuffer.length === 4) { verificarPin(); }
            };
            pad.appendChild(b);
        });
    })();
    document.getElementById('pin-voltar').onclick = voltarParaNomes;

    function verificarPin() {
        const fd = new FormData();
        fd.append('colaborador_id', pinSel); fd.append('pin', pinBuffer);
        fetch(API + '?acao=pin', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                if (d.ok) {
                    colaboradorId = pinSel; colaboradorNome = d.nome;
                    voltarParaScan();
                    hint.textContent = colaboradorNome + ' — aponte o código de barras';
                } else {
                    pinBuffer = ''; renderDots();
                    const dots = document.getElementById('pin-dots');
                    dots.classList.add('shake'); setTimeout(() => dots.classList.remove('shake'), 400);
                }
            });
    }

    // ---------- Câmera (ZXing) ----------
    function iniciarCamera() {
        if (typeof ZXing === 'undefined') { hint.textContent = 'Falha ao carregar o leitor. Recarregue.'; return; }
        if (!leitor) {
            // TRY_HARDER + formatos de barras 1D melhoram muito a leitura de EAN.
            const hints = new Map();
            hints.set(ZXing.DecodeHintType.TRY_HARDER, true);
            hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [
                ZXing.BarcodeFormat.EAN_13, ZXing.BarcodeFormat.EAN_8,
                ZXing.BarcodeFormat.UPC_A, ZXing.BarcodeFormat.UPC_E,
                ZXing.BarcodeFormat.CODE_128, ZXing.BarcodeFormat.CODE_39,
                ZXing.BarcodeFormat.ITF, ZXing.BarcodeFormat.QR_CODE,
            ]);
            leitor = new ZXing.BrowserMultiFormatReader(hints);
        }
        try { leitor.reset(); } catch (e) {}
        leitor.decodeFromConstraints(
            { video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 } } },
            document.getElementById('video'),
            function (result) { if (result && !pausado) { onScan(result.getText()); } }
        ).catch(function (e) { hint.textContent = 'Não consegui abrir a câmera: ' + e; });
    }
    document.getElementById('btn-cam').onclick = function () { facing = (facing === 'user') ? 'environment' : 'user'; iniciarCamera(); };
    document.getElementById('btn-trocar').onclick = voltarParaNomes;

    let audioCtx = null;
    function bip() {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const o = audioCtx.createOscillator(), g = audioCtx.createGain();
            o.type = 'square'; o.frequency.value = 880; o.connect(g); g.connect(audioCtx.destination);
            g.gain.setValueAtTime(0.15, audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.15);
            o.start(); o.stop(audioCtx.currentTime + 0.15);
        } catch (e) {}
    }

    // Fluxo único de leitura (usado pelo leitor de barras E pelo OCR).
    function processarLeitura(codigo) {
        if (pausado) { return; }
        const agora = Date.now();
        if (codigo === ultimo && agora - ultimoT < 2500) { return; }
        ultimo = codigo; ultimoT = agora;
        pausado = true; clearInatividade(); bip();
        lookup(codigo);
    }
    function onScan(texto) { processarLeitura(texto); }

    // Valida o dígito verificador de EAN-8/UPC-A/EAN-13/GTIN-14 (padrão GTIN).
    function barcodeValido(code) {
        if (![8, 12, 13, 14].includes(code.length) || !/^\d+$/.test(code)) { return false; }
        const d = code.split('').map(Number);
        const check = d.pop();
        let soma = 0, w = 3;
        for (let i = d.length - 1; i >= 0; i--) { soma += d[i] * w; w = (w === 3 ? 1 : 3); }
        return ((10 - (soma % 10)) % 10) === check;
    }
    function lookup(codigo) {
        fetch(API + '?acao=lookup&codigo=' + encodeURIComponent(codigo), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) { if (d.found) { abrirItem(d.item); } else { abrirNovo(d.codigo || codigo); } })
            .catch(() => voltarParaScan());
    }

    // ---------- OCR (Tesseract) como 2ª via ----------
    // Lê os DÍGITOS impressos sob o código de barras. Aceita item novo também;
    // a proteção contra leitura torta é o dígito verificador (barcodeValido).
    let ocrWorker = null, ocrBusy = false;
    async function iniciarOcr() {
        if (typeof Tesseract === 'undefined') { return; }
        try {
            ocrWorker = await Tesseract.createWorker('eng');
            await ocrWorker.setParameters({ tessedit_char_whitelist: '0123456789' });
            setInterval(tickOcr, 1500);
        } catch (e) {}
    }
    async function tickOcr() {
        const v = document.getElementById('video');
        if (!ocrWorker || ocrBusy || pausado || !colaboradorId || !v.videoWidth) { return; }
        ocrBusy = true;
        try {
            // Recorta a faixa central (onde a pessoa aponta) — mais rápido e preciso.
            const cw = v.videoWidth, ch = v.videoHeight;
            const rw = Math.floor(cw * 0.8), rh = Math.floor(ch * 0.4);
            const rx = Math.floor((cw - rw) / 2), ry = Math.floor((ch - rh) / 2);
            const cv = document.createElement('canvas'); cv.width = rw; cv.height = rh;
            cv.getContext('2d').drawImage(v, rx, ry, rw, rh, 0, 0, rw, rh);
            const res = await ocrWorker.recognize(cv);
            const runs = (res.data.text.match(/\d{8,14}/g) || []);
            for (const run of runs) {
                if (!pausado && barcodeValido(run)) { processarLeitura(run); break; }
            }
        } catch (e) {}
        ocrBusy = false;
    }

    // ---------- Card do item ----------
    function abrirItem(item) {
        itemAtual = item;
        const foto = document.getElementById('it-foto');
        if (item.imagem) { foto.innerHTML = '<img src="' + item.imagem + '" style="width:100%;height:100%;object-fit:cover;border-radius:20px;">'; }
        else { foto.textContent = '📦'; }
        document.getElementById('it-nome').textContent = item.nome;
        document.getElementById('it-saldo').textContent = 'Saldo atual: ' + fmt(item.saldo);
        document.getElementById('q-valor').value = '1';
        mostrar(ov.item);
    }
    function passo(d) { const el = document.getElementById('q-valor'); let v = parseInt(el.value, 10) || 0; v += d; if (v < 1) { v = 1; } el.value = v; }
    document.getElementById('q-menos').onclick = () => passo(-1);
    document.getElementById('q-mais').onclick = () => passo(1);
    document.getElementById('btn-cancelar').onclick = voltarParaScan;

    document.getElementById('btn-confirmar').onclick = function () {
        if (!itemAtual) { return; }
        const qtd = parseInt(document.getElementById('q-valor').value, 10) || 1;
        const fd = new FormData();
        fd.append('item_id', itemAtual.id); fd.append('quantidade', qtd); fd.append('colaborador_id', colaboradorId || '');
        this.disabled = true;
        fetch(API + '?acao=baixa', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                document.getElementById('btn-confirmar').disabled = false;
                if (d.error) { alert(d.error); return; }
                document.getElementById('ok-msg').textContent = '−' + qtd + ' · ' + itemAtual.nome;
                document.getElementById('ok-saldo').textContent = 'Novo saldo: ' + fmt(d.saldo);
                mostrar(ov.ok);
                setTimeout(voltarParaNomes, 1600);
            })
            .catch(function () { document.getElementById('btn-confirmar').disabled = false; alert('Falha ao dar baixa.'); });
    };

    // ---------- Código desconhecido ----------
    function abrirNovo(codigo) {
        codigoPendente = codigo;
        document.getElementById('nv-cod').textContent = codigo;
        document.getElementById('nv-busca').value = '';
        document.getElementById('nv-lista').innerHTML = '';
        mostrar(ov.novo);
        document.getElementById('nv-busca').focus();
    }
    document.getElementById('nv-cancelar').onclick = voltarParaScan;
    ligarBusca('nv-busca', 'nv-lista', function (item) {
        const fd = new FormData();
        fd.append('item_id', item.id); fd.append('codigo', codigoPendente);
        fetch(API + '?acao=associar', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) { if (d.error) { alert(d.error); return; } abrirItem(d.item); });
    });

    // ---------- Busca manual ----------
    document.getElementById('btn-buscar').onclick = function () {
        if (!colaboradorId) { return; }
        pausado = true; clearInatividade();
        document.getElementById('bs-busca').value = '';
        document.getElementById('bs-lista').innerHTML = '';
        mostrar(ov.busca);
        document.getElementById('bs-busca').focus();
    };
    document.getElementById('bs-cancelar').onclick = voltarParaScan;
    ligarBusca('bs-busca', 'bs-lista', abrirItem);

    function ligarBusca(inputId, listaId, onEscolher) {
        const input = document.getElementById(inputId);
        const lista = document.getElementById(listaId);
        let t = null;
        input.addEventListener('input', function () {
            clearTimeout(t);
            const q = input.value.trim();
            t = setTimeout(function () {
                fetch(API + '?acao=buscar&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(function (d) {
                        lista.innerHTML = '';
                        (d.itens || []).forEach(function (it) {
                            const b = document.createElement('button');
                            b.className = 'item';
                            b.textContent = it.nome + '  ·  saldo ' + fmt(it.saldo);
                            b.onclick = function () { onEscolher(it); };
                            lista.appendChild(b);
                        });
                        if (!(d.itens || []).length) { lista.innerHTML = '<div class="text-muted p-2">Nada encontrado.</div>'; }
                    });
            }, 250);
        });
    }

    carregarNomes();
    iniciarCamera();
    iniciarOcr();
})();
</script>
</body>
</html>
