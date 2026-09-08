'use strict';
/**
 * Teste comportamental da regra de horario (fidelidade nº1 ao ManyChat):
 *   - o MENU abre a qualquer hora (fora do horario tambem);
 *   - so o no de atendimento humano respeita o horario -> fora dele, manda ausencia.
 * Usa um flow de teste (via FLOW_FILE) com horario SEMPRE FECHADO e captura os
 * envios em DRY_RUN. Roda: npm run test:hours
 */
const fs = require('fs');
const os = require('os');
const path = require('path');

// flow real + horario sempre fechado (dias vazio => dentroDoHorario sempre false)
const real = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'flow.json'), 'utf8'));
real.horario = { inicio: '00:00', fim: '00:00', dias: [] };
const fixture = path.join(os.tmpdir(), 'flow-closed-test.json');
fs.writeFileSync(fixture, JSON.stringify(real));

process.env.DRY_RUN = 'true';
process.env.FLOW_FILE = fixture;
const bot = require('../bot.js');

const enviados = [];
const orig = console.log;
console.log = (...a) => { const s = a.join(' '); if (s.startsWith('[DRY_RUN')) enviados.push(s); else orig(...a); };

const msg = (text, id) => ({ jid: '5511900000000@s.whatsapp.net', fromMe: false, id, text, rowId: null, pushName: 'Teste' });
const sleep = ms => new Promise(r => setTimeout(r, ms));

let falhas = 0;
const ok = (cond, nome) => { orig((cond ? '  OK  ' : ' FALHA') + ' ' + nome); if (!cond) falhas++; };

(async () => {
  await bot.processar(msg('oi', 'H1'));          // fora do horario -> deve abrir o menu
  await sleep(7000);
  const abriuMenu = enviados.some(s => s.includes('selecione a opção'));
  const naoDeuAusenciaAqui = !enviados.some(s => s.includes('temporariamente'));
  ok(abriuMenu, 'menu abre mesmo FORA do horario');
  ok(naoDeuAusenciaAqui, 'primeiro contato NAO cai em ausencia');

  enviados.length = 0;
  await bot.processar(msg('5', 'H2'));            // Atendimento Humano fora do horario -> ausencia
  await sleep(4000);
  const deuAusencia = enviados.some(s => s.includes('temporariamente'));
  ok(deuAusencia, 'atendente FORA do horario -> mensagem de ausencia');

  // Anti-loop: depois da ausencia, a cliente segue mandando msgs (como no caso real).
  // O bot NAO pode re-saudar/remandar menu — deve ficar calado (PARADO).
  enviados.length = 0;
  await bot.processar(msg('Bom dia!', 'H3'));
  await bot.processar(msg('Ainda tem vaga sabado? 300 salgados...', 'H4'));
  await sleep(4000);
  ok(enviados.length === 0, 'apos ausencia, bot fica calado (nao re-sauda em loop)');

  console.log = orig;
  try { fs.unlinkSync(fixture); } catch {}
  orig(falhas ? `\n${falhas} teste(s) falharam.` : '\nRegra de horario OK. ✅');
  process.exit(falhas ? 1 : 0);
})();
