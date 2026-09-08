'use strict';
/**
 * Teste end-to-end com Evolution MOCKADO (sem WhatsApp real).
 * Sobe um "Evolution falso" que grava os sendText recebidos, sobe o bot apontado
 * pra ele (DRY_RUN=false, envio REAL de HTTP), injeta um "oi" e verifica que o bot
 * chamou o sendText com a saudacao + o menu. Cobre o caminho de envio que o
 * smoke-test (funcoes puras) e o DRY_RUN nao exercitam.
 *
 * Rode: npm run test:e2e   (ou: node mock/e2e-test.js)
 * Sai 0 se passar, 1 se falhar.
 */
const http = require('http');
const { spawn } = require('child_process');
const path = require('path');

const EVO_PORT = 8099, BOT_PORT = 4090;
const enviados = [];   // bodies dos sendText recebidos pelo Evolution falso

// --- Evolution falso ---
const evo = http.createServer((req, res) => {
  let body = '';
  req.on('data', c => (body += c));
  req.on('end', () => {
    if (req.url.includes('/message/sendText/')) {
      try { enviados.push(JSON.parse(body)); } catch {}
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ key: { id: 'FAKE_' + enviados.length } }));
    }
    if (req.url.includes('/instance/connectionState/')) {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      return res.end(JSON.stringify({ instance: { state: 'open' } }));
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end('{}');
  });
});

const sleep = ms => new Promise(r => setTimeout(r, ms));
let bot;
function fim(falhou, msg) {
  console.log((falhou ? ' FALHA ' : '  OK   ') + msg);
  try { bot && bot.kill(); } catch {}
  try { evo.close(); } catch {}
  process.exit(falhou ? 1 : 0);
}

(async () => {
  await new Promise(r => evo.listen(EVO_PORT, r));

  // Sobe o bot apontado pro Evolution falso (envio HTTP real, sem DRY_RUN).
  bot = spawn('node', ['bot.js'], {
    cwd: path.join(__dirname, '..'),
    env: { ...process.env, DRY_RUN: 'false', PORT: String(BOT_PORT),
      EVOLUTION_URL: `http://localhost:${EVO_PORT}`, EVOLUTION_API_KEY: 'x',
      INSTANCE_NAME: 'arlete', HEARTBEAT_URL: '' },
    stdio: 'ignore',
  });

  await sleep(1500);   // boot

  // Injeta um "oi" de um cliente.
  const payload = JSON.stringify({ event: 'messages.upsert', data: {
    key: { remoteJid: '5511999998888@s.whatsapp.net', fromMe: false, id: 'E2E1' },
    pushName: 'Cliente', message: { conversation: 'oi' } } });
  await new Promise((resolve, reject) => {
    const r = http.request({ host: 'localhost', port: BOT_PORT, path: '/webhook', method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } },
      res => { res.on('data', () => {}); res.on('end', resolve); });
    r.on('error', reject); r.write(payload); r.end();
  });

  await sleep(7000);   // aguarda os 2 envios (cada um tem delay aleatorio 1-3s)

  // Verificacoes
  if (enviados.length < 2) return fim(true, `esperava >=2 sendText, recebi ${enviados.length}`);
  const textos = enviados.map(e => e.text || '');
  const temSaudacao = textos.some(t => t.includes('Olá'));
  const temMenu = textos.some(t => t.includes('selecione a opção'));
  const numeroCerto = enviados.every(e => e.number === '5511999998888');
  if (!temSaudacao) return fim(true, 'nao enviou a saudacao');
  if (!temMenu) return fim(true, 'nao enviou o menu principal');
  if (!numeroCerto) return fim(true, 'numero de destino errado em algum envio');
  fim(false, `e2e: bot enviou saudacao + menu via sendText (${enviados.length} chamadas) ✅`);
})().catch(e => fim(true, 'erro no teste: ' + e.message));
