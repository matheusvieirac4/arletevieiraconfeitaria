<?php
// Rodado pelo CRON da HostGator (ex: a cada 5 min).
// Olha "faz quanto tempo que nao recebo heartbeat?" e avisa por e-mail se sumir
// ou se o WhatsApp desconectar. Com dedupe: so manda e-mail quando o estado MUDA
// (nao spamma a cada 5 min) e manda um e-mail de "voltou" quando normaliza.
require __DIR__ . '/config.php';

$agora = time();

// 1. Descobre o problema atual (se houver)
$problema = null;   // null = tudo ok
if (!file_exists(HB_STATE_FILE)) {
    $problema = 'sem-heartbeat';
} else {
    $st       = json_decode(file_get_contents(HB_STATE_FILE), true) ?: [];
    $lastSeen = (int)($st['last_seen'] ?? 0);
    $status   = (string)($st['status'] ?? 'unknown');
    $idade    = $agora - $lastSeen;

    if ($idade > HB_OFFLINE_SECONDS) {
        $problema = 'offline';          // PC/internet caiu: parou de mandar sinal
    } elseif ($status !== 'open') {
        $problema = 'whatsapp-desconectado';  // processo vivo, mas numero deslogou
    }
}

// 2. Compara com o ultimo estado alertado (dedupe)
$prev = file_exists(HB_ALERT_FILE)
    ? (json_decode(file_get_contents(HB_ALERT_FILE), true)['problema'] ?? null)
    : null;

// 3. Se nao mudou nada, encerra sem e-mail
if ($problema === $prev) {
    echo "sem mudanca (" . ($problema ?? 'ok') . ")\n";
    exit;
}

// 4. Estado mudou -> monta e envia o e-mail
$mapa = [
    'sem-heartbeat'         => 'O bot NUNCA reportou (heartbeat inexistente). Ele ja subiu?',
    'offline'              => 'O bot esta OFFLINE ha mais de ' . (HB_OFFLINE_SECONDS / 60) . ' min. Provavel queda de luz/internet no PC.',
    'whatsapp-desconectado' => 'O bot esta rodando, mas o WhatsApp DESCONECTOU. Pode precisar reparear o QR Code.',
];

if ($problema === null) {
    $assunto = '[Bot Doceria] ✅ Normalizou';
    $corpo   = "O bot voltou a reportar normalmente em " . date('d/m/Y H:i', $agora) . ".";
} else {
    $assunto = '[Bot Doceria] ⚠️ ' . strtoupper(str_replace('-', ' ', $problema));
    $corpo   = ($mapa[$problema] ?? 'Problema desconhecido.')
             . "\n\nHorario: " . date('d/m/Y H:i', $agora)
             . "\n\n(Plano B: atenda no manual ate voltar.)";
}

$headers = 'From: ' . HB_FROM_EMAIL . "\r\n" . 'Content-Type: text/plain; charset=UTF-8';
@mail(HB_ALERT_EMAIL, $assunto, $corpo, $headers);

// 5. Guarda o novo estado pro proximo dedupe
file_put_contents(HB_ALERT_FILE, json_encode(['problema' => $problema, 'em' => $agora]), LOCK_EX);

echo "alerta enviado: " . ($problema ?? 'ok') . "\n";
