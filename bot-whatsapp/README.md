# Bot de primeiro atendimento — Arlete Vieira Confeitaria

Bot que responde o **primeiro contato** no WhatsApp com um menu numerado e **sai de cena assim que voce entra** na conversa. Roda ao lado do seu WhatsApp Web/celular no mesmo numero, via Evolution API (dispositivo vinculado, igual WhatsApp Web).

> **Escopo:** responde inbound, entrega os cardapios (PDFs) e passa pro humano quando pedido. Nao faz CRM, IA, disparo em massa nem dashboard — de proposito.

## Arquitetura

```
Cliente ─▶ WhatsApp ─▶ Evolution API (Docker) ─webhook─▶ bot.js ─▶ Evolution ─▶ Cliente
                                                  ▲
                            voce responde pelo celular/web (fromMe) ─┘  => bot se cala
```

- **Evolution** mantem o WebSocket com o WhatsApp — por isso roda numa maquina que fica de pe 24h (o **PC de casa** ou uma VPS), **nunca** em hospedagem compartilhada (nao roda processo persistente nem Docker).
- **bot.js**: 1 arquivo. Estado em `Map` na memoria + espelho em disco (`data/state.json`) dos estados que nao podem se perder num restart. Textos e menus em `flow.json`. Sobe no proprio `docker-compose`.
- Imagem Evolution: **`evoapicloud/evolution-api:latest`** (testado na v2.3.7). Versoes v2.3+ mandam o webhook em `/webhook/<evento>` — o bot aceita tanto `/webhook` quanto `/webhook/<evento>`.

## Por que os detalhes importam (leia antes de mexer)

1. **`fromMe` tambem vem das mensagens do proprio bot.** O bot registra o `id` de tudo que envia e ignora esses `fromMe` — so o `fromMe` que ele **nao** enviou (voce, no celular) marca a conversa como humana. Sem isso o bot se mataria ao mandar o proprio menu.
2. **O flag humano expira** (`HUMAN_TTL_HOURS`, padrao 18h). Cliente antigo que volta semanas depois recebe o menu de novo, em vez de ficar mudo pra sempre.
3. **Estados HUMANO e PARADO sao persistidos** em `data/state.json` (+ flush no desligamento). Se o container reinicia no meio de um atendimento, o bot **nao** volta a atropelar sua conversa ao vivo com o menu.
4. **O horario NAO bloqueia o menu.** Fiel ao ManyChat: o menu abre a qualquer hora; so os nos de atendimento humano respeitam o horario (`flow.horario`) e, fora dele, mandam a mensagem de ausencia.
5. **Menu e NUMERADO em texto.** A lista nativa do WhatsApp quebra no Baileys atual (`ListMessage.toObject`); fica atras do flag `USE_NATIVE_LIST=true` pra religar se um dia consertar.

---

## Subir (PC ou VPS)

### 1. Docker
No Windows: instale o **Docker Desktop** e deixe-o iniciar junto com o sistema. Em Linux/VPS:
```bash
curl -fsSL https://get.docker.com | sh && sudo usermod -aG docker $USER
```

### 2. Configurar
```bash
cd bot-whatsapp
cp .env.example .env
# edite .env: EVOLUTION_API_KEY, POSTGRES_PASSWORD, HEARTBEAT_URL/SECRET (opcional), TELEGRAM_* (opcional)
```

### 3. Subir
```bash
docker compose up -d --build
docker compose logs -f bot
```

### 4. Parear o numero (uma vez)
```bash
# cria a instancia
curl -X POST http://localhost:8080/instance/create \
  -H "apikey: SUA_EVOLUTION_API_KEY" -H "Content-Type: application/json" \
  -d '{"instanceName":"arlete","integration":"WHATSAPP-BAILEYS","qrcode":true}'
# pega o QR (base64) — ou abra o Manager em http://localhost:8080/manager
curl http://localhost:8080/instance/connect/arlete -H "apikey: SUA_EVOLUTION_API_KEY"
```
No celular: **WhatsApp ▸ Aparelhos conectados ▸ Conectar um aparelho ▸ escaneie o QR.** Ocupa 1 dos 4 slots; seu celular e o WhatsApp Web seguem funcionando no mesmo numero. Se der "tente mais tarde", e rate-limit por excesso de QR — espere ~10 min e gere **um** QR, escaneie na hora.

### 5. Confirmar
- Log mostra `Bot doceria ouvindo em :3000` e `[flow] flow.json valido.`
- Mande "oi" de outro numero: chega saudacao + menu.
- Responda pelo celular: o bot para de responder aquela conversa.

---

## Editar textos e menus — `flow.json`

Estrutura (arvore de menus + nos de conteudo):

```jsonc
{
  "saudacao": "texto com {{nome}} (primeiro nome do cliente)",
  "start": "menu_principal",
  "menus": {
    "menu_principal": {
      "body": "cabecalho do menu",
      "opcoes": [
        { "label": "Nosso Cardapio", "goto": "menu_cardapio" },   // goto = outro menu
        { "label": "Atendente", "goto": "atendente" }             // ou um node
      ]
    }
  },
  "nodes": {
    "cat_cupcakes": { "tipo": "link", "texto": "...", "url": "https://.../av_cupcakes.pdf" },
    "grupo_vip":    { "tipo": "texto", "texto": "..." },
    "atendente":    { "tipo": "atendente", "texto": "..." }   // passa pra humano (checa horario)
  },
  "mensagens": { "opcao_invalida": "...", "midia_recebida": "...", "ausencia": "...", "encerramento": "...", "followup": "..." },
  "horario": { "inicio": "13:00", "fim": "18:00", "dias": [1,2,3,4,5,6] }   // 0=dom .. 6=sab
}
```

O bot valida o `flow.json` no boot (goto orfao, campos faltando, limites de tamanho do WhatsApp) e lista problemas no log. `flow.json` entra por volume — edite e rode `docker compose restart bot`.

## Testar sem WhatsApp real

```bash
npm install
npm test           # smoke test: funcoes puras (flow valido, extrator, horario, filtros)
npm run test:e2e   # e2e: sobe Evolution falso e verifica o caminho de envio real

# cenarios manuais de webhook (bot em DRY_RUN noutro terminal):
DRY_RUN=true node bot.js
node mock/send-webhook.js menu|fluxo|humano|flood|grupo
```

## Operacao
- **Logs:** `docker compose logs -f bot`
- **Reiniciar:** `docker compose restart bot`  ·  **Parar (voltar ao manual):** `docker compose stop bot`
- **Health:** `curl http://localhost:3000/health` → `{conexao, conversas, uptimeSec, horarioAberto, metricas}`
- **Alertas de queda:** dois canais, ambos opcionais e redundantes —
  - **E-mail** via o monitor na HostGator (ver abaixo).
  - **Telegram** direto: preencha `TELEGRAM_BOT_TOKEN` e `TELEGRAM_CHAT_ID` no `.env` (avisa no celular quando o WhatsApp desconectar).

## Monitor de queda por e-mail (`bot-monitor/`, na HostGator)

O PC cai quando falta luz/internet. Como o bot fica atras do roteador, o modelo e **push**: o bot manda um heartbeat (com o status da conexao) a cada 2 min pra HostGator, e um cron de la avisa quando o sinal some ou o WhatsApp desconecta. Sem abrir porta no roteador.

```
PC (bot) --heartbeat 2min--> HostGator/bot-monitor/heartbeat.php  (grava timestamp+status)
                                    ^
              cron 5min: heartbeat-check.php -> sem sinal? WhatsApp deslogou? -> e-mail (so na mudanca)
```

1. Os PHP em `../bot-monitor/` sobem pra HostGator pelo auto-deploy (push na main → FTPS).
2. `bot-monitor/config.php`: `HB_SECRET`, `HB_ALERT_EMAIL`, `HB_FROM_EMAIL`.
3. `.env` do bot: `HEARTBEAT_URL=https://SEU_DOMINIO/bot-monitor/heartbeat.php` e `HEARTBEAT_SECRET` = mesmo `HB_SECRET`.
4. Cron na HostGator: `*/5 * * * * php /home/SEU_USUARIO/public_html/bot-monitor/heartbeat-check.php`

O bot semeia o status real da conexao no boot (consulta o Evolution), entao um restart nao dispara falso alarme.

## Riscos conhecidos
- **Ban (Baileys e engenharia reversa).** Baixo neste desenho (so inbound, zero disparo). Plano B: `docker compose stop bot` e voltar ao manual.
- **Payload do webhook muda entre versoes do Evolution.** Imagem pinada em `latest` (v2.3.7). Se trocar, rode `npm run test:e2e` e confira o extrator (`extrairTexto`/`extrairRowId`) antes de por no ar.
- **Feriado nao e tratado** — em feriado dentro do horario o atendimento sera oferecido normalmente.
