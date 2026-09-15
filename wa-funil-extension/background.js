// Service worker: faz a chamada ao bot (http://localhost:3000) que o content
// script (na pagina https do WhatsApp) nao pode fazer por causa de mixed-content.
const BASES = ['http://127.0.0.1:3000', 'http://localhost:3000'];

async function pegarFunil() {
  let ultimoErro = 'sem resposta';
  for (const base of BASES) {
    try {
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), 4000);
      const r = await fetch(base + '/funil', { signal: ctrl.signal });
      clearTimeout(t);
      if (r.ok) return { ok: true, data: await r.json() };
      ultimoErro = 'HTTP ' + r.status;
    } catch (e) {
      ultimoErro = e.message;
    }
  }
  return { ok: false, error: ultimoErro };
}

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg && msg.type === 'getFunil') {
    pegarFunil().then(sendResponse);
    return true; // resposta assincrona
  }
});
