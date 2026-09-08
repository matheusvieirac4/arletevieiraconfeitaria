'use strict';
/**
 * Gera o artefato "Fluxo do Bot da Doceria" a partir do flow.json.
 * Toda vez que o flow.json muda, rode este gerador e republique o artefato
 * (mesmo caminho -> mesmo link). Uso: node tools/gen-flowchart.js
 * Saida: tools/flowchart.html
 */
const fs = require('fs');
const path = require('path');

const flow = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'flow.json'), 'utf8'));

const cap = s => s.charAt(0).toUpperCase() + s.slice(1);
const humanize = id => cap(id.replace(/_/g, ' '));
const esc = s => String(s).replace(/"/g, "'");

// Rotulo amigavel por tipo de node
function labelNode(id, node) {
  if (node.tipo === 'link') {
    const arq = (node.url || '').split('/').pop().replace(/^av_/, '').replace(/\.(pdf|php|html?)$/i, '');
    return `📄 ${cap(arq || id)}`;
  }
  if (node.tipo === 'atendente') return `👤 ${humanize(id)}`;
  if (node.tipo === 'perguntas') return `📋 ${humanize(id)}`;
  if (node.tipo === 'texto') return `💬 ${humanize(id)}`;
  return humanize(id);
}
// Shape mermaid por tipo
function shapeNode(id, label, node) {
  const L = `"${esc(label)}"`;
  switch (node.tipo) {
    case 'link':      return `${id}[/${L}/]:::pdf`;
    case 'atendente': return `${id}([${L}]):::atendente`;
    case 'perguntas': return `${id}[[${L}]]:::perguntas`;
    case 'texto':     return `${id}[${L}]:::texto`;
    default:          return `${id}[${L}]`;
  }
}

// --- monta o diagrama mermaid ------------------------------------------------
const linhas = ['flowchart TD'];
linhas.push(`  inicio([" 1º contato "]):::evento`);
if (flow.saudacao) { linhas.push(`  saud["💬 Saudação"]:::texto`); linhas.push(`  inicio --> saud --> ${flow.start}`); }
else { linhas.push(`  inicio --> ${flow.start}`); }

// menus (hexagono)
for (const [id, menu] of Object.entries(flow.menus || {})) {
  const nome = id === flow.start ? 'Menu principal' : humanize(id.replace(/^menu_/, ''));
  linhas.push(`  ${id}{{"${esc(nome)}"}}:::menu`);
}
// nodes de conteudo
for (const [id, node] of Object.entries(flow.nodes || {})) {
  linhas.push(`  ${shapeNode(id, labelNode(id, node), node)}`);
}
// no do kill-switch (mecanismo central)
linhas.push(`  humano["🤫 Você assume<br/>bot silencia"]:::humano`);

// arestas: cada opcao de menu -> destino, rotulada
for (const [id, menu] of Object.entries(flow.menus || {})) {
  for (const o of (menu.opcoes || [])) {
    linhas.push(`  ${id} -->|"${esc(o.label)}"| ${o.goto}`);
  }
}
// handoff pra humano: nós de atendente/perguntas -> humano (fromMe)
for (const [id, node] of Object.entries(flow.nodes || {})) {
  if (node.tipo === 'atendente' || node.tipo === 'perguntas') {
    linhas.push(`  ${id} -.->|"você responde (fromMe)"| humano`);
  }
}

// classes (cores nas paleta do "papel" — funcionam em tema claro e escuro)
linhas.push(`  classDef evento fill:#2A2126,stroke:#2A2126,color:#fff;`);
linhas.push(`  classDef menu fill:#F4D9E3,stroke:#C2557A,color:#5A2438,font-weight:600;`);
linhas.push(`  classDef pdf fill:#F6E7C9,stroke:#C79A3E,color:#6B4E12;`);
linhas.push(`  classDef atendente fill:#D6E4E5,stroke:#5E8C8F,color:#204547;`);
linhas.push(`  classDef perguntas fill:#E3DAF0,stroke:#8A6FC0,color:#3D2A66;`);
linhas.push(`  classDef texto fill:#EFE2C6,stroke:#B79A55,color:#5C4A1E;`);
linhas.push(`  classDef humano fill:#fff,stroke:#2A2126,stroke-dasharray:4 3,color:#2A2126;`);
const mermaidDef = linhas.join('\n');

// --- horario legivel ---------------------------------------------------------
const dias = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
const h = flow.horario || {};
const diasTxt = (h.dias || []).map(d => dias[d]).join(', ');
const horarioTxt = h.inicio ? `${diasTxt} · ${h.inicio}–${h.fim}` : '—';
const gerado = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });

// atalhos agrupados por destino (palavra-chave -> destino)
const porAlvo = {};
for (const [kw, alvo] of Object.entries(flow.atalhos || {})) { (porAlvo[alvo] ||= []).push(kw); }
const atalhosHtml = Object.keys(porAlvo).length
  ? `<div class="card"><h3>Atalhos por palavra-chave</h3><p style="margin:0 0 10px;color:var(--muted);font-size:13px">O cliente pode digitar em vez de navegar:</p><div class="kw">`
    + Object.entries(porAlvo).map(([alvo, kws]) =>
        `<div class="kwrow"><span class="kws">${kws.map(k => `<code>${esc(k)}</code>`).join(' ')}</span><span class="kwto">→ ${esc(humanize(alvo))}</span></div>`).join('')
    + `</div></div>`
  : '';
const nMenus = Object.keys(flow.menus || {}).length;
const nNodes = Object.keys(flow.nodes || {}).length;

// --- HTML do artefato --------------------------------------------------------
const html = `<title>Fluxo do Bot da Doceria</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
  :root{
    --ground:#FBF6F3; --ink:#2A2126; --muted:#8A7A80; --line:#EADFDA;
    --accent:#C2557A; --panel:#FDFBF9; --card:#FFFFFF; --paper:#FDFBF9;
  }
  :root:not([data-theme="light"]){}
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      --ground:#1E181B; --ink:#F3E9ED; --muted:#B69FA8; --line:#3A2F34;
      --accent:#E58AA8; --panel:#251E22; --card:#251E22;
    }
  }
  :root[data-theme="dark"]{
    --ground:#1E181B; --ink:#F3E9ED; --muted:#B69FA8; --line:#3A2F34;
    --accent:#E58AA8; --panel:#251E22; --card:#251E22;
  }
  *{box-sizing:border-box}
  body{background:var(--ground);color:var(--ink);
    font-family:"IBM Plex Sans",system-ui,sans-serif;line-height:1.5;
    margin:0;padding:32px 24px 56px;}
  .wrap{max-width:1040px;margin:0 auto}
  header{margin-bottom:24px}
  .eyebrow{font-family:"IBM Plex Mono",monospace;font-size:12px;letter-spacing:.14em;
    text-transform:uppercase;color:var(--accent);margin:0 0 6px}
  h1{font-family:"Fraunces",Georgia,serif;font-weight:600;font-size:clamp(30px,5vw,46px);
    line-height:1.05;text-wrap:balance;margin:0 0 8px}
  .sub{color:var(--muted);max-width:60ch;margin:0}
  .meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
  .chip{font-family:"IBM Plex Mono",monospace;font-size:12.5px;color:var(--ink);
    background:var(--card);border:1px solid var(--line);border-radius:999px;padding:6px 12px}
  .chip b{color:var(--accent);font-weight:500}
  .paper{background:var(--paper);border:1px solid var(--line);border-radius:14px;
    padding:20px;margin:22px 0;overflow-x:auto;box-shadow:0 1px 0 rgba(0,0,0,.03)}
  .paper .mermaid{min-width:560px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px}
  .card h3{font-family:"Fraunces",Georgia,serif;font-weight:600;font-size:16px;margin:0 0 10px}
  .legend{display:flex;flex-direction:column;gap:9px;font-size:14px}
  .legend .row{display:flex;align-items:center;gap:10px}
  .sw{width:26px;height:16px;border-radius:5px;border:1.5px solid;flex:none}
  .rules{font-size:14px;color:var(--ink);margin:0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:8px}
  .rules li{padding-left:20px;position:relative}
  .rules li::before{content:"→";position:absolute;left:0;color:var(--accent)}
  .kw{display:flex;flex-direction:column;gap:8px;font-size:13.5px}
  .kwrow{display:flex;justify-content:space-between;gap:10px;align-items:baseline;flex-wrap:wrap}
  .kw code{font-family:"IBM Plex Mono",monospace;font-size:12px;background:var(--ground);
    border:1px solid var(--line);border-radius:5px;padding:1px 6px}
  .kwto{color:var(--muted);white-space:nowrap}
  footer{margin-top:26px;color:var(--muted);font-family:"IBM Plex Mono",monospace;font-size:12px}
</style>

<div class="wrap">
  <header>
    <p class="eyebrow">Arlete Vieira Confeitaria · atendimento WhatsApp</p>
    <h1>Fluxo do bot, do jeito que ele atende hoje</h1>
    <p class="sub">Mapa gerado direto do <code>flow.json</code> — cada menu, cada cardápio em PDF e o caminho até um humano assumir. Republica sempre que o fluxo muda.</p>
    <div class="meta">
      <span class="chip"><b>${nMenus}</b> menus</span>
      <span class="chip"><b>${nNodes}</b> destinos</span>
      <span class="chip">Atendimento: <b>${esc(horarioTxt)}</b></span>
    </div>
  </header>

  <div class="paper">
    <pre class="mermaid">
${mermaidDef}
    </pre>
  </div>

  <div class="grid">
    <div class="card">
      <h3>Legenda</h3>
      <div class="legend">
        <div class="row"><span class="sw" style="background:#F4D9E3;border-color:#C2557A"></span> Menu (o cliente escolhe)</div>
        <div class="row"><span class="sw" style="background:#F6E7C9;border-color:#C79A3E"></span> Cardápio em PDF</div>
        <div class="row"><span class="sw" style="background:#D6E4E5;border-color:#5E8C8F"></span> Atende humano (checa horário)</div>
        <div class="row"><span class="sw" style="background:#E3DAF0;border-color:#8A6FC0"></span> Captura de pedido (perguntas)</div>
        <div class="row"><span class="sw" style="background:#EFE2C6;border-color:#B79A55"></span> Texto / link</div>
        <div class="row"><span class="sw" style="background:#fff;border-color:#2A2126;border-style:dashed"></span> Você assume → bot silencia</div>
      </div>
    </div>
    <div class="card">
      <h3>Regras que valem em todo o fluxo</h3>
      <ul class="rules">
        <li>Você responde pelo celular → o bot se cala naquela conversa (kill-switch <code>fromMe</code>).</li>
        <li>O menu abre a qualquer hora; só o atendimento humano respeita o horário.</li>
        <li>Fora do horário: manda a ausência, confirma o recebimento da mensagem (uma vez) e fica quieto até você responder.</li>
        <li>Sem resposta por ~23h, o bot manda um lembrete e encerra.</li>
      </ul>
    </div>
    ${atalhosHtml}
  </div>

  <footer>Gerado de flow.json em ${esc(gerado)} (America/Sao_Paulo)</footer>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/mermaid/10.9.1/mermaid.min.js"></script>
<script>
  mermaid.initialize({
    startOnLoad: true,
    securityLevel: 'strict',
    flowchart: { curve: 'basis', nodeSpacing: 46, rankSpacing: 52, useMaxWidth: true },
    theme: 'base',
    themeVariables: { fontFamily: 'IBM Plex Sans, sans-serif', lineColor: '#B49AA2', primaryTextColor: '#2A2126' }
  });
</script>`;

fs.writeFileSync(path.join(__dirname, 'flowchart.html'), html);
console.log(`flowchart.html gerado (${nMenus} menus, ${nNodes} destinos).`);
