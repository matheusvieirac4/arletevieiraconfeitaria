<?php
// Config do monitor do bot. Ajuste os 3 valores abaixo.

// Mesmo segredo do HEARTBEAT_SECRET no .env do bot.
const HB_SECRET = '65b8ee8a431a2e0f9f594fc2bf2ddf6dc7ab737c9e9770fc';

// Pra onde o alerta de queda vai.
const HB_ALERT_EMAIL = 'matheusvieirac4@gmail.com';
const HB_FROM_EMAIL  = 'no-reply@arletevieiraconfeitaria.com.br';   // e-mail do seu dominio (evita cair no spam)

// Se o ultimo heartbeat for mais velho que isso, considera o bot OFFLINE.
// Deixe folga: bot manda a cada 2min; 8min tolera um atraso sem falso alarme.
const HB_OFFLINE_SECONDS = 8 * 60;

// Arquivos de estado (na mesma pasta; protegidos pelo .htaccess)
const HB_STATE_FILE = __DIR__ . '/hb-last.json';   // ultimo heartbeat recebido
const HB_ALERT_FILE = __DIR__ . '/hb-alert.json';  // estado do alerta (dedupe)
