<?php
require_once __DIR__ . '/lib/cardapioweb_api.php';

// ---------------------------------------------------------------- Config ---

/** Caminho do arquivo de config real (fora do Git). */
function financeiro_config_path(): string
{
    return __DIR__ . '/config_financeiro.php';
}

/** Devolve a config, ou null se ainda não foi criada. */
function financeiro_config(): ?array
{
    $path = financeiro_config_path();
    if (!is_file($path)) {
        return null;
    }
    $cfg = require $path;
    return is_array($cfg) ? $cfg : null;
}

/** True quando a config existe e está preenchida (sem placeholders). */
function financeiro_configurado(): bool
{
    $cfg = financeiro_config();
    if (!$cfg) {
        return false;
    }
    foreach (['firebase_api_key', 'company_id', 'refresh_token'] as $k) {
        if (empty($cfg[$k]) || strpos((string) $cfg[$k], 'COLOQUE_') === 0) {
            return false;
        }
    }
    return true;
}

/** Instância do cliente da API a partir da config. Lança se não configurado. */
function financeiro_api(): CardapioWebApi
{
    $cfg = financeiro_config();
    if (!$cfg) {
        throw new CardapioWebApiException('Integração não configurada. Crie admin/config_financeiro.php.');
    }
    return CardapioWebApi::fromConfig($cfg);
}

// ------------------------------- Registro de notas processadas (dedup) ---

function financeiro_registro_path(): string
{
    return __DIR__ . '/data/financeiro_processados.json';
}

/** Lê o registro (mapa chave-de-acesso => metadados). */
function financeiro_registro_listar(): array
{
    $path = financeiro_registro_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** Já importamos essa nota (por chave de acesso da NF-e)? */
function financeiro_ja_processada(string $chave): bool
{
    $reg = financeiro_registro_listar();
    return isset($reg[$chave]);
}

/** Marca a nota como processada APÓS confirmação de sucesso no envio. */
function financeiro_marcar_processada(string $chave, array $meta = []): bool
{
    $reg = financeiro_registro_listar();
    $reg[$chave] = $meta + ['importado_em' => date('c')];

    $path = financeiro_registro_path();
    $dir  = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    return file_put_contents(
        $path,
        json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) !== false;
}
