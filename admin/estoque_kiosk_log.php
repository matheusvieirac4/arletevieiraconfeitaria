<?php
// Log de diagnóstico do leitor do quiosque. Só grava quando o quiosque é aberto
// com ?debug=1&log=1. Uma linha por leitura, em admin/data/kiosk_debug.log.
// GET (sem parâmetros) devolve o conteúdo do log pra baixar/copiar.
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/model_estoque.php';
// Mesmo acesso do quiosque: admin logado OU token do quiosque.
if (!estoque_kiosk_autorizado()) { http_response_code(403); echo 'sem acesso'; exit; }

$dir = __DIR__ . '/data';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
$arq = $dir . '/kiosk_debug.log';

// Baixar/ler o log: estoque_kiosk_log.php  (ou ?raw=1 pra texto puro)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['limpar'] ?? '') === '1') { @unlink($arq); echo "Log apagado.\n"; exit; }
    echo is_file($arq) ? file_get_contents($arq) : "(log vazio)\n";
    exit;
}

// Gravar uma leitura
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo 'bad'; exit; }

$s = fn($k, $max = 60) => substr(preg_replace('/[\r\n\t]+/', ' ', (string) ($body[$k] ?? '')), 0, $max);
$linha = sprintf(
    "%s | %-18s | %-16s | %-8s | frame %4sms | busca %6sms | %s\n",
    date('Y-m-d H:i:s'),
    $s('codigo', 24),
    $s('modo', 20),
    $s('formato', 12),
    (string) (int) ($body['ms'] ?? 0),
    (string) (int) ($body['busca'] ?? 0),
    $s('cam', 30)
);

// Limita o arquivo a ~500 KB (rotação simples).
if (is_file($arq) && filesize($arq) > 500 * 1024) {
    $keep = array_slice(file($arq), -1000);
    file_put_contents($arq, implode('', $keep));
}
file_put_contents($arq, $linha, FILE_APPEND | LOCK_EX);
echo 'ok';
