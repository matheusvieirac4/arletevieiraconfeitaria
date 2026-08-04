<?php
// Relógio de ponto (quiosque) — tela full-screen do celular da empresa.
// Fluxo: escolhe o nome -> digita o PIN -> vê o estado (dentro/fora) -> bate
// (entrada ou saída) -> confirmação -> volta para a lista de nomes.
// Mesmo acesso do quiosque do estoque (admin logado OU token do aparelho).
require_once __DIR__ . '/_session.php';
require_once 'model_ponto.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!estoque_kiosk_autorizado()) { header('Location: login.php'); exit; }
if (!ponto_pronto($pdo))         { header('Location: ponto.php'); exit; }
$kioskAdmin = !empty($_SESSION['admin_blog']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Relógio de ponto</title>
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#14171c">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
    html, body { height: 100%; margin: 0; background: #14171c; color: #fff; overflow: hidden; }
    body { font-family: system-ui, sans-serif; }
    .pt-top { position: fixed; top: 0; left: 0; right: 0; z-index: 20; display: flex; justify-content: space-between;
              align-items: center; padding: 12px 16px; background: rgba(0,0,0,.35); }
    .pt-relogio { font-size: 1.3rem; font-weight: 700; letter-spacing: .02em; }
    .pt-overlay { position: fixed; inset: 0; z-index: 30; background: #14171c;
                  display: none; flex-direction: column; align-items: center; justify-content: center; padding: 20px; overflow:auto; }
    .pt-overlay.show { display: flex; }
    .pt-card { width: 100%; max-width: 560px; text-align: center; }
    .pt-nome { font-size: 2rem; font-weight: 700; line-height: 1.15; margin-bottom: 6px; }
    .pt-sub { color: #adb5bd; font-size: 1.1rem; margin-bottom: 22px; }
    .btn-pt { font-size: 1.5rem; font-weight: 700; padding: 22px; border-radius: 18px; width: 100%; }
    .pt-grade { width: 100%; max-width: 640px; }
    .pt-nomes { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .pt-nomes button { background: #1b1f26; border: 2px solid #2a3038; color: #fff; border-radius: 16px;
                       padding: 26px 12px; font-size: 1.5rem; font-weight: 700; }
    .pin-dots { font-size: 2.6rem; letter-spacing: .4rem; margin-bottom: 20px; height: 40px; }
    .pin-pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; max-width: 340px; margin: 0 auto; }
    .pin-pad button { background: #1b1f26; border: 2px solid #2a3038; color: #fff; border-radius: 16px; padding: 22px 0; font-size: 2rem; font-weight: 700; }
    .shake { animation: shake .35s; } @keyframes shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-10px)} 75%{transform:translateX(10px)} }
    .pt-estado { display:inline-block; padding: 8px 18px; border-radius: 999px; font-size: 1.1rem; font-weight: 700; margin-bottom: 18px; }
    .pt-dentro { background: #14351f; color: #4ade80; border: 1px solid #216c3a; }
    .pt-fora   { background: #2a3038; color: #cbd2da; border: 1px solid #3a424c; }
    .pt-batidas { color:#8b939c; font-size:1rem; margin: 14px 0 0; }
    .pt-check { font-size: 5rem; }
</style>
</head>
<body>
<script>
// Tela cheia no primeiro toque (Android/Chrome esconde a barra de endereço).
(function () {
    function goFull() {
        var el = document.documentElement;
        var req = el.requestFullscreen || el.webkitRequestFullscreen;
        if (req && !(document.fullscreenElement || document.webkitFullscreenElement)) { try { req.call(el); } catch (e) {} }
        document.removeEventListener('touchend', goFull, true);
        document.removeEventListener('click', goFull, true);
    }
    document.addEventListener('touchend', goFull, true);
    document.addEventListener('click', goFull, true);
})();
</script>

<div class="pt-top">
    <a href="estoque_kiosk.php" class="btn btn-outline-light btn-sm">📦 Estoque</a>
    <div class="pt-relogio" id="relogio">--:--</div>
    <?php if ($kioskAdmin): ?>
        <a href="ponto.php" class="btn btn-outline-light btn-sm">Sair</a>
    <?php else: ?>
        <span style="width:64px"></span>
    <?php endif; ?>
</div>

<!-- Passo 1: escolher a pessoa -->
<div class="pt-overlay show" id="ov-nomes">
    <div class="pt-card pt-grade">
        <div class="pt-nome mb-3">Quem está batendo o ponto?</div>
        <div id="nomes-lista" class="pt-nomes"></div>
        <div id="nomes-vazio" class="pt-sub d-none mt-3">Nenhuma pessoa cadastrada. Cadastre em Estoque → Colaboradores.</div>
    </div>
</div>

<!-- Passo 2: PIN -->
<div class="pt-overlay" id="ov-pin">
    <div class="pt-card">
        <div class="pt-nome mb-1" id="pin-nome"></div>
        <div class="pt-sub mb-2">Digite seu PIN de 4 dígitos</div>
        <div class="pin-dots" id="pin-dots">○ ○ ○ ○</div>
        <div class="pin-pad" id="pin-pad"></div>
        <button class="btn btn-outline-light btn-pt mt-3" id="pin-voltar" style="max-width:340px;margin:16px auto 0;">Voltar</button>
    </div>
</div>

<!-- Passo 3: bater (entrada/saída) -->
<div class="pt-overlay" id="ov-bater">
    <div class="pt-card">
        <div class="pt-nome" id="bt-nome"></div>
        <div class="pt-sub mb-1" id="bt-data"></div>
        <div><span class="pt-estado" id="bt-estado"></span></div>
        <button class="btn btn-pt mb-3" id="bt-acao"></button>
        <button class="btn btn-outline-light btn-pt" id="bt-cancelar">Cancelar</button>
        <div class="pt-batidas" id="bt-batidas"></div>
    </div>
</div>

<!-- Sucesso -->
<div class="pt-overlay" id="ov-ok">
    <div class="pt-card">
        <div class="pt-check" id="ok-icone">✅</div>
        <div class="pt-nome mt-2" id="ok-msg"></div>
        <div class="pt-sub" id="ok-sub"></div>
    </div>
</div>

<script>
(function () {
    const API = 'ponto_api.php';
    const ov = {
        nomes: document.getElementById('ov-nomes'),
        pin:   document.getElementById('ov-pin'),
        bater: document.getElementById('ov-bater'),
        ok:    document.getElementById('ov-ok'),
    };
    let pinSel = null, pinNome = '', pinBuffer = '', pinAtual = '', voltarTimer = null;

    function mostrar(el) { Object.values(ov).forEach(o => o.classList.remove('show')); if (el) { el.classList.add('show'); } }
    function hm(min) { min = Math.max(0, min|0); const h = Math.floor(min/60), m = min%60; return h + 'h' + (m>0 ? String(m).padStart(2,'0') : ''); }

    // Relógio no topo.
    function tick() {
        const d = new Date();
        document.getElementById('relogio').textContent =
            String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
    }
    tick(); setInterval(tick, 10000);

    function voltarParaNomes() {
        if (voltarTimer) { clearTimeout(voltarTimer); voltarTimer = null; }
        pinSel = null; pinNome = ''; pinBuffer = ''; pinAtual = '';
        mostrar(ov.nomes);
    }

    // ---------- Passo 1: nomes ----------
    function carregarNomes() {
        fetch(API + '?acao=pessoas', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                const lista = document.getElementById('nomes-lista');
                lista.innerHTML = '';
                const ps = d.pessoas || [];
                document.getElementById('nomes-vazio').classList.toggle('d-none', ps.length > 0);
                ps.forEach(function (p) {
                    const b = document.createElement('button');
                    b.textContent = p.nome;
                    b.onclick = function () { abrirPin(p.id, p.nome); };
                    lista.appendChild(b);
                });
            })
            .catch(function () { document.getElementById('nomes-vazio').classList.remove('d-none'); });
    }

    // ---------- Passo 2: PIN ----------
    function abrirPin(id, nome) {
        pinSel = id; pinNome = nome; pinBuffer = '';
        document.getElementById('pin-nome').textContent = nome;
        renderDots(); mostrar(ov.pin);
    }
    function renderDots() {
        let s = '';
        for (let i = 0; i < 4; i++) { s += (i < pinBuffer.length ? '●' : '○') + ' '; }
        document.getElementById('pin-dots').textContent = s.trim();
    }
    (function montarPad() {
        const pad = document.getElementById('pin-pad');
        ['1','2','3','4','5','6','7','8','9','⌫','0',''].forEach(function (t) {
            const b = document.createElement('button');
            b.textContent = t;
            if (t === '') { b.style.visibility = 'hidden'; }
            b.onclick = function () {
                if (t === '⌫') { pinBuffer = pinBuffer.slice(0, -1); renderDots(); return; }
                if (t === '' || pinBuffer.length >= 4) { return; }
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
                if (d.ok) { pinAtual = pinBuffer; abrirBater(d.status); }
                else {
                    pinBuffer = ''; renderDots();
                    const dots = document.getElementById('pin-dots');
                    dots.classList.add('shake'); setTimeout(() => dots.classList.remove('shake'), 400);
                }
            })
            .catch(function () { voltarParaNomes(); });
    }

    // ---------- Passo 3: bater ----------
    function abrirBater(status) {
        document.getElementById('bt-nome').textContent = pinNome;
        const d = new Date();
        document.getElementById('bt-data').textContent =
            d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' });
        const est = document.getElementById('bt-estado');
        const acao = document.getElementById('bt-acao');
        if (status.dentro) {
            est.className = 'pt-estado pt-dentro';
            est.textContent = '● Dentro desde ' + (status.desde || '--:--') + ' · ' + hm(status.trabalhado_min);
            acao.className = 'btn btn-danger btn-pt mb-3';
            acao.textContent = '⏸ Registrar SAÍDA';
        } else {
            est.className = 'pt-estado pt-fora';
            est.textContent = status.trabalhado_min > 0 ? ('Fora · hoje ' + hm(status.trabalhado_min)) : 'Fora';
            acao.className = 'btn btn-success btn-pt mb-3';
            acao.textContent = '▶ Registrar ENTRADA';
        }
        // Resumo das batidas do dia.
        const bt = (status.batidas || []).map(function (b) {
            return (b.tipo === 'entrada' ? '↳ ' : '⇤ ') + b.hora;
        }).join('   ');
        document.getElementById('bt-batidas').textContent = bt ? ('Hoje: ' + bt) : '';
        mostrar(ov.bater);
    }
    document.getElementById('bt-cancelar').onclick = voltarParaNomes;
    document.getElementById('bt-acao').onclick = function () {
        this.disabled = true;
        const fd = new FormData();
        fd.append('colaborador_id', pinSel); fd.append('pin', pinAtual);
        fetch(API + '?acao=bater', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                document.getElementById('bt-acao').disabled = false;
                if (d.error) { alert(d.error); return; }
                const entrada = d.tipo === 'entrada';
                document.getElementById('ok-icone').textContent = entrada ? '👋' : '✅';
                document.getElementById('ok-msg').textContent = pinNome + ' — ' + (entrada ? 'ENTRADA' : 'SAÍDA') + ' ' + d.hora;
                document.getElementById('ok-sub').textContent = entrada ? 'Bom trabalho!' : ('Trabalhado hoje: ' + hm(d.status.trabalhado_min));
                mostrar(ov.ok);
                voltarTimer = setTimeout(voltarParaNomes, 2200);
            })
            .catch(function () { document.getElementById('bt-acao').disabled = false; alert('Falha ao bater ponto.'); });
    };

    carregarNomes();
})();
</script>
</body>
</html>
