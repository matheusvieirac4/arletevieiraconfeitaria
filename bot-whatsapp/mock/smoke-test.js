'use strict';
/**
 * Teste de fumaca — valida as funcoes puras do bot sem subir o WhatsApp.
 * Rode: npm test  (ou: node mock/smoke-test.js)
 * Sai com codigo 0 se tudo passar, 1 se algo falhar.
 */
process.env.DRY_RUN = 'true';
const bot = require('../bot.js');

let falhas = 0;
function ok(cond, nome) {
  console.log((cond ? '  OK  ' : ' FALHA') + ' ' + nome);
  if (!cond) falhas++;
}

// 1) flow.json integro (nenhum goto/no orfao)
const probs = bot.validarFlow();
ok(probs.length === 0, 'flow.json valido' + (probs.length ? ' -> ' + probs.join('; ') : ''));

// 2) extrator de texto cobre os dois formatos
ok(bot.normalizar({ key: { remoteJid: 'x@s.whatsapp.net' }, message: { conversation: 'oi' } }).text === 'oi', 'extrai conversation');
ok(bot.normalizar({ key: { remoteJid: 'x@s.whatsapp.net' }, message: { extendedTextMessage: { text: '1' } } }).text === '1', 'extrai extendedTextMessage');

// 3) rowId de lista nativa
ok(bot.normalizar({ key: { remoteJid: 'x@s.whatsapp.net' }, message: { listResponseMessage: { singleSelectReply: { selectedRowId: 'menu_cardapio' } } } }).rowId === 'menu_cardapio', 'extrai rowId da lista');

// 4) filtro de grupo/broadcast
ok(bot.normalizar({ key: { remoteJid: '123@g.us' }, message: { conversation: 'x' } }).isGroup === true, 'detecta grupo (@g.us)');
ok(bot.normalizar({ key: { remoteJid: 'status@broadcast' }, message: {} }).isGroup === true, 'detecta status@broadcast');

// 5) horario comercial (SP = UTC-3): seg 15h aberto, dom fechado, seg 12h fechado
ok(bot.dentroDoHorario(new Date('2026-09-07T18:00:00Z')) === true, 'seg 15h SP = aberto');
ok(bot.dentroDoHorario(new Date('2026-09-07T15:00:00Z')) === false, 'seg 12h SP = fechado (antes das 13h)');
ok(bot.dentroDoHorario(new Date('2026-09-13T18:00:00Z')) === false, 'domingo = fechado');

// 6) gcConversas remove expiradas e preserva as recentes
bot.conversas.set('velha@s.whatsapp.net', { state: 'HUMANO', humanUntil: Date.now() - 1000, lastSeen: Date.now() - 99 * 3600e3, timer: null });
bot.conversas.set('nova@s.whatsapp.net', { state: 'PARADO', lastSeen: Date.now(), timer: null });
// conversa de 20h com follow-up pendente (23h) NAO pode ser removida antes do nudge
bot.conversas.set('followup@s.whatsapp.net', { state: 'ACTIVE', node: '__aguardando__', lastSeen: Date.now() - 20 * 3600e3, timer: null });
const removidos = bot.gcConversas();
ok(removidos === 1, 'gcConversas remove 1 expirada');
ok(bot.conversas.has('nova@s.whatsapp.net') && !bot.conversas.has('velha@s.whatsapp.net'), 'gcConversas preserva a recente e remove a velha');
ok(bot.conversas.has('followup@s.whatsapp.net'), 'gcConversas preserva conversa de 20h (follow-up de 23h ainda vai disparar)');

// 7) chaveNumero canoniza (tolera 9o digito e DDI) — base da lista de ignorados
const k = bot.chaveNumero('5548991689995');
ok(k === bot.chaveNumero('554891689995'), 'chaveNumero tolera 9o digito (com/sem)');
ok(k === bot.chaveNumero('(48) 99168-9995'), 'chaveNumero tolera formatacao e DDI');

console.log(falhas ? `\n${falhas} teste(s) falharam.` : '\nTodos os testes passaram. ✅');
process.exit(falhas ? 1 : 0);
