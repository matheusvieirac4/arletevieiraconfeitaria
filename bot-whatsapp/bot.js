'use strict';
/**
 * Bot de primeiro atendimento — Arlete Vieira Confeitaria
 * ------------------------------------------------------
 * Faz TRES coisas e so:
 *   1. Responde o primeiro contato com um menu numerado
 *   2. Entrega a informacao da categoria escolhida
 *   3. Sai de cena PERMANENTEMENTE assim que um humano (voce) entra
 *
 * Nao faz: CRM, IA/NLP, disparo em massa, dashboard. De proposito.
 *
 * Estado: Map em memoria + espelho em disco (state.json) SO do flag HUMANO,
 * porque perder esse flag num restart faria o bot atropelar sua conversa ao vivo.
 * O resto do estado (menu/submenu) pode se perder num restart sem dano real.
 */

const express = require('express');
const fs = require('fs');
const path = require('path');

// ----------------------------------------------------------------------------
// Config (via .env)
// ----------------------------------------------------------------------------
const PORT              = parseInt(process.env.PORT || '3000', 10);
const EVOLUTION_URL     = process.env.EVOLUTION_URL || 'http://evolution:8080';
const EVOLUTION_KEY     = process.env.EVOLUTION_API_KEY || '';
const INSTANCE          = process.env.INSTANCE_NAME || 'arlete';
const TZ                = 'America/Sao_Paulo';

const HUMAN_TTL_HOURS   = parseFloat(process.env.HUMAN_TTL_HOURS || '18');   // flag HUMANO expira depois disso
const MENU_RESET_HOURS  = parseFloat(process.env.MENU_RESET_HOURS || '12');  // sem interacao > isso => menu de novo
const FOLLOWUP_HOURS    = parseFloat(process.env.FOLLOWUP_HOURS || '23');    // ManyChat usava 23h; espera antes do follow-up/encerramento
const USE_NATIVE_LIST   = process.env.USE_NATIVE_LIST === 'true';            // lista nativa do WhatsApp (quebra no Baileys atual; padrao false = menu numerado)
const DRY_RUN           = process.env.DRY_RUN === 'true';                    // true = nao chama Evolution, so loga

// Heartbeat: o bot empurra um sinal de vida pra HostGator; o cron de la avisa se sumir.
const HEARTBEAT_URL     = process.env.HEARTBEAT_URL || '';                   // ex: https://seudominio/bot-monitor/heartbeat.php
const HEARTBEAT_SECRET  = process.env.HEARTBEAT_SECRET || '';
const HEARTBEAT_MIN     = parseFloat(process.env.HEARTBEAT_MIN || '2');

const STATE_FILE        = path.join(__dirname, 'data', 'state.json');
const FLOW_FILE         = path.join(__dirname, 'flow.json');

// ----------------------------------------------------------------------------
// Conteudo editavel (flow.json)
// ----------------------------------------------------------------------------
let flow = JSON.parse(fs.readFileSync(FLOW_FILE, 'utf8'));

// ----------------------------------------------------------------------------
// Estado em memoria
//   conversas: jid -> { state, lastSeen, timer, humanUntil }
//   sentIds:   ids de mensagens que O BOT enviou (pra ignorar o proprio fromMe)
//   seenIds:   idempotencia — messageIds ja processados
// ----------------------------------------------------------------------------
// ACTIVE = navegando a arvore de menus; PARADO = passado pra humano (bot cala ate voce responder)
const STATES = { ACTIVE: 'ACTIVE', PARADO: 'PARADO', HUMANO: 'HUMANO', ENCERRADO: 'ENCERRADO' };
const conversas = new Map();
const sentIds   = new Map();   // id -> timestamp (expira)
const seenIds   = new Map();   // id -> timestamp (expira)
const chatQueue = new Map();   // jid -> Promise (serializa mensagens do mesmo chat)
let connState   = 'unknown';   // estado da conexao Evolution<->WhatsApp (vai no heartbeat)

// --- Persistencia so do flag HUMANO -----------------------------------------
function loadState() {
  try {
    const raw = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
    const now = Date.now();
    for (const [jid, humanUntil] of Object.entries(raw.humanos || {})) {
      if (humanUntil > now) conversas.set(jid, { state: STATES.HUMANO, lastSeen: now, humanUntil, timer: null });
    }
    console.log(`[state] ${conversas.size} conversa(s) humana(s) restauradas do disco`);
  } catch { /* primeiro boot: arquivo nao existe, tudo bem */ }
}
let saveTimer = null;
function saveStateThrottled() {
  if (saveTimer) return;
  saveTimer = setTimeout(() => {
    saveTimer = null;
    const humanos = {};
    for (const [jid, c] of conversas) if (c.state === STATES.HUMANO && c.humanUntil) humanos[jid] = c.humanUntil;
    try {
      fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
      fs.writeFileSync(STATE_FILE, JSON.stringify({ humanos }, null, 0));
    } catch (e) { console.error('[state] falha ao salvar:', e.message); }
  }, 2000);
}

// ----------------------------------------------------------------------------
// Helpers de tempo / horario comercial
// ----------------------------------------------------------------------------
function partsInSP(date = new Date()) {
  // Extrai dia-da-semana e hora no fuso de SP sem depender de libs.
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: TZ, weekday: 'short', hour: 'numeric', minute: 'numeric', hour12: false,
  });
  const p = Object.fromEntries(fmt.formatToParts(date).map(x => [x.type, x.value]));
  const wdMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  return { weekday: wdMap[p.weekday], hour: parseInt(p.hour, 10), minute: parseInt(p.minute, 10) };
}
function dentroDoHorario(date = new Date()) {
  // Le do flow.horario (editavel sem tocar no codigo). Feriado NAO tratado.
  const h = flow.horario || { inicio: '13:00', fim: '18:00', dias: [1, 2, 3, 4, 5, 6] };
  const { weekday, hour, minute } = partsInSP(date);
  if (!h.dias.includes(weekday)) return false;
  const [hi, mi] = h.inicio.split(':').map(Number);
  const [hf, mf] = h.fim.split(':').map(Number);
  const agora = hour * 60 + minute;
  return agora >= (hi * 60 + mi) && agora < (hf * 60 + mf);
}
const hoursMs = h => h * 60 * 60 * 1000;
const sleep   = ms => new Promise(r => setTimeout(r, ms));
const randDelay = () => 1000 + Math.floor(Math.random() * 2000); // 1-3s

// Limpeza periodica dos Sets de ids (evita crescer pra sempre)
function gcIds() {
  const cutoff = Date.now() - hoursMs(6);
  for (const [id, t] of sentIds) if (t < cutoff) sentIds.delete(id);
  for (const [id, t] of seenIds) if (t < cutoff) seenIds.delete(id);
}
setInterval(gcIds, hoursMs(1));

// ----------------------------------------------------------------------------
// Envio via Evolution API
// ----------------------------------------------------------------------------
async function sendText(jid, text) {
  const numero = jid.split('@')[0];
  if (DRY_RUN) { console.log(`[DRY_RUN -> ${numero}] ${text.slice(0, 60)}...`); return; }
  try {
    const res = await fetch(`${EVOLUTION_URL}/message/sendText/${INSTANCE}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', apikey: EVOLUTION_KEY },
      body: JSON.stringify({ number: numero, text }),
    });
    const body = await res.json().catch(() => ({}));
    // Guarda o id da mensagem que o BOT enviou, pra ignorar quando voltar como fromMe.
    const id = body?.key?.id;
    if (id) sentIds.set(id, Date.now());
    if (!res.ok) console.error(`[send] Evolution respondeu ${res.status}:`, JSON.stringify(body).slice(0, 200));
  } catch (e) {
    console.error('[send] erro ao falar com Evolution:', e.message);
  }
}

// Envia uma LISTA NATIVA do WhatsApp (o botao "Abrir Menu" que abre as opcoes).
async function sendList(jid, payload) {
  const numero = jid.split('@')[0];
  if (DRY_RUN) { console.log(`[DRY_RUN LIST -> ${numero}] ${payload.description?.slice(0, 45)} | rows: ${payload.sections[0].rows.map(r => r.rowId).join(',')}`); return; }
  try {
    const res = await fetch(`${EVOLUTION_URL}/message/sendList/${INSTANCE}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', apikey: EVOLUTION_KEY },
      body: JSON.stringify({ number: numero, ...payload }),
    });
    const body = await res.json().catch(() => ({}));
    const id = body?.key?.id;
    if (id) sentIds.set(id, Date.now());     // lista tambem volta como fromMe -> ignorar
    if (!res.ok) console.error(`[sendList] ${res.status}:`, JSON.stringify(body).slice(0, 200));
  } catch (e) {
    console.error('[sendList] erro:', e.message);
  }
}

// Envia respeitando: delay humano + revalidacao do flag HUMANO logo antes.
async function reply(jid, text) {
  await sleep(randDelay());
  const c = conversas.get(jid);
  if (c && c.state === STATES.HUMANO) {  // voce entrou durante o delay -> aborta
    console.log(`[reply] abortado, humano entrou em ${jid}`);
    return;
  }
  await sendText(jid, text);
}

// ----------------------------------------------------------------------------
// Marca conversa como humana (voce respondeu) e cancela timers
// ----------------------------------------------------------------------------
function marcarHumano(jid) {
  const c = conversas.get(jid) || {};
  if (c.timer) clearTimeout(c.timer);
  conversas.set(jid, {
    state: STATES.HUMANO,
    lastSeen: Date.now(),
    humanUntil: Date.now() + hoursMs(HUMAN_TTL_HOURS),
    timer: null,
  });
  saveStateThrottled();
  console.log(`[humano] ${jid} marcada como HUMANO (expira em ${HUMAN_TTL_HOURS}h)`);
}

// ----------------------------------------------------------------------------
// Timer: apos mostrar menu / enviar link, espera FOLLOWUP_HOURS e manda UMA
// mensagem (followup ou encerramento) e encerra. Nunca em loop.
// ----------------------------------------------------------------------------
function armarTimeout(jid, msgKey) {
  const c = conversas.get(jid);
  if (!c) return;
  if (c.timer) clearTimeout(c.timer);
  c.timer = setTimeout(async () => {
    const cur = conversas.get(jid);
    if (!cur || cur.state !== STATES.ACTIVE) return;
    await reply(jid, flow.mensagens[msgKey] || flow.mensagens.encerramento);
    cur.state = STATES.ENCERRADO;
    cur.timer = null;
  }, hoursMs(FOLLOWUP_HOURS));
}

const digitos = ['1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟'];

// Envia um menu. Por padrao NUMERADO em texto (robusto). Se USE_NATIVE_LIST=true e
// o Baileys da versao suportar lista nativa sem quebrar, usa a lista. Hoje (v2.3.7)
// a lista quebra em ListMessage.toObject (bug do Baileys/long), entao o padrao e numerado.
async function enviarMenu(jid, menuId) {
  const menu = flow.menus[menuId];
  await sleep(randDelay());
  const c = conversas.get(jid);
  if (c && c.state === STATES.HUMANO) return;   // voce entrou durante o delay

  if (USE_NATIVE_LIST) {
    const rows = menu.opcoes.map(o => {
      const row = { title: o.label.slice(0, 24), rowId: o.goto };
      if (o.desc) row.description = o.desc;
      return row;
    });
    await sendList(jid, {
      title: menu.header || 'Arlete Vieira Confeitaria',
      description: menu.body,
      buttonText: menu.button || 'Abrir Menu',
      footerText: 'Arlete Vieira Confeitaria 💛',
      sections: [{ title: menu.section || 'Opções', rows }],
    });
    return;
  }

  // Menu numerado (padrao). O cliente responde com o numero.
  const linhas = menu.opcoes.map((o, i) => `${digitos[i] || (i + 1) + '.'} ${o.label}`);
  await sendText(jid, `${menu.body}\n\n${linhas.join('\n')}`);
}

// Executa o destino de uma opcao: outro menu, link de PDF, texto, ou atendente.
async function executarDestino(jid, c, target) {
  if (flow.menus[target]) {                 // vai pra outro menu
    c.node = target;
    if (c.timer) { clearTimeout(c.timer); c.timer = null; }
    await enviarMenu(jid, target);
    armarTimeout(jid, 'encerramento');
    return;
  }
  const node = flow.nodes[target];
  if (!node) { await enviarMenu(jid, flow.start); c.node = flow.start; return; }

  if (node.tipo === 'link') {
    await reply(jid, `${node.texto}\n${node.url}`);
    c.node = '__aguardando__';
    armarTimeout(jid, 'followup');
  } else if (node.tipo === 'texto') {
    await reply(jid, node.texto);
    c.node = '__aguardando__';
    armarTimeout(jid, 'followup');
  } else if (node.tipo === 'atendente') {
    // AQUI e o unico ponto que checa horario (fiel ao ManyChat: o menu abre sempre,
    // so o atendimento humano respeita o horario). Fora do horario -> ausencia.
    if (c.timer) { clearTimeout(c.timer); c.timer = null; }
    if (dentroDoHorario()) {
      await reply(jid, node.texto);
      c.state = STATES.PARADO;              // bot cala; espera voce responder (fromMe)
    } else {
      await reply(jid, flow.mensagens.ausencia);
      c.state = STATES.ENCERRADO;          // pode mandar de novo e reabrir o menu
    }
  }
}

// ----------------------------------------------------------------------------
// Nucleo: processa UMA mensagem inbound ja normalizada
//   msg = { jid, fromMe, id, text, isGroup }
// ----------------------------------------------------------------------------
async function processar(msg) {
  const { jid, fromMe, id, text, rowId } = msg;

  // Idempotencia: webhook pode duplicar
  if (id && seenIds.has(id)) return;
  if (id) seenIds.set(id, Date.now());

  // --- REGRA 1: fromMe (so conta se NAO for envio do proprio bot) --------
  if (fromMe) {
    if (id && sentIds.has(id)) return;      // foi o bot; ignora
    marcarHumano(jid);                      // foi voce, no celular/web; bot se cala
    return;
  }

  let c = conversas.get(jid);

  // --- REGRA 2: conversa ja humana (dentro do TTL) -> ignora tudo --------
  if (c && c.state === STATES.HUMANO) {
    if (c.humanUntil && c.humanUntil > Date.now()) { c.lastSeen = Date.now(); return; }
    conversas.delete(jid); c = null;        // TTL expirou: esquece, vira elegivel de novo
  }

  // --- REGRA 2b: passado pra humano -> bot fica calado ate voce responder
  if (c && c.state === STATES.PARADO) { c.lastSeen = Date.now(); return; }

  // OBS: o horario NAO bloqueia o menu (fiel ao ManyChat). A checagem de horario
  // acontece so nos nos de atendimento humano (ver executarDestino).

  // --- REGRA 4: sem estado OU inativo > MENU_RESET_HOURS -> saudacao+menu
  const expirou = c && (Date.now() - c.lastSeen) > hoursMs(MENU_RESET_HOURS);
  if (!c || expirou || c.state === STATES.ENCERRADO) {
    c = { state: STATES.ACTIVE, node: flow.start, lastSeen: Date.now(), timer: null };
    conversas.set(jid, c);
    if (flow.saudacao) await reply(jid, flow.saudacao.replace('{{nome}}', (msg.pushName || '').split(' ')[0] || 'tudo bem?'));
    await enviarMenu(jid, flow.start);
    armarTimeout(jid, 'encerramento');
    return;
  }

  // --- REGRA 5: ACTIVE -> escolha por toque (rowId) ou por numero --------
  c.lastSeen = Date.now();

  // Toque numa lista nativa: o rowId ja carrega o destino.
  if (rowId && (flow.menus[rowId] || flow.nodes[rowId])) {
    await executarDestino(jid, c, rowId);
    return;
  }

  const menu = flow.menus[c.node];
  if (!menu) {                              // estava em no de conteudo/aguardando -> reabre menu principal
    await enviarMenu(jid, flow.start);
    c.node = flow.start;
    armarTimeout(jid, 'encerramento');
    return;
  }
  const escolha = parseInt((text || '').trim(), 10);
  const opt = menu.opcoes[escolha - 1];
  if (!opt) {
    await reply(jid, flow.mensagens.opcao_invalida);
    await enviarMenu(jid, c.node);
    return;
  }
  await executarDestino(jid, c, opt.goto);
}

// Serializa mensagens do MESMO chat (cliente manda 5 seguidas sem race)
function enfileirar(msg) {
  const prev = chatQueue.get(msg.jid) || Promise.resolve();
  const next = prev.then(() => processar(msg)).catch(e => console.error('[processar]', e));
  chatQueue.set(msg.jid, next);
  next.finally(() => { if (chatQueue.get(msg.jid) === next) chatQueue.delete(msg.jid); });
}

// ----------------------------------------------------------------------------
// Normalizacao do payload do Evolution (o ponto mais provavel de quebrar —
// confirme na doc da versao que voce subir; extrator defensivo abaixo).
// ----------------------------------------------------------------------------
function extrairTexto(m) {
  const msg = m?.message;
  if (!msg) return null;
  return msg.conversation
      || msg.extendedTextMessage?.text
      || msg.ephemeralMessage?.message?.extendedTextMessage?.text
      || null;
}
// Quando o cliente TOCA numa opcao da lista nativa, o WhatsApp devolve o rowId escolhido.
function extrairRowId(m) {
  const msg = m?.message;
  if (!msg) return null;
  return msg.listResponseMessage?.singleSelectReply?.selectedRowId
      || msg.interactiveResponseMessage?.nativeFlowResponseMessage?.paramsJson && (() => {
           try { return JSON.parse(msg.interactiveResponseMessage.nativeFlowResponseMessage.paramsJson).id; } catch { return null; }
         })()
      || null;
}
function normalizar(data) {
  const jid = data?.key?.remoteJid;
  if (!jid) return null;
  const isGroup = jid.endsWith('@g.us') || jid === 'status@broadcast';
  return {
    jid,
    isGroup,
    fromMe: !!data?.key?.fromMe,
    id: data?.key?.id,
    pushName: data?.pushName || '',
    text: extrairTexto(data),
    rowId: extrairRowId(data),
  };
}

// ----------------------------------------------------------------------------
// Alerta de reconexao (plugue Telegram/e-mail aqui)
// ----------------------------------------------------------------------------
function notifyOwner(texto) {
  console.warn(`[ALERTA] ${texto}`);
  // TODO: fetch pro Telegram Bot API / e-mail. Quero saber se o numero cair.
}

// ----------------------------------------------------------------------------
// HTTP: recebe webhook do Evolution
// ----------------------------------------------------------------------------
const app = express();
app.use(express.json({ limit: '25mb' })); // Evolution v2.3.7 manda payloads grandes; 1mb dava 413 e ele retentava

app.get('/health', (_req, res) => res.json({ ok: true, conversas: conversas.size }));

// Aceita /webhook E /webhook/<evento> — versoes novas do Evolution (v2.3+) anexam
// o nome do evento no caminho (ex: /webhook/messages-upsert). Cobrimos os dois.
app.post(['/webhook', '/webhook/:evento'], (req, res) => {
  res.sendStatus(200); // responde rapido; processa async
  const evt = req.body || {};
  // O evento pode vir no corpo (evt.event) ou so no caminho (ex: 'messages-upsert')
  const event = (evt.event || evt.type || req.params.evento || '').replace(/-/g, '.');

  if (event === 'connection.update') {
    const st = evt.data?.state || evt.data?.connection;
    connState = st || connState;   // vai no proximo heartbeat pra HostGator saber
    console.log(`[conexao] ${st}`);
    if (st === 'close' || st === 'closed') notifyOwner('Evolution desconectou do WhatsApp — reparear pode ser necessario.');
    return;
  }

  if (event !== 'messages.upsert') return;

  // data pode ser objeto unico ou array, dependendo da versao
  const items = Array.isArray(evt.data) ? evt.data : [evt.data];
  for (const d of items) {
    const msg = normalizar(d);
    if (!msg) continue;
    if (msg.isGroup) continue;               // ignora grupos e status
    enfileirar(msg);
  }
});

// ----------------------------------------------------------------------------
// Heartbeat: empurra sinal de vida + status da conexao pra HostGator
// ----------------------------------------------------------------------------
async function sendHeartbeat() {
  if (!HEARTBEAT_URL) return;
  try {
    await fetch(HEARTBEAT_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ secret: HEARTBEAT_SECRET, status: connState, ts: Date.now() }),
    });
  } catch (e) {
    // Sem internet o heartbeat falha — e exatamente isso que a HostGator vai notar (silencio).
    console.error('[heartbeat] falhou:', e.message);
  }
}

// ----------------------------------------------------------------------------
// Boot
// ----------------------------------------------------------------------------
loadState();
app.listen(PORT, () => {
  console.log(`Bot doceria ouvindo em :${PORT}  (DRY_RUN=${DRY_RUN}, TZ=${TZ})`);
  console.log(`Horario comercial agora? ${dentroDoHorario() ? 'ABERTO' : 'FECHADO'}`);
  if (HEARTBEAT_URL) {
    sendHeartbeat();
    setInterval(sendHeartbeat, HEARTBEAT_MIN * 60 * 1000);
    console.log(`[heartbeat] ativo -> ${HEARTBEAT_URL} a cada ${HEARTBEAT_MIN}min`);
  } else {
    console.log('[heartbeat] desativado (defina HEARTBEAT_URL no .env pra ativar)');
  }
});

// Exporta pra testes (mock)
module.exports = { app, processar, normalizar, dentroDoHorario, conversas };
