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
    foreach (['company_id', 'refresh_token'] as $k) {
        if (empty($cfg[$k]) || strpos((string) $cfg[$k], 'COLOQUE_') === 0) {
            return false;
        }
    }
    return true;
}

/** Arquivo onde o refresh_token rotacionado é persistido (fora do Git). */
function financeiro_token_state_path(): string
{
    return __DIR__ . '/data/financeiro_token.json';
}

/** Instância do cliente da API a partir da config. Lança se não configurado. */
function financeiro_api(): CardapioWebApi
{
    $cfg = financeiro_config();
    if (!$cfg) {
        throw new CardapioWebApiException('Integração não configurada. Crie admin/config_financeiro.php.');
    }
    return CardapioWebApi::fromConfig($cfg, financeiro_token_state_path());
}

// --------------------------------------------- Helpers de cadastros ---

/** Normaliza a resposta de um lookup (pode vir como [...] ou {"data":[...]}). */
function financeiro_extrair_lista($resp): array
{
    if (isset($resp['data']) && is_array($resp['data'])) {
        return $resp['data'];
    }
    return is_array($resp) ? $resp : [];
}

/** Extrai os nomes (campo "name") de um lookup, ordenados. */
function financeiro_nomes($resp): array
{
    $nomes = [];
    foreach (financeiro_extrair_lista($resp) as $item) {
        if (is_array($item) && isset($item['name']) && $item['name'] !== '') {
            $nomes[] = (string) $item['name'];
        }
    }
    $nomes = array_values(array_unique($nomes));
    sort($nomes, SORT_NATURAL | SORT_FLAG_CASE);
    return $nomes;
}

// ------------------- Regras por fornecedor (classificação aprendida) ---
// A ferramenta memoriza como cada fornecedor (chave = CNPJ) foi classificado
// da última vez, para pré-preencher as próximas notas dele automaticamente.

function financeiro_regras_path(): string
{
    return __DIR__ . '/data/financeiro_regras.json';
}

function financeiro_regras_listar(): array
{
    $path = financeiro_regras_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** Busca a classificação memorizada para um fornecedor (por CNPJ). */
function financeiro_regra_buscar(string $cnpj): ?array
{
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if ($cnpj === '') {
        return null;
    }
    $regras = financeiro_regras_listar();
    return $regras[$cnpj] ?? null;
}

/** Memoriza/atualiza a classificação de um fornecedor após um envio. */
function financeiro_regra_salvar(string $cnpj, string $nome, array $campos): bool
{
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if ($cnpj === '') {
        return false; // sem CNPJ não dá para memorizar com segurança
    }
    $regras = financeiro_regras_listar();
    $regras[$cnpj] = [
        'fornecedor'     => $nome,
        'account'        => $campos['account'] ?? '',
        'category'       => $campos['category'] ?? '',
        'cost_center'    => $campos['cost_center'] ?? '',
        'payment_method' => $campos['payment_method'] ?? '',
        'atualizado_em'  => date('c'),
    ];

    $path = financeiro_regras_path();
    $dir  = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    return file_put_contents(
        $path,
        json_encode($regras, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    ) !== false;
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
