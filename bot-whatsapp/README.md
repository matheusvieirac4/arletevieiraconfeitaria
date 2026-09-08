# Bot de primeiro atendimento — Arlete Vieira Confeitaria

Bot que responde o **primeiro contato** no WhatsApp com um menu numerado e **sai de cena assim que voce entra** na conversa. Roda ao lado do seu WhatsApp Web/celular no mesmo numero, via Evolution API (dispositivo vinculado, igual WhatsApp Web).

> **Escopo:** responde inbound, entrega info de categoria, se cala quando humano entra. Nao faz CRM, IA, disparo em massa nem dashboard — de proposito.

## Arquitetura

```
Cliente ─▶ WhatsApp ─▶ Evolution API (Docker) ─webhook─▶ bot.js ─▶ Evolution ─▶ Cliente
                                                  ▲
                            voce responde pelo celular/web (fromMe) ─┘  => bot se cala
```

- **Evolution** mantem o WebSocket com o WhatsApp (por isso precisa de VPS, nao hospedagem compartilhada).
- **bot.js**: 1 arquivo, estado em `Map` + espelho em disco so do flag "humano", textos em `flow.json`, sob PM2 ou no proprio compose.

## Por que os detalhes importam (leia antes de mexer)

1. **`fromMe` tambem vem das mensagens do proprio bot.** O bot registra o `id` de tudo que envia e ignora esses `fromMe` — so o `fromMe` que ele **nao** enviou (voce, no celular) marca a conversa como humana. Sem isso o bot se mataria ao mandar o proprio menu.
2. **O flag "humano" expira** (`HUMAN_TTL_HOURS`, padrao 18h). Cliente antigo que volta semanas depois recebe o menu de novo, em vez de ficar mudo pra sempre.
3. **O flag "humano" e persistido em `data/state.json`.** Se o container reinicia no meio de um atendimento, o bot nao volta a atropelar sua conversa ao vivo.

---

## Passo a passo — subir na VPS

### 1. VPS
Ubuntu 22.04+, 1 vCPU / 2GB ja bastam. Aponte o firewall pra liberar a porta `8080` (Evolution) apenas pro seu IP, se possivel.

### 2. Docker
```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # reloga depois
```

### 3. Clonar e configurar
```bash
git clone <SEU_REPO> doceria-bot && cd doceria-bot/bot-whatsapp
cp .env.example .env
nano .env    # preencha EVOLUTION_API_KEY, SERVER_URL (http://SEU_IP:8080), POSTGRES_PASSWORD
```

### 4. Subir
```bash
docker compose up -d --build
docker compose logs -f bot    # acompanhe
```

### 5. Criar a instancia e parear o numero
Crie a instancia (uma vez):
```bash
curl -X POST http://SEU_IP:8080/instance/create \
  -H "apikey: SUA_EVOLUTION_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"instanceName":"arlete","integration":"WHATSAPP-BAILEYS","qrcode":true}'
```
Pegue o QR Code:
```bash
curl http://SEU_IP:8080/instance/connect/arlete -H "apikey: SUA_EVOLUTION_API_KEY"
```
Abra o Evolution Manager em `http://SEU_IP:8080/manager` (mais facil de ler o QR), e no celular:
**WhatsApp ▸ Aparelhos conectados ▸ Conectar um aparelho ▸ escaneie o QR.**

> Isso ocupa 1 dos 4 slots de dispositivo. Seu celular e o WhatsApp Web continuam funcionando no mesmo numero.

### 6. Confirmar
- `docker compose logs -f bot` deve mostrar `Bot doceria ouvindo em :3000`.
- Mande "oi" de outro numero pro seu WhatsApp: deve chegar o menu.
- Responda pelo celular: o bot deve parar de responder aquela conversa.

---

## Testar localmente SEM WhatsApp real

Nao precisa de VPS nem Evolution pra validar a logica:

```bash
npm install
# Terminal 1 — bot em modo DRY_RUN (nao chama o Evolution, so loga o que enviaria)
DRY_RUN=true node bot.js
# PowerShell:  $env:DRY_RUN='true'; node bot.js

# Terminal 2 — dispara cenarios de webhook falso
node mock/send-webhook.js menu       # primeiro contato
node mock/send-webhook.js fluxo      # menu -> submenu -> categoria
node mock/send-webhook.js humano     # voce responde (fromMe) e o bot se cala
node mock/send-webhook.js flood      # 5 msgs seguidas -> 1 menu so
node mock/send-webhook.js grupo      # mensagem de grupo -> ignorada
```

Assista o Terminal 1: cada `[DRY_RUN -> ...]` e uma mensagem que o bot mandaria.

---

## Editar os textos
So `flow.json`. As chaves em `opcoes` sao os numeros que o cliente digita. No compose ele entra por volume read-only — edite e rode `docker compose restart bot`.

> **Dica do seu handoff:** antes de recriar os 7 ramos de categoria, olhe as tags do ManyChat. Se a maioria cai em 2 categorias ou vai direto pro atendente, comece com 3 opcoes. Mudar depois e so editar o JSON.

## Operacao
- **Logs:** `docker compose logs -f bot`
- **Reiniciar so o bot:** `docker compose restart bot`
- **Health:** `curl http://localhost:3000/health`
- **Alerta de queda:** o handler `notifyOwner()` em `bot.js` esta como stub — plugue um Telegram/e-mail pra ser avisado se o numero desconectar.

## Monitor de queda (rodando no PC de casa)

O PC de casa cai quando falta luz/internet. O monitor avisa por e-mail quando isso acontece — sem precisar abrir porta no roteador, porque o **bot empurra** um heartbeat pra HostGator e o cron de la so observa o silencio.

```
PC (bot) --heartbeat 2min--> HostGator/bot-monitor/heartbeat.php  (grava timestamp+status)
                                    ^
              cron 5min: heartbeat-check.php -> sem sinal? WhatsApp deslogou? -> e-mail
```

**Passos:**
1. Os arquivos PHP estao em `../bot-monitor/` (na raiz do repo) e sobem pra HostGator pelo auto-deploy.
2. Edite `bot-monitor/config.php`: `HB_SECRET` (um segredo longo), `HB_ALERT_EMAIL`, `HB_FROM_EMAIL` (use um e-mail do seu dominio).
3. No `.env` do bot, aponte `HEARTBEAT_URL=https://SEU_DOMINIO/bot-monitor/heartbeat.php` e `HEARTBEAT_SECRET=` (o MESMO valor do `HB_SECRET`).
4. No painel da HostGator, crie um **Cron Job** a cada 5 min:
   ```
   */5 * * * * php /home/SEU_USUARIO/public_html/bot-monitor/heartbeat-check.php
   ```
   (o caminho exato do `php` e da pasta aparece no painel de cron da HostGator).

O e-mail chega so quando o estado MUDA (queda, WhatsApp desconectado, ou normalizou) — nao spamma.

## Riscos conhecidos
- **Ban (Baileys e engenharia reversa).** Baixo neste desenho (so inbound, zero disparo). Plano B: `docker compose stop bot` e voltar ao manual.
- **Payload do webhook muda entre versoes do Evolution.** A imagem esta pinada (`v2.1.1`). Se trocar, confirme o shape com o mock antes de por no ar. O extrator de texto em `bot.js` (`extrairTexto`) e o ponto a revisar.
- **Feriado nao e tratado** — em feriado o bot dira que esta aberto no horario normal.
