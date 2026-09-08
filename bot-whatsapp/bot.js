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

// Alerta redundante por Telegram (opcional). Se vazio, notifyOwner so loga.
const TELEGRAM_TOKEN    = process.env.TELEGRAM_BOT_TOKEN || '';
const TELEGRAM_CHAT     = process.env.TELEGRAM_CHAT_ID || '';

const STATE_FILE        = path.join(__dirname, 'data', 'state.json');
const FLOW_FILE         = process.env.FLOW_FILE || path.join(__dirname, 'flow.json');  // override p/ testes

// ----------------------------------------------------------------------------
// Conteudo editavel (flow.json)
// ----------------------------------------------------------------------------
// Carrega o flow.json com erro CLARO se o JSON estiver invalido — voce vai editar
// esse arquivo pra mudar precos/textos, e uma virgula a mais nao deve virar um
// stack trace cru num loop de restart.
let flow;
try {
  flow = JSON.parse(fs.readFileSync(FLOW_FILE, 'utf8'));
} catch (e) {
  console.error(`[flow] ERRO ao ler/parsear ${FLOW_FILE}: ${e.message}`);
  console.error('[flow] Corrija o JSON (virgula/aspas/chave) e reinicie. O bot nao sobe com flow invalido.');
  process.exit(1);
}

// Valida a integridade do flow.json: todo 'goto' aponta pra um menu/node existente,
// o 'start' existe, e os textos das mensagens estao presentes. Loga problemas no boot
// (falha cedo, em vez de quebrar na frente do cliente). Retorna lista de problemas.
function validarFlow() {
  const probs = [];
  const alvos = new Set([...Object.keys(flow.menus || {}), ...Object.keys(flow.nodes || {})]);
  if (!flow.start || !flow.menus?.[flow.start]) probs.push(`start invalido: "${flow.start}"`);
  for (const [id, menu] of Object.entries(flow.menus || {})) {
    if (!menu.body) probs.push(`menu "${id}" sem body`);
    if (!Array.isArray(menu.opcoes) || !menu.opcoes.length) probs.push(`menu "${id}" sem opcoes`);
    // Limites do WhatsApp (importam se USE_NATIVE_LIST): max 10 linhas, titulo <=24 chars.
    if ((menu.opcoes || []).length > 10) probs.push(`menu "${id}" tem ${menu.opcoes.length} opcoes (lista nativa suporta no maximo 10)`);
    (menu.opcoes || []).forEach((o, i) => {
      if (!o.label) probs.push(`menu "${id}" opcao ${i + 1} sem label`);
      if (o.label && o.label.length > 24) probs.push(`menu "${id}" opcao "${o.label}" tem ${o.label.length} chars (lista nativa corta em 24)`);
      if (!alvos.has(o.goto)) probs.push(`menu "${id}" opcao "${o.label}" aponta pra goto inexistente: "${o.goto}"`);
    });
  }
  for (const [id, node] of Object.entries(flow.nodes || {})) {
    if (!['link', 'texto', 'atendente', 'perguntas'].includes(node.tipo)) probs.push(`node "${id}" tipo invalido: "${node.tipo}"`);
    if (node.tipo === 'link' && !node.url) probs.push(`node "${id}" (link) sem url`);
    if (node.tipo === 'perguntas') {
      if (!Array.isArray(node.perguntas) || !node.perguntas.length) probs.push(`node "${id}" (perguntas) sem lista de perguntas`);
      (node.perguntas || []).forEach((p, i) => { if (!p.chave || !p.texto) probs.push(`node "${id}" pergunta ${i + 1} sem chave/texto`); });
    } else if (!node.texto && node.tipo !== 'link') {
      probs.push(`node "${id}" sem texto`);
    }
  }
  for (const k of ['opcao_invalida', 'ausencia', 'encerramento']) {
    if (!flow.mensagens?.[k]) probs.push(`mensagens.${k} ausente`);
  }
  for (const [kw, alvo] of Object.entries(flow.atalhos || {})) {
    if (!alvos.has(alvo)) probs.push(`atalho "${kw}" aponta pra destino inexistente: "${alvo}"`);
  }
  return probs;
}

// ----------------------------------------------------------------------------
// Estado em memoria
//   conversas: jid -> { state, lastSeen, timer, humanUntil }
//   sentIds:   ids de mensagens que O BOT enviou (pra ignorar o proprio fromMe)
//   seenIds:   idempotencia — messageIds ja processados
// ----------------------------------------------------------------------------
// ACTIVE = navegando a arvore de menus; PARADO = passado pra humano (bot cala ate voce responder)
// COLETANDO = coletando respostas de um no 'perguntas' (captura guiada de pedido)
const STATES = { ACTIVE: 'ACTIVE', COLETANDO: 'COLETANDO', PARADO: 'PARADO', HUMANO: 'HUMANO', ENCERRADO: 'ENCERRADO' };
const conversas = new Map();
const sentIds   = new Map();   // id -> timestamp (expira)
const seenIds   = new Map();   // id -> timestamp (expira)
const chatQueue = new Map();   // jid -> Promise (serializa mensagens do mesmo chat)
let connState   = 'unknown';   // estado da conexao Evolution<->WhatsApp (vai no heartbeat)
const metricas  = { desde: Date.now(), atendimentosIniciados: 0, handoffs: 0, foraDoHorario: 0, pedidosCapturados: 0 };  // contadores desde o boot

// --- Persistencia dos estados que NAO podem ser perdidos num restart --------
// HUMANO (voce respondeu) e PARADO (menu passou pra humano): se sumirem num
// restart, o bot volta a atropelar sua conversa ao vivo com o menu. Salvamos os
// dois. Menu em navegacao (ACTIVE) pode se perder sem dano (cliente reve o menu).
function loadState() {
  try {
    const raw = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
    const now = Date.now();
    for (const [jid, humanUntil] of Object.entries(raw.humanos || {})) {
      if (humanUntil > now) conversas.set(jid, { state: STATES.HUMANO, lastSeen: now, humanUntil, timer: null });
    }
    // PARADO expira pela mesma janela do reset de menu (fica elegivel de novo depois).
    for (const [jid, lastSeen] of Object.entries(raw.parados || {})) {
      if ((now - lastSeen) < hoursMs(MENU_RESET_HOURS)) conversas.set(jid, { state: STATES.PARADO, lastSeen, timer: null });
    }
    console.log(`[state] restauradas do disco: ${Object.keys(raw.humanos || {}).length} humana(s), ${Object.keys(raw.parados || {}).length} parada(s)`);
  } catch { /* primeiro boot: arquivo nao existe, tudo bem */ }
}
function escreverState() {
  const humanos = {}, parados = {};
  for (const [jid, c] of conversas) {
    if (c.state === STATES.HUMANO && c.humanUntil) humanos[jid] = c.humanUntil;
    else if (c.state === STATES.PARADO) parados[jid] = c.lastSeen;
  }
  try {
    fs.mkdirSync(path.dirname(STATE_FILE), { recursive: true });
    fs.writeFileSync(STATE_FILE, JSON.stringify({ humanos, parados }, null, 0));
  } catch (e) { console.error('[state] falha ao salvar:', e.message); }
}
let saveTimer = null;
function saveStateThrottled() {
  if (saveTimer) return;
  saveTimer = setTimeout(() => { saveTimer = null; escreverState(); }, 2000);
}
// Flush sincrono no desligamento: um PARADO/HUMANO gravado nos ultimos 2s (janela
// do debounce) nao pode se perder num restart — seria justo o caso perigoso.
function saveStateNow() {
  if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
  escreverState();
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

// Chave canonica de um numero BR: DDD + 8 digitos, ignorando DDI (55) e o 9o
// digito de celular — pra casar "5548991689995", "554891689995", "(48) 99168-9995".
function chaveNumero(s) {
  let d = (s || '').split('@')[0].replace(/\D/g, '');
  if (d.startsWith('55') && d.length > 11) d = d.slice(2);   // tira DDI
  return d.slice(0, 2) + d.slice(-8);                        // DDD + assinante (8)
}
// Contatos que o bot NUNCA atende (parceiros/fornecedores) — de flow.ignorar.
const ignorarSet = new Set((flow.ignorar || []).map(chaveNumero));

// Normaliza texto pra casar atalho: minusculo, sem acento, sem pontuacao.
const normalizarTexto = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w\s]/g, ' ').replace(/\s+/g, ' ').trim();
// Atalho por palavra-chave: se a msg contem uma keyword (palavra inteira), roteia
// direto pro destino. So pra mensagens curtas (evita falso positivo em frases).
function matchAtalho(text) {
  if (!flow.atalhos || !text) return null;
  const t = normalizarTexto(text);
  if (!t || /^\d+$/.test(t)) return null;               // numero e escolha de menu, nao atalho
  const palavras = new Set(t.split(/\s+/));
  if (palavras.size > 4) return null;                   // frase longa: nao chuta atalho
  for (const [kw, alvo] of Object.entries(flow.atalhos)) {
    const k = normalizarTexto(kw);
    if (t === k || palavras.has(k)) return alvo;
  }
  return null;
}

// Limpeza periodica dos Sets de ids (evita crescer pra sempre)
function gcIds() {
  const cutoff = Date.now() - hoursMs(6);
  for (const [id, t] of sentIds) if (t < cutoff) sentIds.delete(id);
  for (const [id, t] of seenIds) if (t < cutoff) seenIds.delete(id);
}
setInterval(gcIds, hoursMs(1));

// Varredura de conversas expiradas: sem isso, flags HUMANO/PARADO ficam no Map
// (e no state.json) ate um restart — vaza memoria com muitos clientes ao longo do
// tempo. Remove HUMANO com TTL vencido e qualquer conversa inativa alem da janela.
function gcConversas(now = Date.now()) {
  // Inclui FOLLOWUP_HOURS: senao o gc removeria a conversa (e o timer) ANTES do
  // follow-up de 23h disparar, deixando o nudge como codigo morto.
  const limiteInatividade = hoursMs(Math.max(HUMAN_TTL_HOURS, MENU_RESET_HOURS, FOLLOWUP_HOURS) + 1);
  let removidos = 0;
  for (const [jid, c] of conversas) {
    const humanoVencido = c.state === STATES.HUMANO && c.humanUntil && c.humanUntil <= now;
    const inativo = (now - (c.lastSeen || 0)) > limiteInatividade;
    if (humanoVencido || inativo) {
      if (c.timer) clearTimeout(c.timer);
      conversas.delete(jid);
      removidos++;
    }
  }
  if (removidos) { console.log(`[gc] ${removidos} conversa(s) expirada(s) removida(s)`); saveStateThrottled(); }
  return removidos;
}
setInterval(() => gcConversas(), hoursMs(1));

// ----------------------------------------------------------------------------
// Envio via Evolution API
// ----------------------------------------------------------------------------
async function sendText(jid, text) {
  const numero = jid.split('@')[0];
  if (DRY_RUN) { console.log(`[DRY_RUN -> ${numero}] ${text.slice(0, 60)}...`); return; }
  // Retenta em falha de rede ou erro 5xx (blip transitorio). NAO retenta 4xx
  // (ex.: numero inexistente) — retentar nao ajuda e so atrasa.
  for (let tentativa = 1; tentativa <= 3; tentativa++) {
    try {
      const res = await fetch(`${EVOLUTION_URL}/message/sendText/${INSTANCE}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', apikey: EVOLUTION_KEY },
        body: JSON.stringify({ number: numero, text }),
      });
      const body = await res.json().catch(() => ({}));
      const id = body?.key?.id;
      if (id) sentIds.set(id, Date.now());   // pra ignorar quando voltar como fromMe
      if (res.ok) return;
      if (res.status < 500) {                 // erro do cliente: nao adianta retentar
        console.error(`[send] Evolution respondeu ${res.status}:`, JSON.stringify(body).slice(0, 200));
        return;
      }
      console.error(`[send] ${res.status} (tentativa ${tentativa}/3), retentando...`);
    } catch (e) {
      console.error(`[send] erro de rede (tentativa ${tentativa}/3): ${e.message}`);
    }
    if (tentativa < 3) await sleep(1000 * tentativa);   // backoff 1s, 2s
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
    if (!cur || (cur.state !== STATES.ACTIVE && cur.state !== STATES.COLETANDO)) return;
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

// Handoff pra humano — UNICO ponto que decide a mensagem por horario, usado por
// TODOS os fluxos que passam pra atendente (atendente, meu pedido, captura).
// Dentro do horario: manda opts.dentro. Fora: manda opts.fora (padrao: ausencia)
// e marca foraHorario, pra confirmar "recebemos" na proxima msg da cliente.
async function handoff(jid, c, opts = {}) {
  if (c.timer) { clearTimeout(c.timer); c.timer = null; }
  c.state = STATES.PARADO;
  if (dentroDoHorario()) {
    if (opts.dentro) await reply(jid, opts.dentro);
    c.foraHorario = false;
    metricas.handoffs++;
  } else {
    await reply(jid, opts.fora || flow.mensagens.ausencia);
    c.foraHorario = true;
    c.ackDado = !!opts.jaAckou;           // true se a msg 'fora' ja confirma o recebimento
    metricas.foraDoHorario++;
  }
  saveStateThrottled();
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
  } else if (node.tipo === 'perguntas') {
    // Captura guiada: pergunta uma por vez, junta as respostas e monta um resumo.
    if (c.timer) { clearTimeout(c.timer); c.timer = null; }
    c.state = STATES.COLETANDO;
    c.coleta = { nodeId: target, idx: 0, respostas: {} };
    if (node.intro) await reply(jid, node.intro);
    await reply(jid, node.perguntas[0].texto);
    armarTimeout(jid, 'encerramento');   // se abandonar no meio, encerra
  } else if (node.tipo === 'atendente') {
    // Passa pra humano. O horario e tratado no handoff (menu abre sempre; so o
    // atendimento respeita o horario). Fora do horario manda a ausencia e fica
    // PARADO (nao ENCERRADO — senao re-saudava em loop); a confirmacao de
    // "recebemos" vai na proxima mensagem da cliente (ver Regra 2b).
    await handoff(jid, c, { dentro: node.texto });
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

  // Lista de "nunca atender": parceiros/fornecedores nao passam por triagem.
  // O bot fica 100% mudo com esses contatos (nem responde, nem marca estado).
  if (ignorarSet.has(chaveNumero(jid))) return;

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

  // --- REGRA 2b: passado pra humano -> bot fica calado ate voce responder.
  // Mas expira pela janela do reset de menu: cliente que volta dias depois
  // recebe o menu de novo, em vez de ficar mudo pra sempre.
  if (c && c.state === STATES.PARADO) {
    if ((Date.now() - c.lastSeen) < hoursMs(MENU_RESET_HOURS)) {
      c.lastSeen = Date.now();
      // Passada pra humano FORA do horario: confirma UMA vez que recebemos a
      // mensagem dela (ManyChat fazia isso). Depois, silencio ate voce responder.
      if (c.foraHorario && !c.ackDado && (text || '').trim()) {
        c.ackDado = true;
        await reply(jid, flow.mensagens.recebido || flow.mensagens.ausencia);
      }
      saveStateThrottled();
      return;
    }
    conversas.delete(jid); c = null;        // PARADO velho: esquece, vira elegivel
  }

  // --- REGRA 2c: coletando respostas (captura guiada de pedido) ---------
  if (c && c.state === STATES.COLETANDO && c.coleta) {
    c.lastSeen = Date.now();
    const node = flow.nodes[c.coleta.nodeId];
    const pergunta = node.perguntas[c.coleta.idx];
    const resposta = (text || '').trim();
    if (!resposta) {                        // audio/imagem no meio da coleta -> re-pergunta
      await reply(jid, flow.mensagens.midia_recebida || 'Me responde em texto, por favor. 🙂');
      await reply(jid, pergunta.texto);
      return;
    }
    c.coleta.respostas[pergunta.chave] = resposta;
    c.coleta.idx++;
    if (c.coleta.idx < node.perguntas.length) {   // proxima pergunta
      await reply(jid, node.perguntas[c.coleta.idx].texto);
      return;
    }
    // terminou: monta resumo, confirma e passa pro humano com tudo organizado
    const r = c.coleta.respostas;
    const resumo = node.perguntas.map(p => `• *${p.rotulo || p.chave}:* ${r[p.chave]}`).join('\n');
    await reply(jid, `📋 *Seu pedido, resumido:*\n${resumo}`);
    c.coleta = null;
    metricas.pedidosCapturados++;
    // Fecho por horario: dentro -> node.final ("em breve respondemos"); fora ->
    // confirma o recebimento ("recebemos, respondemos quando retomarmos").
    await handoff(jid, c, { dentro: node.final, fora: flow.mensagens.recebido, jaAckou: true });
    return;
  }

  // OBS: o horario NAO bloqueia o menu (fiel ao ManyChat). A checagem de horario
  // acontece so nos nos de atendimento humano (ver executarDestino).

  // --- REGRA 4: sem estado OU inativo > MENU_RESET_HOURS -> saudacao+menu
  const expirou = c && (Date.now() - c.lastSeen) > hoursMs(MENU_RESET_HOURS);
  if (!c || expirou || c.state === STATES.ENCERRADO) {
    c = { state: STATES.ACTIVE, node: flow.start, lastSeen: Date.now(), timer: null };
    conversas.set(jid, c);
    metricas.atendimentosIniciados++;
    if (flow.saudacao) await reply(jid, flow.saudacao.replace('{{nome}}', (msg.pushName || '').split(' ')[0] || 'tudo bem?'));
    // Se o 1o contato ja for uma palavra-chave ("cardapio", "atendente"...), pula direto.
    const alvoInicial = matchAtalho(text);
    if (alvoInicial) { await executarDestino(jid, c, alvoInicial); }
    else { await enviarMenu(jid, flow.start); armarTimeout(jid, 'encerramento'); }
    return;
  }

  // --- REGRA 5: ACTIVE -> escolha por toque (rowId) ou por numero --------
  c.lastSeen = Date.now();

  // Toque numa lista nativa: o rowId ja carrega o destino.
  if (rowId && (flow.menus[rowId] || flow.nodes[rowId])) {
    c.erros = 0;
    await executarDestino(jid, c, rowId);
    return;
  }

  // Atalho por palavra-chave (funciona em qualquer sub-estado ativo).
  const alvoAtalho = matchAtalho(text);
  if (alvoAtalho) {
    c.erros = 0;
    await executarDestino(jid, c, alvoAtalho);
    return;
  }

  const menu = flow.menus[c.node];
  if (!menu) {                              // estava em no de conteudo/aguardando -> reabre menu principal
    await enviarMenu(jid, flow.start);
    c.node = flow.start;
    armarTimeout(jid, 'encerramento');
    return;
  }
  // Mensagem sem texto (audio/imagem/figurinha) e sem toque: nao da pra parsear
  // numero. Responde gentil e reabre o menu, em vez do seco "opcao invalida".
  if (!text || !text.trim()) {
    await reply(jid, flow.mensagens.midia_recebida || flow.mensagens.opcao_invalida);
    await enviarMenu(jid, c.node);
    return;
  }
  // So conta como escolha de menu se a mensagem for SO um numero de 1-2 digitos
  // (aceita emoji keycap "1️⃣", ponto, parenteses). "1 bolo 30 fatias..." NAO e
  // escolha — senao o parseInt agarra o "1" e manda pro cardapio (bug real).
  const digs = text.replace(/\D/g, '');
  const soNumero = digs.length >= 1 && digs.length <= 2 &&
                   text.replace(/\d/g, '').replace(/[️⃣\s.)]/g, '') === '';
  const opt = soNumero ? menu.opcoes[parseInt(digs, 10) - 1] : undefined;
  if (!opt) {
    // Nao e numero valido. Se parece um RECADO/PEDIDO (texto longo ou multi-linha),
    // o cliente esta escrevendo o pedido em vez de navegar — passa pro humano em vez
    // de re-empurrar o menu (foi a frustracao real de clientes que mandaram o pedido
    // inteiro). Heuristica de tamanho, nao NLP. Fora do horario, confirma "recebemos".
    const t = text.trim();
    const pareceRecado = /\n/.test(text) || t.length > 40 || t.split(/\s+/).length >= 6;
    if (pareceRecado && flow.nodes.atendente) {
      c.erros = 0;
      await handoff(jid, c, { dentro: flow.nodes.atendente.texto, fora: flow.mensagens.recebido, jaAckou: true });
      return;
    }
    // Transbordo: se o cliente erra o menu 2x seguidas, passa pra um atendente em
    // vez de repetir o menu pra sempre (evita frustracao de quem nao entende).
    c.erros = (c.erros || 0) + 1;
    if (c.erros >= 2 && flow.nodes.atendente) {
      c.erros = 0;
      await handoff(jid, c, { dentro: flow.mensagens.transbordo || flow.nodes.atendente.texto, fora: flow.mensagens.recebido, jaAckou: true });
      return;
    }
    await reply(jid, flow.mensagens.opcao_invalida);
    await enviarMenu(jid, c.node);
    return;
  }
  c.erros = 0;
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
// Alerta ao dono. Canal redundante ao monitor da HostGator: se TELEGRAM_BOT_TOKEN
// e TELEGRAM_CHAT_ID estiverem no .env, manda no Telegram (chega instantaneo no
// celular). Se nao, so loga. Dedupe simples pra nao repetir o mesmo alerta em loop.
// ----------------------------------------------------------------------------
let ultimoAlerta = { texto: null, em: 0 };
async function notifyOwner(texto) {
  console.warn(`[ALERTA] ${texto}`);
  // Dedupe: nao repete o mesmo alerta dentro de 10 min.
  if (texto === ultimoAlerta.texto && (Date.now() - ultimoAlerta.em) < hoursMs(1 / 6)) return;
  ultimoAlerta = { texto, em: Date.now() };
  if (!TELEGRAM_TOKEN || !TELEGRAM_CHAT) return;
  try {
    await fetch(`https://api.telegram.org/bot${TELEGRAM_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ chat_id: TELEGRAM_CHAT, text: `🤖 Bot Doceria: ${texto}` }),
    });
  } catch (e) {
    console.error('[notifyOwner] falha no Telegram:', e.message);
  }
}

// ----------------------------------------------------------------------------
// HTTP: recebe webhook do Evolution
// ----------------------------------------------------------------------------
const app = express();
app.use(express.json({ limit: '25mb' })); // Evolution v2.3.7 manda payloads grandes; 1mb dava 413 e ele retentava

app.get('/health', (_req, res) => res.json({
  ok: true,
  conexao: connState,
  conversas: conversas.size,
  uptimeSec: Math.round(process.uptime()),
  horarioAberto: dentroDoHorario(),
  metricas,
}));

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
    if (st === 'close' || st === 'closed') {
      notifyOwner('Evolution desconectou do WhatsApp — reparear pode ser necessario.');
      sendHeartbeat();   // empurra o estado 'close' JA, pra HostGator alertar sem esperar o ciclo
    }
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

// Semeia/atualiza o connState consultando o Evolution. Evita FALSO ALARME de
// "WhatsApp desconectado" logo apos um restart (quando ainda nao chegou o evento
// connection.update e o connState estaria 'unknown').
async function fetchConnState() {
  if (DRY_RUN) return;
  try {
    const res = await fetch(`${EVOLUTION_URL}/instance/connectionState/${INSTANCE}`, { headers: { apikey: EVOLUTION_KEY } });
    const body = await res.json().catch(() => ({}));
    const st = body?.instance?.state;
    if (st) connState = st;
  } catch (e) {
    console.error('[connState] falha ao consultar Evolution:', e.message);
  }
}

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
// Boot — so quando executado direto (node bot.js). Se for importado (testes),
// nao sobe o servidor nem os timers, so expoe as funcoes.
// ----------------------------------------------------------------------------
function boot() {
  // Desligamento gracioso: flush do estado antes de sair (docker stop manda SIGTERM).
  for (const sig of ['SIGTERM', 'SIGINT']) {
    process.on(sig, () => {
      console.log(`[shutdown] ${sig} recebido, salvando estado...`);
      saveStateNow();
      process.exit(0);
    });
  }
  loadState();
  const problemasFlow = validarFlow();
  if (problemasFlow.length) {
    console.error(`[flow] ${problemasFlow.length} problema(s) no flow.json:`);
    problemasFlow.forEach(p => console.error('  - ' + p));
    // 'start' invalido quebra o menu inteiro -> fatal explicito (em vez de menu vazio silencioso).
    if (!flow.start || !flow.menus?.[flow.start]) {
      console.error('[flow] FATAL: "start" invalido — sem menu inicial o bot nao atende. Corrija e reinicie.');
      process.exit(1);
    }
  } else {
    console.log('[flow] flow.json valido.');
  }
  app.listen(PORT, () => {
    console.log(`Bot doceria ouvindo em :${PORT}  (DRY_RUN=${DRY_RUN}, TZ=${TZ})`);
    console.log(`Horario comercial agora? ${dentroDoHorario() ? 'ABERTO' : 'FECHADO'}`);
    if (HEARTBEAT_URL) {
      // Semeia o connState real antes do 1o heartbeat (evita falso alarme pos-restart),
      // e reconsulta periodicamente como rede de seguranca caso um evento se perca.
      fetchConnState().then(sendHeartbeat);
      setInterval(sendHeartbeat, HEARTBEAT_MIN * 60 * 1000);
      setInterval(fetchConnState, 5 * 60 * 1000);
      console.log(`[heartbeat] ativo -> ${HEARTBEAT_URL} a cada ${HEARTBEAT_MIN}min`);
    } else {
      console.log('[heartbeat] desativado (defina HEARTBEAT_URL no .env pra ativar)');
    }
  });
}
if (require.main === module) boot();

// Exporta pra testes (mock/smoke-test.js)
module.exports = { app, processar, normalizar, dentroDoHorario, extrairTexto, extrairRowId, validarFlow, gcConversas, chaveNumero, conversas };
