<?php
// Quiosque de baixa de estoque — página full-screen para o celular na porta.
// Câmera sempre aberta (html5-qrcode lê QR e código de barras EAN). Lê 1x,
// mostra foto+nome+quantidade+confirmar. Também tem busca para itens sem código.
require_once __DIR__ . '/_auth.php';
require_once 'model_estoque.php';
if (!estoque_pronto($pdo)) { header('Location: estoque.php'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Baixa de estoque</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
    html, body { height: 100%; margin: 0; background: #14171c; color: #fff; overflow: hidden; }
    body { font-family: system-ui, sans-serif; }
    .kx-top { position: fixed; top: 0; left: 0; right: 0; z-index: 20; display: flex; justify-content: space-between;
              align-items: center; padding: 12px 16px; background: rgba(0,0,0,.45); }
    .kx-top h1 { font-size: 1.05rem; margin: 0; font-weight: 600; }
    #reader { width: 100vw; height: 100vh; }
    #reader video { object-fit: cover !important; }
    .kx-hint { position: fixed; bottom: 24px; left: 0; right: 0; text-align: center; z-index: 15;
               font-size: 1.1rem; color: #dfe3e8; text-shadow: 0 1px 4px #000; }
    .kx-overlay { position: fixed; inset: 0; z-index: 30; background: rgba(10,12,16,.96);
                  display: none; flex-direction: column; align-items: center; justify-content: center; padding: 20px; }
    .kx-overlay.show { display: flex; }
    .kx-card { width: 100%; max-width: 520px; text-align: center; }
    .kx-foto { width: 190px; height: 190px; border-radius: 20px; object-fit: cover; background: #232830;
               display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;
               font-size: 4rem; color: #6c757d; }
    .kx-nome { font-size: 2rem; font-weight: 700; line-height: 1.15; margin-bottom: 6px; }
    .kx-saldo { color: #adb5bd; font-size: 1.1rem; margin-bottom: 22px; }
    .kx-qtd { display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 26px; }
    .kx-qtd button { width: 68px; height: 68px; border-radius: 50%; border: none; font-size: 2rem; font-weight: 700;
                     background: #2a3038; color: #fff; }
    .kx-qtd input { width: 130px; height: 74px; text-align: center; font-size: 2.4rem; font-weight: 700;
                    border-radius: 14px; border: 2px solid #3a424c; background: #1b1f26; color: #fff; }
    .btn-kx { font-size: 1.4rem; font-weight: 700; padding: 18px; border-radius: 16px; width: 100%; }
    .kx-check { font-size: 5rem; }
    .kx-grade { width: 100%; max-width: 560px; }
    .kx-grade .item { background: #1b1f26; border: 1px solid #2a3038; border-radius: 14px; padding: 14px;
                      text-align: left; width: 100%; color: #fff; font-size: 1.15rem; margin-bottom: 10px; }
    .kx-busca { font-size: 1.3rem; padding: 16px; border-radius: 14px; }
</style>
</head>
<body>

<div class="kx-top">
    <h1>Baixa de estoque</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-light btn-sm" id="btn-buscar">🔍 Buscar item</button>
        <button class="btn btn-outline-light btn-sm" id="btn-cam">↺ Câmera</button>
        <a class="btn btn-outline-light btn-sm" href="estoque.php">Sair</a>
    </div>
</div>

<div id="reader"></div>
<div class="kx-hint" id="hint">Aponte o código de barras para a câmera</div>

<!-- Overlay: item encontrado (confirmar baixa) -->
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

<!-- Overlay: código desconhecido (associar a um item) -->
<div class="kx-overlay" id="ov-novo">
    <div class="kx-card kx-grade">
        <div class="kx-nome mb-1">Código não cadastrado</div>
        <div class="kx-saldo mb-3">Código <span id="nv-cod" class="text-info"></span> — a qual item pertence?</div>
        <input type="text" class="form-control kx-busca mb-3" id="nv-busca" placeholder="Buscar item pelo nome…">
        <div id="nv-lista"></div>
        <button class="btn btn-outline-light btn-kx mt-2" id="nv-cancelar">Cancelar</button>
    </div>
</div>

<!-- Overlay: busca manual (itens sem código) -->
<div class="kx-overlay" id="ov-busca">
    <div class="kx-card kx-grade">
        <div class="kx-nome mb-3">Buscar item</div>
        <input type="text" class="form-control kx-busca mb-3" id="bs-busca" placeholder="Digite o nome do item…" autofocus>
        <div id="bs-lista"></div>
        <button class="btn btn-outline-light btn-kx mt-2" id="bs-cancelar">Fechar</button>
    </div>
</div>

<!-- Overlay: sucesso -->
<div class="kx-overlay" id="ov-ok">
    <div class="kx-card">
        <div class="kx-check">✅</div>
        <div class="kx-nome mt-2" id="ok-msg"></div>
        <div class="kx-saldo" id="ok-saldo"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    const API = 'estoque_api.php';
    const hint = document.getElementById('hint');
    const ov = {
        item: document.getElementById('ov-item'),
        novo: document.getElementById('ov-novo'),
        busca: document.getElementById('ov-busca'),
        ok: document.getElementById('ov-ok'),
    };
    let scanner = null, facing = 'user', pausado = false, ultimo = '', ultimoT = 0;
    let itemAtual = null, codigoPendente = '';

    function fmt(n) { return (Math.round(n * 1000) / 1000).toLocaleString('pt-BR'); }
    function mostrar(el) { Object.values(ov).forEach(o => o.classList.remove('show')); if (el) { el.classList.add('show'); } }
    function pausar() { pausado = true; try { scanner && scanner.pause(true); } catch (e) {} }
    function retomar() { mostrar(null); pausado = false; try { scanner && scanner.resume(); } catch (e) {} }

    // ---------- Câmera ----------
    function iniciarCamera() {
        if (scanner) { scanner.stop().catch(()=>{}).then(comecar); } else { comecar(); }
    }
    function comecar() {
        scanner = new Html5Qrcode('reader', { verbose: false });
        scanner.start({ facingMode: facing }, { fps: 10, qrbox: { width: 280, height: 200 } }, onScan)
            .catch(function (e) { hint.textContent = 'Não consegui abrir a câmera: ' + e; });
    }
    document.getElementById('btn-cam').onclick = function () {
        facing = (facing === 'user') ? 'environment' : 'user';
        iniciarCamera();
    };

    function onScan(texto) {
        if (pausado) { return; }
        const agora = Date.now();
        if (texto === ultimo && agora - ultimoT < 2500) { return; }   // anti-repetição
        ultimo = texto; ultimoT = agora;
        pausar();
        lookup(texto);
    }

    // ---------- Lookup ----------
    function lookup(codigo) {
        fetch(API + '?acao=lookup&codigo=' + encodeURIComponent(codigo), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                if (d.found) { abrirItem(d.item); }
                else { abrirNovo(d.codigo || codigo); }
            })
            .catch(() => retomar());
    }

    // ---------- Card do item (confirmar baixa) ----------
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
    function passo(d) {
        const el = document.getElementById('q-valor');
        let v = parseInt(el.value, 10) || 0; v += d; if (v < 1) { v = 1; }
        el.value = v;
    }
    document.getElementById('q-menos').onclick = () => passo(-1);
    document.getElementById('q-mais').onclick = () => passo(1);
    document.getElementById('btn-cancelar').onclick = retomar;

    document.getElementById('btn-confirmar').onclick = function () {
        if (!itemAtual) { return; }
        const qtd = parseInt(document.getElementById('q-valor').value, 10) || 1;
        const fd = new FormData();
        fd.append('item_id', itemAtual.id); fd.append('quantidade', qtd);
        this.disabled = true;
        fetch(API + '?acao=baixa', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                document.getElementById('btn-confirmar').disabled = false;
                if (d.error) { alert(d.error); return; }
                document.getElementById('ok-msg').textContent = '−' + qtd + ' · ' + itemAtual.nome;
                document.getElementById('ok-saldo').textContent = 'Novo saldo: ' + fmt(d.saldo);
                mostrar(ov.ok);
                setTimeout(retomar, 1600);
            })
            .catch(function () { document.getElementById('btn-confirmar').disabled = false; alert('Falha ao dar baixa.'); });
    };

    // ---------- Código desconhecido (associar) ----------
    function abrirNovo(codigo) {
        codigoPendente = codigo;
        document.getElementById('nv-cod').textContent = codigo;
        document.getElementById('nv-busca').value = '';
        document.getElementById('nv-lista').innerHTML = '';
        mostrar(ov.novo);
        document.getElementById('nv-busca').focus();
    }
    document.getElementById('nv-cancelar').onclick = retomar;
    ligarBusca('nv-busca', 'nv-lista', function (item) {
        // associa o código pendente e já abre a baixa
        const fd = new FormData();
        fd.append('item_id', item.id); fd.append('codigo', codigoPendente);
        fetch(API + '?acao=associar', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(function (d) {
                if (d.error) { alert(d.error); return; }
                abrirItem(d.item);
            });
    });

    // ---------- Busca manual (itens sem código) ----------
    document.getElementById('btn-buscar').onclick = function () {
        pausar();
        document.getElementById('bs-busca').value = '';
        document.getElementById('bs-lista').innerHTML = '';
        mostrar(ov.busca);
        document.getElementById('bs-busca').focus();
    };
    document.getElementById('bs-cancelar').onclick = retomar;
    ligarBusca('bs-busca', 'bs-lista', abrirItem);

    // Liga um campo de busca a uma lista; onEscolher(item) ao tocar.
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

    iniciarCamera();
})();
</script>
</body>
</html>
