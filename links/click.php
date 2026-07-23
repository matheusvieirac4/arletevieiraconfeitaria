<?php

require_once __DIR__ . '/../includes/links_bio.php';

$links = links_bio_mapa_urls();

$slug = isset($_GET['l']) ? $_GET['l'] : '';
if (!isset($links[$slug])) {
    http_response_code(404);
    exit('Link nao encontrado.');
}

date_default_timezone_set('America/Sao_Paulo');

$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$source = isset($_GET['s']) ? $_GET['s'] : '';

$device = 'desktop';
if (preg_match('/mobile|android|iphone|ipad|ipod|windows phone/i', $userAgent)) {
    $device = 'mobile';
}

$record = [
    'timestamp' => date('c'),
    'link' => $slug,
    'source' => substr((string) $source, 0, 120),
    'device' => $device,
    'ip' => $ip,
    'referer' => substr($referer, 0, 300),
    'user_agent' => substr($userAgent, 0, 300)
];

$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0775, true);
}

$logFile = $logsDir . '/clicks.jsonl';
$line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($line !== false) {
    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

header('Location: ' . $links[$slug], true, 302);
exit;
