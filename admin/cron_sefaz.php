<?php
// ============================================================================
// Puxador automático de NF-e do SEFAZ (distribuição por NSU).
// Rode pelo cron do cPanel:   php /home/USUARIO/.../admin/cron_sefaz.php
// Ou por URL protegida:        /admin/cron_sefaz.php?token=SEU_CRON_TOKEN
// Baixa NF-e novas e enfileira em "recebidas" para você revisar no admin.
// ============================================================================

require_once __DIR__ . '/model_financeiro.php';

$cli = php_sapi_name() === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $cfg = financeiro_config() ?: [];
    $token = (string) ($cfg['cron_token'] ?? '');
    if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        echo "Acesso negado.\n";
        exit;
    }
}

if (!financeiro_sefaz_configurado()) {
    echo date('c') . " — SEFAZ não configurado (defina cert_path/cert_password em config_financeiro.php).\n";
    exit;
}

try {
    $r = financeiro_sefaz_puxar();
    echo date('c') . " OK — novas: {$r['novas']} | cStat {$r['cStat']} ({$r['xMotivo']}) | "
        . "ultNSU {$r['ultNSU']} / maxNSU {$r['maxNSU']} | páginas {$r['paginas']}\n";
} catch (\Throwable $e) {
    echo date('c') . " ERRO: " . $e->getMessage() . "\n";
    exit(1);
}
