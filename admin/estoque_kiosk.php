<?php
// Quiosque de baixa de estoque — página full-screen para o celular na porta.
// Fluxo: escolhe o nome -> digita o PIN -> câmera lê o código -> confirma a
// quantidade -> sucesso -> volta para a lista de nomes. Cada baixa fica
// atribuída ao colaborador. Leitura: BarcodeDetector nativo (rápido, quando o
// aparelho suporta) com ZXing como reserva. Lê EAN, UPC, CODE-128/39, ITF e QR.
require_once __DIR__ . '/_session.php';
require_once 'model_estoque.php';
// Nunca cachear: o navegador do celular estava servindo JS antigo.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
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
    /* Moldura da faixa que o leitor realmente analisa (ROI) — mire o código aqui. */
    .kx-aim { position: fixed; left: 4%; top: 25%; width: 92%; height: 50%; z-index: 14; pointer-events: none;
              border: 3px solid rgba(255,255,255,.55); border-radius: 16px; box-shadow: 0 0 0 100vmax rgba(0,0,0,.28); }
    .kx-aim::after { content: ''; position: absolute; left: 8%; right: 8%; top: 50%; height: 2px;
                     background: rgba(255,64,64,.8); box-shadow: 0 0 6px rgba(255,64,64,.8); }
    .kx-dbg { position: fixed; top: 8px; left: 8px; z-index: 40; display: none; font: 12px/1.4 monospace;
              background: rgba(0,0,0,.72); color: #fff; padding: 6px 9px; border-radius: 8px; max-width: 72vw; }
    .kx-dbglog { position: fixed; top: 8px; right: 8px; z-index: 41; display: none; font: 12px/1.4 monospace;
              background: rgba(0,0,0,.72); color: #fff; padding: 6px 9px; border-radius: 8px; max-width: 60vw; text-align: right; }
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
    /* Pílula de estado do ponto (dentro/fora) */
    .pt-estado { display:inline-block; padding: 8px 18px; border-radius: 999px; font-size: 1.1rem; font-weight: 700; margin-bottom: 18px; }
    .pt-dentro { background: #14351f; color: #4ade80; border: 1px solid #216c3a; }
    .pt-fora   { background: #2a3038; color: #cbd2da; border: 1px solid #3a424c; }
    /* Stand-by: tela preta por cima de tudo; câmera desligada por baixo. Um toque acorda. */
    .kx-standby { position: fixed; inset: 0; z-index: 60; background: #000; color: #566173;
                  display: none; flex-direction: column; align-items: center; justify-content: center; }
    .kx-standby.show { display: flex; }
    .kx-standby .zzz { font-size: 3rem; opacity: .85; }
    .kx-standby .txt { margin-top: 12px; font-size: 1.05rem; letter-spacing: .02em; }

    /* Alarme de despacho de entrega — acima de TUDO (inclusive stand-by z=60). */
    .kx-alarme { position: fixed; inset: 0; z-index: 70; background: #e74c3c; color: #fff; display: none;
                 flex-direction: column; align-items: center; justify-content: center; text-align: center;
                 padding: 24px; animation: kxpisca 1s steps(1) infinite; }
    .kx-alarme.show { display: flex; }
    @keyframes kxpisca { 50% { background: #b83227; } }
    .kx-alarme .selo { font-size: 1.3rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; opacity: .95; }
    .kx-alarme .cli { font-size: clamp(2.4rem, 9vw, 5rem); font-weight: 900; line-height: 1.05; margin: 10px 0; }
    .kx-alarme .sub { font-size: clamp(1.2rem, 4vw, 2rem); font-weight: 700; }
    .kx-alarme .end { font-size: 1.15rem; opacity: .92; margin-top: 10px; }
    .kx-alarme .parar { margin-top: 40px; font-size: 1.7rem; font-weight: 900; padding: 24px 44px; border-radius: 22px;
                        border: 4px solid #fff; background: rgba(255,255,255,.14); color: #fff; }
    .kx-alarme .fila { margin-top: 16px; font-size: 1rem; opacity: .85; min-height: 1.2em; }
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
    async function soltar() {
        try { if (lock) { await lock.release(); lock = null; } } catch (e) {}
    }
    document.addEventListener('visibilitychange', function () {
        // Em stand-by não reaquire: deixa a tela apagar sozinha (economiza bateria/calor).
        if (document.visibilityState === 'visible' && !window.__standby) { segurar(); }
    });
    document.addEventListener('touchend', function () { if (!window.__standby) { segurar(); } }, false);
    // Expõe pro stand-by soltar/reaquirir a trava de tela.
    window.__wakeSegurar = segurar;
    window.__wakeSoltar = soltar;
    segurar();
})();
</script>

<div class="kx-top">
    <div class="d-flex gap-2">
        <button class="btn btn-light btn-sm" id="btn-buscar">🔍 Buscar item</button>
        <button class="btn btn-outline-light btn-sm" id="btn-trocar">Trocar usuário</button>
    </div>
</div>

<video id="video" playsinline autoplay muted></video>
<div class="kx-aim" id="aim"></div>
<div class="kx-dbg" id="dbg"></div>
<div class="kx-dbglog" id="dbglog"></div>
<div class="kx-hint" id="hint">Aponte o código de barras na faixa central</div>

<!-- Passo 1: escolher o colaborador -->
<div class="kx-overlay show" id="ov-nomes">
    <div class="kx-card kx-grade">
        <div class="d-flex justify-content-center mb-4">
            <button type="button" id="btn-bater-ponto" class="btn btn-outline-light btn-lg">🕐 Bater ponto</button>
        </div>
        <div class="kx-nome mb-3" id="nomes-titulo">Quem está retirando?</div>
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

<!-- Ponto: bater entrada/saída (mesma tela, sem trocar de página) -->
<div class="kx-overlay" id="ov-ponto">
    <div class="kx-card">
        <div class="kx-nome" id="pt-nome"></div>
        <div class="kx-saldo mb-1" id="pt-data"></div>
        <div><span class="pt-estado" id="pt-estado"></span></div>
        <button class="btn btn-kx mb-3" id="pt-acao"></button>
        <button class="btn btn-outline-light btn-kx" id="pt-cancelar">Cancelar</button>
        <div class="kx-saldo mt-3" id="pt-batidas"></div>
    </div>
</div>

<!-- Stand-by: aparece após 1h sem toque (câmera desligada). Toque para começar. -->
<div class="kx-standby" id="ov-standby">
    <div class="zzz">💤</div>
    <div class="txt">Toque para começar</div>
</div>

<!-- Sucesso -->
<div class="kx-overlay" id="ov-ok">
    <div class="kx-card">
        <div class="kx-check" id="ok-icone">✅</div>
        <div class="kx-nome mt-2" id="ok-msg"></div>
        <div class="kx-saldo" id="ok-saldo"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.19.1/umd/index.min.js"></script>
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
        ponto: document.getElementById('ov-ponto'),
        ok: document.getElementById('ov-ok'),
    };
    let facing = 'user', pausado = true, ultimo = '', ultimoT = 0;
    let itemAtual = null, codigoPendente = '';
    let colaboradorId = null, colaboradorNome = '', pinBuffer = '', pinSel = null;
    let inatividade = null;
    // Modo ponto: mesma grade de nomes + PIN, mas bate ponto em vez de dar baixa.
    const PONTO_API = 'ponto_api.php';
    let pontoMode = false, pontoId = null, pontoNome = '', pontoPin = '';
    function hmMin(min) { min = Math.max(0, min | 0); const h = Math.floor(min / 60), m = min % 60; return h + 'h' + (m > 0 ? String(m).padStart(2, '0') : ''); }
    function aplicarModo() {
        document.getElementById('nomes-titulo').textContent = pontoMode ? 'Quem está batendo o ponto?' : 'Quem está retirando?';
        document.getElementById('btn-bater-ponto').textContent = pontoMode ? '📦 Retirar item' : '🕐 Bater ponto';
    }

    function fmt(n) { return String(Math.round(n)); }   // saldo é sempre inteiro
    function mostrar(el) { Object.values(ov).forEach(o => o.classList.remove('show')); if (el) { el.classList.add('show'); } }

    // Volta para a captura (mesmo colaborador) — após cancelar um card.
    // Liga a câmera só agora (fica desligada na lista de nomes e no modo ponto).
    function voltarParaScan() {
        mostrar(null);
        if (!camStream) { iniciarCamera(); }
        pausado = false; _buscaT0 = performance.now(); resetInatividade();
    }
    // Volta para a lista de nomes (encerra o colaborador) — após sucesso/timeout.
    // Sempre volta ao modo "retirada" (o padrão da tela inicial). Desliga a câmera.
    function voltarParaNomes() {
        colaboradorId = null; colaboradorNome = ''; clearInatividade();
        pontoMode = false; pontoId = null; pontoNome = ''; pontoPin = ''; aplicarModo();
        pausado = true; pararCamera(); mostrar(ov.nomes);
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
    // Voltar do PIN: preserva o modo atual (ponto ou retirada), sem sair dele.
    document.getElementById('pin-voltar').onclick = function () { pausado = true; mostrar(ov.nomes); };

    // Alterna entre "retirar item" e "bater ponto" na própria tela inicial.
    document.getElementById('btn-bater-ponto').onclick = function () {
        pontoMode = !pontoMode; aplicarModo();
    };

    function pinErrado() {
        pinBuffer = ''; renderDots();
        const dots = document.getElementById('pin-dots');
        dots.classList.add('shake'); setTimeout(() => dots.classList.remove('shake'), 400);
    }

    function verificarPin() {
        if (pontoMode) { verificarPinPonto(); return; }
        const fd = new FormData();
        fd.append('colaborador_id', pinSel); fd.append('pin', pinBuffer);
        fetch(API + '?acao=pin', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                if (d.ok) {
                    colaboradorId = pinSel; colaboradorNome = d.nome;
                    voltarParaScan();
                    hint.textContent = colaboradorNome + ' — aponte o código de barras';
                } else { pinErrado(); }
            });
    }

    // ---------- Ponto: PIN + bater (sem trocar de página) ----------
    function verificarPinPonto() {
        const fd = new FormData();
        fd.append('colaborador_id', pinSel); fd.append('pin', pinBuffer);
        fetch(PONTO_API + '?acao=pin', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                if (d && d.ok) { pontoId = pinSel; pontoNome = d.nome; pontoPin = pinBuffer; abrirPonto(d.status); }
                else { pinErrado(); }
            })
            .catch(function () { pinErrado(); });
    }
    function abrirPonto(status) {
        document.getElementById('pt-nome').textContent = pontoNome;
        document.getElementById('pt-data').textContent =
            new Date().toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
        const est = document.getElementById('pt-estado');
        const acao = document.getElementById('pt-acao');
        if (status.dentro) {
            est.className = 'pt-estado pt-dentro';
            est.textContent = '● Dentro desde ' + (status.desde || '--:--') + ' · ' + hmMin(status.trabalhado_min);
            acao.className = 'btn btn-danger btn-kx mb-3'; acao.textContent = '⏸ Registrar SAÍDA';
        } else {
            est.className = 'pt-estado pt-fora';
            est.textContent = status.trabalhado_min > 0 ? ('Fora · hoje ' + hmMin(status.trabalhado_min)) : 'Fora';
            acao.className = 'btn btn-success btn-kx mb-3'; acao.textContent = '▶ Registrar ENTRADA';
        }
        const bt = (status.batidas || []).map(function (b) { return (b.tipo === 'entrada' ? '▶ ' : '⏸ ') + b.hora; }).join('   ');
        document.getElementById('pt-batidas').textContent = bt ? ('Hoje: ' + bt) : '';
        mostrar(ov.ponto);
    }
    document.getElementById('pt-cancelar').onclick = function () { pausado = true; mostrar(ov.nomes); };
    document.getElementById('pt-acao').onclick = function () {
        this.disabled = true;
        const fd = new FormData();
        fd.append('colaborador_id', pontoId); fd.append('pin', pontoPin);
        fetch(PONTO_API + '?acao=bater', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                document.getElementById('pt-acao').disabled = false;
                if (d.error) { alert(d.error); return; }
                const entrada = d.tipo === 'entrada';
                if (d.duplicado) {
                    document.getElementById('ok-icone').textContent = '⏱️';
                    document.getElementById('ok-msg').textContent = 'Você já bateu agora há pouco';
                    document.getElementById('ok-saldo').textContent = 'Última: ' + (entrada ? 'ENTRADA' : 'SAÍDA') + ' ' + d.hora;
                } else {
                    document.getElementById('ok-icone').textContent = entrada ? '👋' : '✅';
                    document.getElementById('ok-msg').textContent = pontoNome + ' — ' + (entrada ? 'ENTRADA' : 'SAÍDA') + ' ' + d.hora;
                    document.getElementById('ok-saldo').textContent = d.esqueceu
                        ? '⚠ Você não bateu a saída no último dia — avise o gestor.'
                        : (entrada ? 'Bom trabalho!' : ('Trabalhado hoje: ' + hmMin(d.status.trabalhado_min)));
                }
                mostrar(ov.ok);
                clearInatividade();
                inatividade = setTimeout(voltarParaNomes, d.esqueceu ? 3800 : 2000);
            })
            .catch(function () { document.getElementById('pt-acao').disabled = false; alert('Falha ao bater ponto.'); });
    };

    // ---------- Câmera + leitor de barras (ROI rápido) ----------
    // Prioridade 1: BarcodeDetector NATIVO do navegador (Android Chrome). Usa o
    // motor de barras do próprio sistema — muito mais rápido e preciso que JS.
    // Prioridade 2 (reserva): ZXing recortando a FAIXA CENTRAL (ROI) ~16x/seg.
    // Removemos o OCR (Tesseract): os números impressos ao redor do código de
    // barras faziam ele "ler" dígitos a mais/a menos e gerar códigos errados.
    let mfReader = null, camStream = null, scanToken = 0, BARCODE_HINTS = null;
    let nativeDetector = null;   // BarcodeDetector nativo, quando disponível
    // Confirmação: só aceita um código depois de vê-lo em 2 quadros seguidos.
    // Barato (~1 quadro) e elimina a leitura solta/errada de um quadro ruim.
    let _cand = '', _candN = 0;
    const FORMATOS = ['ean_13','ean_8','upc_a','upc_e','code_128','code_39','itf','qr_code'];
    async function iniciarDetectorNativo() {
        try {
            if (!('BarcodeDetector' in window)) { return; }
            let sup = FORMATOS;
            try {
                const disp = await window.BarcodeDetector.getSupportedFormats();
                sup = FORMATOS.filter(f => disp.includes(f));
            } catch (e) {}
            if (!sup.length) { return; }
            nativeDetector = new window.BarcodeDetector({ formats: sup });
        } catch (e) { nativeDetector = null; }
    }
    // Confirma o código em 2 quadros seguidos antes de disparar o fluxo.
    function confirmar(codigo, modo, fmt, ms) {
        if (codigo === _cand) { _candN++; }
        else { _cand = codigo; _candN = 1; }
        if (_candN < 2) { return; }
        _cand = ''; _candN = 0;
        logScan(codigo, modo, fmt, ms);
        onScan(codigo);
    }
    const _roi = document.createElement('canvas');
    const _roiCtx = _roi.getContext('2d', { willReadFrequently: true });
    const _flip = document.createElement('canvas');
    const _flipCtx = _flip.getContext('2d', { willReadFrequently: true });
    let _camInfo = '';
    // Diagnóstico: abra o quiosque com ?debug=1 para ver formato + tempo de leitura.
    // Acrescente &log=1 para gravar cada leitura em admin/data/kiosk_debug.log.
    const _debug = /[?&]debug=1/.test(location.search);
    const _logOn = /[?&]log=1/.test(location.search);
    let _buscaT0 = performance.now();   // início da busca atual (mede o "tempão")
    let _logN = 0;                      // quantos POSTs de log já saíram (feedback na tela)
    function logScan(codigo, modo, fmt, ms) {
        if (!_logOn) { return; }
        const busca = Math.round(performance.now() - _buscaT0);
        try {
            fetch('estoque_kiosk_log.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ codigo: codigo, modo: modo, formato: fmt, ms: Math.round(ms), busca: busca, cam: _camInfo }),
                keepalive: true
            }).then(function (r) {
                _logN++;
                const el = document.getElementById('dbglog');
                if (el) { el.style.display = 'block'; el.innerHTML = '<span style="color:' + (r.ok ? '#3fd07a' : '#ff6b6b') + '">log HTTP ' + r.status + '</span> · gravados: ' + _logN; }
            }).catch(function (e) {
                const el = document.getElementById('dbglog');
                if (el) { el.style.display = 'block'; el.innerHTML = '<span style="color:#ff6b6b">log ERRO ' + e + '</span>'; }
            });
        } catch (e) {}
    }
    let _tick = 0, _dbgAtt = 0, _dbgFps = 0, _dbgFpsT = 0;
    function formatoNome(f) {
        try { for (const k in ZXing.BarcodeFormat) { if (ZXing.BarcodeFormat[k] === f) { return k; } } } catch (e) {}
        return '?';
    }
    function dbgUpdate(modo, result, ms) {
        const el = document.getElementById('dbg'); if (!el) { return; }
        _dbgAtt++;
        const now = performance.now();
        if (now - _dbgFpsT > 1000) { _dbgFps = _dbgAtt; _dbgAtt = 0; _dbgFpsT = now; }
        const cam = _camInfo ? '<br><span style="opacity:.7">📷 ' + _camInfo + '</span>' : '';
        if (result) {
            const fmt = result.getBarcodeFormat ? formatoNome(result.getBarcodeFormat()) : '?';
            el.innerHTML = '<b style="color:#3fd07a">LEU</b> ' + modo + ' · ' + fmt + ' · ' + Math.round(ms) + 'ms · ' + _dbgFps + '/s' + cam;
        } else {
            el.innerHTML = modo + ' · ' + Math.round(ms) + 'ms · ' + _dbgFps + '/s (procurando…)' + cam;
        }
    }

    function montarHints() {
        const h = new Map();
        h.set(ZXing.DecodeHintType.TRY_HARDER, true);
        h.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [
            ZXing.BarcodeFormat.EAN_13, ZXing.BarcodeFormat.EAN_8,
            ZXing.BarcodeFormat.UPC_A, ZXing.BarcodeFormat.UPC_E,
            ZXing.BarcodeFormat.CODE_128, ZXing.BarcodeFormat.CODE_39,
            ZXing.BarcodeFormat.ITF, ZXing.BarcodeFormat.QR_CODE,
        ]);
        return h;
    }

    let legacyReader = null;
    function roiSuportado() {
        return typeof ZXing.MultiFormatReader !== 'undefined'
            && typeof ZXing.HTMLCanvasElementLuminanceSource !== 'undefined'
            && typeof ZXing.BinaryBitmap !== 'undefined'
            && typeof ZXing.HybridBinarizer !== 'undefined';
    }
    function iniciarCameraLegacy() {
        // Fallback: decodifica o quadro inteiro (método antigo do ZXing).
        if (!legacyReader) { legacyReader = new ZXing.BrowserMultiFormatReader(montarHints()); }
        try { legacyReader.reset(); } catch (e) {}
        legacyReader.decodeFromConstraints(
            { video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 } } },
            document.getElementById('video'),
            function (result) { if (result && !pausado) { onScan(result.getText()); } }
        ).catch(function (e) { hint.textContent = 'Não consegui abrir a câmera: ' + e; });
    }

    async function iniciarCamera() {
        await iniciarDetectorNativo();
        const temZXing = (typeof ZXing !== 'undefined');
        // Sem detector nativo E sem ZXing não há como ler.
        if (!nativeDetector && !temZXing) { hint.textContent = 'Falha ao carregar o leitor. Recarregue.'; return; }
        // Só cai no ZXing "quadro inteiro" se não houver nativo nem ROI.
        if (!nativeDetector && !roiSuportado()) { iniciarCameraLegacy(); return; }
        if (temZXing && !mfReader) { mfReader = new ZXing.MultiFormatReader(); BARCODE_HINTS = montarHints(); }
        const v = document.getElementById('video');
        try {
            if (camStream) { camStream.getTracks().forEach(t => t.stop()); }
            // Resolução alta ajuda muito o código pequeno da câmera FRONTAL (que
            // costuma ser pior que a traseira). Pedimos 1080p e caímos pro que der.
            camStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: facing,
                    width: { ideal: 1920 }, height: { ideal: 1080 },
                    frameRate: { ideal: 30 },
                    advanced: [{ focusMode: 'continuous' }]
                }
            });
            v.srcObject = camStream;
            await v.play();
            try {
                const st = camStream.getVideoTracks()[0].getSettings();
                _camInfo = (st.facingMode || facing) + ' ' + (st.width || '?') + '×' + (st.height || '?')
                         + (nativeDetector ? ' · nativo' : ' · zxing');
            } catch (e) { _camInfo = facing; }
            scanToken++;
            scanLoop(scanToken);
        } catch (e) {
            hint.textContent = 'Não consegui abrir a câmera: ' + e;
        }
    }

    function decodificar(canvas) {
        const src = new ZXing.HTMLCanvasElementLuminanceSource(canvas);
        const bmp = new ZXing.BinaryBitmap(new ZXing.HybridBinarizer(src));
        return mfReader.decode(bmp, BARCODE_HINTS);   // lança se não achar
    }
    // Debug do caminho nativo (código já vem como texto + formato).
    function dbgUpdateNativo(codigo, fmt, ms) {
        const el = document.getElementById('dbg'); if (!el) { return; }
        _dbgAtt++;
        const now = performance.now();
        if (now - _dbgFpsT > 1000) { _dbgFps = _dbgAtt; _dbgAtt = 0; _dbgFpsT = now; }
        const cam = _camInfo ? '<br><span style="opacity:.7">📷 ' + _camInfo + '</span>' : '';
        if (codigo) {
            el.innerHTML = '<b style="color:#3fd07a">LEU</b> nativo · ' + fmt + ' · ' + Math.round(ms) + 'ms · ' + _dbgFps + '/s' + cam;
        } else {
            el.innerHTML = 'nativo · ' + Math.round(ms) + 'ms · ' + _dbgFps + '/s (procurando…)' + cam;
        }
    }

    async function scanLoop(token) {
        if (token !== scanToken) { return; }   // loop antigo morre ao trocar câmera
        // Blindado: qualquer erro num quadro é ignorado; o loop SEMPRE reagenda.
        try {
            const v = document.getElementById('video');
            if (!pausado && v && v.videoWidth) {
                _tick++;
                // Caminho rápido: detector nativo lê o quadro inteiro (pega até
                // código fora do centro). Sem recorte/OCR, sem código inventado.
                if (nativeDetector) {
                    const t0 = performance.now();
                    let codes = [];
                    try { codes = await nativeDetector.detect(v); } catch (e) { codes = []; }
                    const ms = performance.now() - t0;
                    const hit = (codes && codes.length) ? codes[0] : null;
                    if (_debug) { dbgUpdateNativo(hit ? hit.rawValue : '', hit ? (hit.format || '?') : '', ms); }
                    if (hit && hit.rawValue && !pausado) { confirmar(String(hit.rawValue), 'nativo', hit.format || '?', ms); }
                    if (token === scanToken) { setTimeout(function () { scanLoop(token); }, 40); }
                    return;
                }
                const cw = v.videoWidth, ch = v.videoHeight;
                let modo;
                // 2 de cada 3 quadros: FAIXA CENTRAL (rápido, códigos pequenos).
                // 1 de cada 3: QUADRO INTEIRO reduzido (pega código grande/perto que
                // estoura a faixa, e código fora do centro).
                if (_tick % 3 === 0) {
                    modo = 'inteiro';
                    const s = Math.min(1, 1100 / cw);
                    const fw = Math.round(cw * s), fh = Math.round(ch * s);
                    if (_roi.width !== fw || _roi.height !== fh) { _roi.width = fw; _roi.height = fh; }
                    _roiCtx.drawImage(v, 0, 0, cw, ch, 0, 0, fw, fh);
                } else {
                    modo = 'faixa';
                    const rw = Math.floor(cw * 0.92), rh = Math.floor(ch * 0.5);
                    const rx = Math.floor((cw - rw) / 2), ry = Math.floor((ch - rh) / 2);
                    if (_roi.width !== rw || _roi.height !== rh) { _roi.width = rw; _roi.height = rh; }
                    _roiCtx.drawImage(v, rx, ry, rw, rh, 0, 0, rw, rh);
                }
                const t0 = performance.now();
                let result = null;
                try { result = decodificar(_roi); } catch (e) { /* nada neste quadro */ }
                // Fallback anti-espelho: no quadro inteiro, se não leu, tenta a
                // imagem espelhada horizontalmente (descarta a hipótese de que a
                // câmera frontal espelhada estaria atrapalhando).
                if (!result && modo === 'inteiro') {
                    const fw = _roi.width, fh = _roi.height;
                    if (_flip.width !== fw || _flip.height !== fh) { _flip.width = fw; _flip.height = fh; }
                    _flipCtx.setTransform(-1, 0, 0, 1, fw, 0);
                    _flipCtx.drawImage(_roi, 0, 0);
                    _flipCtx.setTransform(1, 0, 0, 1, 0, 0);
                    try { result = decodificar(_flip); if (result) { modo = 'inteiro-espelhado'; } } catch (e) {}
                }
                const _ms = performance.now() - t0;
                if (_debug) { dbgUpdate(modo, result, _ms); }
                if (result) {
                    const _fmt = result.getBarcodeFormat ? formatoNome(result.getBarcodeFormat()) : '?';
                    confirmar(result.getText(), modo, _fmt, _ms);
                }
            }
        } catch (e) { /* quadro num estado ruim: ignora */ }
        if (token === scanToken) { setTimeout(function () { scanLoop(token); }, 60); }
    }

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

    // Fluxo único de leitura do leitor de barras.
    function processarLeitura(codigo) {
        if (pausado) { return; }
        const agora = Date.now();
        if (codigo === ultimo && agora - ultimoT < 2500) { return; }
        ultimo = codigo; ultimoT = agora;
        pausado = true; clearInatividade(); bip();
        lookup(codigo);
    }
    function onScan(texto) { processarLeitura(texto); }

    function lookup(codigo) {
        fetch(API + '?acao=lookup&codigo=' + encodeURIComponent(codigo), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) { if (d.found) { abrirItem(d.item); } else { abrirNovo(d.codigo || codigo); } })
            .catch(() => voltarParaScan());
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

    // ---------- Stand-by (economia): após 1h sem toque, desliga a câmera ----------
    // A câmera ligada é o que esquenta o aparelho e drena a bateria. Depois de 1h
    // parado, paramos o vídeo e soltamos a trava de tela (a tela apaga sozinha).
    // Qualquer toque acorda: religa a câmera e volta para a lista de nomes.
    const STANDBY_MS = 30 * 1000;   // TESTE: 30s (voltar para 60*60*1000 = 1h depois de aprovado)
    let standbyTimer = null, emStandby = false;
    const elStandby = document.getElementById('ov-standby');

    function pararCamera() {
        scanToken++;            // encerra o scanLoop em andamento
        pausado = true;
        if (camStream) { try { camStream.getTracks().forEach(t => t.stop()); } catch (e) {} camStream = null; }
        const v = document.getElementById('video');
        if (v) { try { v.pause(); v.srcObject = null; } catch (e) {} }
    }
    function entrarStandby() {
        if (emStandby) { return; }
        emStandby = true; window.__standby = true;
        clearInatividade();
        pararCamera();
        if (window.__wakeSoltar) { window.__wakeSoltar(); }   // deixa a tela apagar
        mostrar(null);
        elStandby.classList.add('show');
    }
    function sairStandby() {
        if (!emStandby) { return; }
        emStandby = false; window.__standby = false;
        elStandby.classList.remove('show');
        if (window.__wakeSegurar) { window.__wakeSegurar(); }
        voltarParaNomes();      // volta limpo para a lista de nomes (câmera desligada)
        armarStandby();
    }
    function armarStandby() {
        if (standbyTimer) { clearTimeout(standbyTimer); }
        standbyTimer = setTimeout(entrarStandby, STANDBY_MS);
    }
    // Qualquer interação adia o stand-by (quando acordado).
    function atividade() { if (!emStandby) { armarStandby(); } }
    document.addEventListener('touchstart', atividade, true);
    document.addEventListener('click', atividade, true);
    // Toque no overlay preto acorda.
    elStandby.addEventListener('click', sairStandby);
    elStandby.addEventListener('touchstart', function (e) { e.preventDefault(); sairStandby(); }, { passive: false });
    armarStandby();

    carregarNomes();
    if (_debug) { const d = document.getElementById('dbg'); if (d) { d.style.display = 'block'; d.textContent = 'debug ligado…'; } }
    // A câmera NÃO liga aqui: só abre quando o colaborador entra com o PIN (voltarParaScan).
})();
</script>

<!-- ===== Alarme de despacho de entregas (roda em 2º plano sobre o quiosque) ===== -->
<div class="kx-alarme" id="ov-alarme">
    <div class="selo">🚚 Despachar entrega</div>
    <div class="cli" id="al-cli">—</div>
    <div class="sub" id="al-sub">—</div>
    <div class="end" id="al-end"></div>
    <button type="button" class="parar" id="al-parar">✓ Já vi — parar alarme</button>
    <div class="fila" id="al-fila"></div>
</div>
<script>
// Fica varrendo os pedidos do Cardápio Web (via pedidos_api.php). Quando uma
// entrega cruza um limiar (padrão 30 e 5 min antes do despacho), toma a tela
// com alarme sonoro. Sem painel: o acompanhamento é feito no próprio CW.
// Ativar teste com dados falsos: estoque_kiosk.php?entregas_mock=1
(function () {
    const params = new URLSearchParams(location.search);
    const MOCK = params.has('entregas_mock');
    const INTERVALO = Math.max(5, parseInt(params.get('entregas_intervalo') || '20', 10)) * 1000;
    const API = 'pedidos_api.php?acao=alertas' + (MOCK ? '&mock=1' : '');

    const el = id => document.getElementById(id);
    const ov = el('ov-alarme');

    let avisos = [30, 5];
    let pedidos = [];
    let audioCtx = null, alarmeTimer = null, ativoKey = null;
    const fila = [];

    // Reconhecidos (pedido:estágio) já vistos — persiste p/ não repetir; esquece após 12h.
    const RKEY = 'despacho_reconhecidos';
    let reconhecidos = (function () {
        try {
            const o = JSON.parse(localStorage.getItem(RKEY) || '{}');
            const lim = Date.now() - 12 * 3600 * 1000, limpo = {};
            for (const k in o) { if (o[k] > lim) limpo[k] = o[k]; }
            localStorage.setItem(RKEY, JSON.stringify(limpo));
            return limpo;
        } catch (e) { return {}; }
    })();
    function marcar(key) {
        reconhecidos[key] = Date.now();
        try { localStorage.setItem(RKEY, JSON.stringify(reconhecidos)); } catch (e) {}
    }

    // Áudio destravado no 1º toque na tela (política de autoplay).
    function destravarAudio() {
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            audioCtx.resume();
        } catch (e) {}
        document.removeEventListener('touchend', destravarAudio, true);
        document.removeEventListener('click', destravarAudio, true);
    }
    document.addEventListener('touchend', destravarAudio, true);
    document.addEventListener('click', destravarAudio, true);

    // Um "pip" curto (uma nota). start/dur em segundos, relativos ao contexto.
    function pip(freq, start, dur) {
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'square'; o.frequency.setValueAtTime(freq, start);
        g.gain.setValueAtTime(0.0001, start);
        g.gain.exponentialRampToValueAtTime(0.5, start + 0.01);   // ataque rápido
        g.gain.setValueAtTime(0.5, start + dur - 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, start + dur); // corte limpo
        o.connect(g); g.connect(audioCtx.destination);
        o.start(start); o.stop(start + dur + 0.02);
    }

    // Alarme estilo despertador: uma rajada de 4 bipes alternando grave/agudo.
    // Chamado em loop (~1.1s) enquanto o overlay está aberto. Dura ~0.66s.
    function beep() {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') { audioCtx.resume(); }
        const t = audioCtx.currentTime + 0.02;
        const dur = 0.09, gap = 0.075;
        const tons = [1046, 784, 1046, 784];   // dó agudo / sol — "bi-bo bi-bo"
        tons.forEach((f, i) => pip(f, t + i * (dur + gap), dur));
    }

    function esc(s) { return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function estagioDe(restam) { let e = null; for (const l of avisos) { if (restam <= l) e = l; } return e; }

    // A cada segundo: recalcula quem cruzou limiar e enfileira alarme.
    function tick() {
        const agora = Date.now();
        for (const p of pedidos) {
            const restam = Math.floor((new Date(p.despacho_em).getTime() - agora) / 60000);
            const est = estagioDe(restam);
            if (est === null) continue;
            const key = p.id + ':' + est;
            // No teste (mock) ignora os "reconhecidos" p/ sempre disparar.
            if ((!MOCK && reconhecidos[key]) || ativoKey === key || fila.some(f => f.key === key)) continue;
            fila.push({
                key, cli: p.cliente,
                sub: (est <= 5 ? 'Sai em ' + Math.max(restam, 0) + ' min' : 'Faltam ' + restam + ' min')
                     + (p.resumo ? ' · ' + p.resumo : ''),
                end: p.endereco || ''
            });
        }
        processarFila();
    }

    function processarFila() {
        if (ativoKey !== null || fila.length === 0) return;
        const a = fila.shift();
        ativoKey = a.key;
        el('al-cli').textContent = a.cli;
        el('al-sub').textContent = a.sub;
        el('al-end').textContent = a.end ? '📍 ' + a.end : '';
        el('al-fila').textContent = fila.length ? ('+' + fila.length + ' outro(s) alerta(s) em espera') : '';
        ov.classList.add('show');
        if (window.__wakeSegurar) { window.__wakeSegurar(); }   // segura a tela acesa
        beep();
        clearInterval(alarmeTimer);
        alarmeTimer = setInterval(beep, 1100);
    }

    function parar() {
        if (ativoKey !== null) marcar(ativoKey);
        clearInterval(alarmeTimer); alarmeTimer = null;
        ov.classList.remove('show');
        ativoKey = null;
        processarFila();   // mostra o próximo, se houver
    }
    el('al-parar').addEventListener('click', parar);
    el('al-parar').addEventListener('touchend', function (e) { e.preventDefault(); parar(); }, { passive: false });

    async function buscar() {
        try {
            const r = await fetch(API, { cache: 'no-store' });
            const j = await r.json();
            if (j.error) { return; }   // CW não configurado/mapeado ainda: fica quieto
            pedidos = j.pedidos || [];
            if (Array.isArray(j.config?.avisos_min) && j.config.avisos_min.length) avisos = j.config.avisos_min;
        } catch (e) { /* sem conexão: tenta no próximo ciclo */ }
    }

    buscar();
    setInterval(buscar, INTERVALO);
    setInterval(tick, 1000);
})();
</script>
</body>
</html>
