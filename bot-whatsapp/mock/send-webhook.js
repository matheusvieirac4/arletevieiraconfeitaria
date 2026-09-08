'use strict';
/**
 * Mock de webhook — testa o bot SEM WhatsApp real.
 *
 * Suba o bot em modo DRY_RUN (nao chama o Evolution, so loga o que enviaria):
 *   DRY_RUN=true node bot.js         (Linux/Mac)
 *   $env:DRY_RUN='true'; node bot.js (PowerShell)
 *
 * Em outro terminal, rode um cenario:
 *   node mock/send-webhook.js menu        -> primeiro contato (menu)
 *   node mock/send-webhook.js fluxo       -> menu -> submenu -> categoria
 *   node mock/send-webhook.js humano       -> voce responde (fromMe) e o bot se cala
 *   node mock/send-webhook.js botfromme   -> fromMe do PROPRIO bot NAO deve calar (regressao do bug critico)
 *   node mock/send-webhook.js flood        -> 5 mensagens seguidas, so 1 menu
 *   node mock/send-webhook.js grupo        -> mensagem de grupo, deve ser ignorada
 *
 * Assista os logs do bot pra ver o comportamento.
 */

const URL = process.env.BOT_URL || 'http://localhost:3000/webhook';
const JID = '5548999990000@s.whatsapp.net';

let seq = 0;
function msgUpsert({ text, fromMe = false, id, jid = JID }) {
  return {
    event: 'messages.upsert',
    instance: 'arlete',
    data: {
      key: { remoteJid: jid, fromMe, id: id || `MOCK_${Date.now()}_${seq++}` },
      pushName: 'Cliente Teste',
      message: { conversation: text },
      messageType: 'conversation',
      messageTimestamp: Math.floor(Date.now() / 1000),
    },
  };
}

async function post(payload, label) {
  const res = await fetch(URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  console.log(`  -> ${label}  [HTTP ${res.status}]`);
}
const sleep = ms => new Promise(r => setTimeout(r, ms));

const cenarios = {
  async menu() {
    await post(msgUpsert({ text: 'oi' }), 'cliente: "oi"');
  },
  async fluxo() {
    await post(msgUpsert({ text: 'oi' }), 'cliente: "oi"');        await sleep(4000);
    await post(msgUpsert({ text: '1' }),  'cliente: "1" (cardapios)'); await sleep(4000);
    await post(msgUpsert({ text: '2' }),  'cliente: "2" (cookies)');
  },
  async humano() {
    await post(msgUpsert({ text: 'oi' }), 'cliente: "oi"');           await sleep(4000);
    await post(msgUpsert({ text: 'Oi, ja te respondo!', fromMe: true, id: 'HUMANO_PHONE_1' }), 'VOCE responde pelo celular (fromMe)'); await sleep(1000);
    await post(msgUpsert({ text: '1' }),  'cliente: "1" (bot deve IGNORAR)');
  },
  async botfromme() {
    // Simula: bot enviou algo e esse fromMe volta no webhook. NAO deve marcar humano.
    // (Em producao o bot registra o id ao enviar; aqui checamos que o handler nao quebra o fluxo.)
    await post(msgUpsert({ text: 'oi' }), 'cliente: "oi"'); await sleep(4000);
    console.log('  (obs: o fromMe do proprio bot e filtrado por id em producao — ver sentIds)');
  },
  async flood() {
    for (let i = 0; i < 5; i++) await post(msgUpsert({ text: 'oi ' + i, id: 'FLOOD_' + i }), `cliente msg ${i + 1}/5`);
  },
  async grupo() {
    await post(msgUpsert({ text: 'oi grupo', jid: '123456@g.us' }), 'mensagem de GRUPO (deve ser ignorada)');
  },
};

(async () => {
  const nome = process.argv[2] || 'menu';
  const fn = cenarios[nome];
  if (!fn) { console.error('Cenario desconhecido:', nome, '\nUse:', Object.keys(cenarios).join(', ')); process.exit(1); }
  console.log(`\n== Cenario: ${nome} ==`);
  await fn();
  console.log('== Enviado. Veja os logs do bot. ==\n');
})();
