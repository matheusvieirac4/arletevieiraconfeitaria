'use strict';
/**
 * Teste comportamental do reconhecimento de recado/pedido (bug real de producao):
 *   - "1" puro -> escolha de menu (vai pro cardapio);
 *   - "1 bolo 30 fatias..." (texto longo/multi-linha) -> NAO e escolha; vai pro
 *     humano (handoff), em vez de "nao entendi" + menu.
 * Usa flow de teste (FLOW_FILE) com horario SEMPRE ABERTO e captura envios em DRY_RUN.
 * Roda: npm run test:recado
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

const real = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'flow.json'), 'utf8'));
real.horario = { inicio: '00:00', fim: '23:59', dias: [0, 1, 2, 3, 4, 5, 6] }; // sempre aberto
const fixture = path.join(os.tmpdir(), 'flow-recado-test.json');
fs.writeFileSync(fixture, JSON.stringify(real));

process.env.DRY_RUN = 'true';
process.env.FLOW_FILE = fixture;
const bot = require('../bot.js');

const enviados = [];
const orig = console.log;
console.log = (...a) => { const s = a.join(' '); if (s.startsWith('[DRY_RUN')) enviados.push(s); else orig(...a); };

const msg = (jid, text, id) => ({ jid, fromMe: false, id, text, rowId: null, pushName: 'Cliente' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let falhas = 0;
const ok = (cond, nome) => { orig((cond ? '  OK  ' : ' FALHA') + ' ' + nome); if (!cond) falhas++; };

(async () => {
  // A) recado longo comecando com "1" -> humano (nao cardapio, nao "nao entendi")
  await bot.processar(msg('a@s.whatsapp.net', 'oi', 'A1'));
  await sleep(6000);
  enviados.length = 0;
  await bot.processar(msg('a@s.whatsapp.net', '1 bolo 30 fatias brigadeiro\n40 brigadeiros\n30 cajuzinhos', 'A2'));
  await sleep(5000);
  ok(enviados.some(s => s.includes('já foi passado')), 'recado longo (comeca com 1) -> passa pro humano');
  ok(!enviados.some(s => s.includes('Não entendi')), 'recado longo NAO cai em "nao entendi"');
  ok(!enviados.some(s => s.includes('Perfeito')), 'recado longo NAO vai pro cardapio');

  // B) "1" puro -> cardapio (comportamento normal preservado)
  await bot.processar(msg('b@s.whatsapp.net', 'oi', 'B1'));
  await sleep(6000);
  enviados.length = 0;
  await bot.processar(msg('b@s.whatsapp.net', '1', 'B2'));
  await sleep(5000);
  ok(enviados.some(s => s.includes('Perfeito')), '"1" puro -> vai pro cardapio');

  console.log = orig;
  try { fs.unlinkSync(fixture); } catch {}
  orig(falhas ? `\n${falhas} teste(s) falharam.` : '\nReconhecimento de recado OK. ✅');
  process.exit(falhas ? 1 : 0);
})();
