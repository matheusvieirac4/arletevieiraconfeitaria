// Funil Doceria — content script no WhatsApp Web.
// Botao flutuante -> painel que desliza da direita -> funil do bot -> clicar num
// cliente pesquisa o numero na barra do WhatsApp e abre a conversa.
(function () {
  'use strict';
  if (window.__funilDoceria) return; // evita duplicar
  window.__funilDoceria = true;

  let painel, fab, timerRefresh = null;

  // ---------- monta a UI ----------
  function montar() {
    fab = document.createElement('button');
    fab.id = 'funil-doceria-fab';
    fab.innerHTML = '<span class="fd-dot"></span> Funil';
    fab.addEventListener('click', abrirFechar);
    document.body.appendChild(fab);

    painel = document.createElement('div');
    painel.id = 'funil-doceria-panel';
    painel.innerHTML =
      '<div class="fd-head"><button class="fd-close" title="Fechar">×</button>' +
      '<h2>Funil de atendimento</h2>' +
      '<div class="fd-status"><span class="fd-dot"></span><span class="fd-status-txt">carregando…</span></div></div>' +
      '<div class="fd-body">carregando…</div>' +
      '<div class="fd-foot"><span class="fd-atualizado"></span><button class="fd-refresh">Atualizar</button></div>';
    document.body.appendChild(painel);
    painel.querySelector('.fd-close').addEventListener('click', abrirFechar);
    painel.querySelector('.fd-refresh').addEventListener('click', carregar);
  }

  function abrirFechar() {
    const aberto = painel.classList.toggle('fd-open');
    if (aberto) { carregar(); timerRefresh = setInterval(carregar, 15000); }
    else if (timerRefresh) { clearInterval(timerRefresh); timerRefresh = null; }
  }

  // ---------- busca os dados (via background) ----------
  function carregar() {
    chrome.runtime.sendMessage({ type: 'getFunil' }, (resp) => {
      if (!resp || !resp.ok) return mostrarErro(resp ? resp.error : 'sem resposta');
      render(resp.data);
    });
  }

  function mostrarErro(msg) {
    setStatus(false, 'bot offline');
    painel.querySelector('.fd-body').innerHTML =
      '<div class="fd-erro"><b>Não consegui falar com o bot.</b><br>' +
      'Verifique se o bot está rodando (Docker) em <code>localhost:3000</code>.<br><br>' +
      '<small>' + (msg || '') + '</small></div>';
  }

  function setStatus(online, txt) {
    const s = painel.querySelector('.fd-status');
    s.classList.toggle('fd-off', !online);
    painel.querySelector('.fd-status-txt').textContent = txt;
  }

  function haQuanto(ts) {
    if (!ts) return '';
    const min = Math.floor((Date.now() - ts) / 60000);
    if (min < 1) return 'agora';
    if (min < 60) return 'há ' + min + ' min';
    const h = Math.floor(min / 60);
    if (h < 24) return 'há ' + h + 'h';
    return 'há ' + Math.floor(h / 24) + 'd';
  }
  const iniciais = (nome, num) => (nome ? nome.trim()[0] : (num || '#').slice(-2, -1)).toUpperCase();
  const esc = (s) => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  // ---------- render do funil ----------
  function render(data) {
    setStatus(data.conexao === 'open', data.conexao === 'open' ? 'bot online · WhatsApp conectado' : 'bot online · WhatsApp ' + (data.conexao || '?'));
    painel.querySelector('.fd-atualizado').textContent = 'atualizado ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });

    const body = painel.querySelector('.fd-body');
    const abertas = new Set([...body.querySelectorAll('.fd-etapa.fd-aberta')].map(e => e.dataset.k));
    body.innerHTML = '';

    for (const [chave, e] of Object.entries(data.etapas)) {
      const el = document.createElement('div');
      el.className = 'fd-etapa' + (abertas.has(chave) ? ' fd-aberta' : '');
      el.dataset.k = chave;
      const lista = e.clientes.map(c =>
        '<div class="fd-cli" data-num="' + esc(c.numero) + '">' +
          '<div class="fd-av">' + esc(iniciais(c.nome, c.numero)) + '</div>' +
          '<div class="fd-info"><div class="fd-nome">' + esc(c.nome || ('+' + c.numero)) + '</div>' +
          '<div class="fd-meta">' + esc(c.numero) + (c.desde ? ' · ' + haQuanto(c.desde) : '') + '</div></div>' +
          '<span class="fd-go">abrir ›</span></div>'
      ).join('') || '<div class="fd-vazia">Ninguém aqui agora.</div>';

      el.innerHTML =
        '<div class="fd-etapa-head"><span class="fd-bola" style="background:' + e.cor + '"></span>' +
        '<span class="fd-rot">' + esc(e.rotulo) + '</span>' +
        '<span class="fd-num" style="color:' + e.cor + '">' + e.clientes.length + '</span>' +
        '<span class="fd-caret">›</span></div>' +
        '<div class="fd-lista">' + lista + '</div>';

      el.querySelector('.fd-etapa-head').addEventListener('click', () => el.classList.toggle('fd-aberta'));
      el.querySelectorAll('.fd-cli').forEach(cli =>
        cli.addEventListener('click', () => abrirConversa(cli.dataset.num)));
      body.appendChild(el);
    }
  }

  // ---------- abrir a conversa do cliente ----------
  // Primeiro tenta pesquisar o numero na barra do WhatsApp e clicar no resultado.
  // Se nao achar a barra/resultado, cai no link send?phone (abre a conversa direto).
  async function abrirConversa(numero) {
    const num = String(numero).replace(/\D/g, '');
    const busca = acharBarraBusca();
    if (busca) {
      try {
        busca.focus();
        document.execCommand('selectAll', false, null);
        document.execCommand('insertText', false, num);
        busca.dispatchEvent(new InputEvent('input', { bubbles: true }));
        const item = await esperar(() => document.querySelector('#pane-side [role="listitem"], #side [role="listitem"]'), 1600);
        if (item) {
          item.click();
          setTimeout(limparBusca, 300);
          return;
        }
      } catch (e) { /* cai no fallback */ }
    }
    // fallback: abre a conversa pelo link oficial (recarrega o painel de conversa)
    window.location.href = 'https://web.whatsapp.com/send?phone=' + num;
  }

  function acharBarraBusca() {
    // A barra de pesquisa e o unico contenteditable dentro do #side.
    const cands = document.querySelectorAll('#side div[contenteditable="true"], div[contenteditable="true"][data-tab="3"]');
    return cands[0] || null;
  }
  function limparBusca() {
    const b = acharBarraBusca();
    if (!b) return;
    b.focus();
    document.execCommand('selectAll', false, null);
    document.execCommand('delete', false, null);
    b.dispatchEvent(new InputEvent('input', { bubbles: true }));
    b.blur();
  }
  function esperar(fn, ms) {
    return new Promise(resolve => {
      const t0 = Date.now();
      (function loop() {
        const v = fn();
        if (v) return resolve(v);
        if (Date.now() - t0 > ms) return resolve(null);
        setTimeout(loop, 100);
      })();
    });
  }

  // ---------- espera o WhatsApp carregar e monta ----------
  (function esperarWA() {
    if (document.querySelector('#app') && document.body) { montar(); }
    else setTimeout(esperarWA, 800);
  })();
})();
