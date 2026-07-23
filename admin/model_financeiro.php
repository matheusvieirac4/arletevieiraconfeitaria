<?php
require_once __DIR__ . '/lib/cardapioweb_api.php';
require_once __DIR__ . '/lib/gemini_client.php';
require_once __DIR__ . '/lib/nfe_parser.php';
require_once __DIR__ . '/lib/nfe_chave.php';
require_once __DIR__ . '/lib/sefaz_distribuicao.php';

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

/** True quando a IA (Gemini) está configurada. */
function financeiro_ia_configurada(): bool
{
    $cfg = financeiro_config();
    return $cfg && !empty($cfg['gemini_api_key']) && strpos((string) $cfg['gemini_api_key'], 'COLOQUE_') !== 0;
}

/** Cliente do Gemini a partir da config. Lança se a IA não estiver configurada. */
function financeiro_gemini(): GeminiClient
{
    $cfg = financeiro_config();
    if (!$cfg) {
        throw new GeminiException('Integração não configurada.');
    }
    return GeminiClient::fromConfig($cfg);
}

/** Lê os 5 cadastros do Cardápio Web e devolve as listas de nomes p/ a IA. */
function financeiro_contexto_cadastros(CardapioWebApi $api): array
{
    return [
        'contas'       => financeiro_nomes($api->listarContas()),
        'categorias'   => financeiro_nomes($api->listarCategorias()),
        'centros'      => financeiro_nomes($api->listarCentrosCusto()),
        'formas'       => financeiro_nomes($api->listarFormasPagamento()),
        'fornecedores' => financeiro_nomes($api->listarFornecedores()),
    ];
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

/** Busca o nome de um fornecedor pelo CNPJ na BrasilAPI (grátis, sem captcha). */
function financeiro_consultar_cnpj(string $cnpj): ?array
{
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) !== 14) {
        return null;
    }
    $ch = curl_init('https://brasilapi.com.br/api/cnpj/v1/' . $cnpj);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8]);
    $r = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($r === false || $st !== 200) {
        return null;
    }
    $j = json_decode((string) $r, true);
    if (!is_array($j) || empty($j['razao_social'])) {
        return null;
    }
    return ['razao' => $j['razao_social'], 'fantasia' => $j['nome_fantasia'] ?? ''];
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

/** Formata um número para exibição em reais, sem sinal (ex.: "3135.58" -> "3.135,58"). */
function financeiro_valor_br($valor): string
{
    $n = abs((float) str_replace(',', '.', (string) $valor));
    return number_format($n, 2, ',', '.');
}

/**
 * Converte o valor digitado pelo usuário (formato BR) para o formato do envio:
 * despesa => sempre negativo, ponto como decimal (ex.: "3.135,58" -> "-3135.58").
 * Retorna null se inválido/zero.
 */
function financeiro_valor_para_envio(string $entrada): ?string
{
    $s = preg_replace('/[^\d,.\-]/', '', trim($entrada));
    if (strpos($s, ',') !== false) {          // tem vírgula: ela é o decimal
        $s = str_replace('.', '', $s);        // remove separador de milhar
        $s = str_replace(',', '.', $s);       // vírgula -> ponto
    }
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    $n = abs((float) $s);
    if ($n == 0.0) {
        return null;
    }
    return '-' . number_format($n, 2, '.', ''); // contas a pagar: sempre negativo
}

/** Normaliza um nome de empresa para comparação (maiúsculas, sem acento/pontuação/termos societários). */
function financeiro_normalizar_nome(string $s): string
{
    $s = mb_strtoupper($s, 'UTF-8');
    $s = strtr($s, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C']);
    $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
    $stop = ['LTDA','ME','EPP','EIRELI','SA','S','A','COM','COMERCIO','COMERCIAL','DE','DA','DO','DOS','DAS','E','P','PROD','PRODUTOS','PARA','CIA','IND','INDUSTRIA','DISTRIBUIDORA','DIST'];
    $tokens = array_values(array_filter(explode(' ', $s), fn($t) => $t !== '' && !in_array($t, $stop, true)));
    return implode(' ', $tokens);
}

/**
 * Encontra o fornecedor JÁ cadastrado que corresponde à nota.
 * 1º por CNPJ (campo document) — exato; 2º por semelhança de nome.
 * @param array $fornecedores lista completa de fornecedores (objetos da API).
 * @return array{name:string, match:string} match: 'cnpj' | 'nome' | 'nenhum'
 */
function financeiro_casar_fornecedor(string $cnpj, string $razao, array $fornecedores): array
{
    $cnpjN = preg_replace('/\D/', '', $cnpj);
    if ($cnpjN !== '') {
        foreach ($fornecedores as $f) {
            $doc = preg_replace('/\D/', '', (string) ($f['document'] ?? ''));
            if ($doc !== '' && $doc === $cnpjN) {
                return ['name' => (string) ($f['name'] ?? ''), 'match' => 'cnpj'];
            }
        }
    }

    $alvo = financeiro_normalizar_nome($razao);
    $melhorNome = '';
    $melhorScore = 0.0;
    if ($alvo !== '') {
        foreach ($fornecedores as $f) {
            $nome = (string) ($f['name'] ?? '');
            if ($nome === '') { continue; }
            $pct = 0.0;
            similar_text($alvo, financeiro_normalizar_nome($nome), $pct);
            if ($pct > $melhorScore) { $melhorScore = $pct; $melhorNome = $nome; }
        }
    }
    if ($melhorScore >= 62.0) {
        return ['name' => $melhorNome, 'match' => 'nome'];
    }
    return ['name' => '', 'match' => 'nenhum'];
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
    // NÃO memoriza forma de pagamento: ela muda por nota e vem da própria NF-e.
    // Memoriza a classificação de gestão (constante por fornecedor) + o nome do
    // fornecedor escolhido (para reusar o cadastro certo, sem duplicar razão social).
    $regras = financeiro_regras_listar();
    $regras[$cnpj] = [
        'fornecedor'    => $nome,
        'supplier'      => $campos['supplier'] ?? '',
        'account'       => $campos['account'] ?? '',
        'category'      => $campos['category'] ?? '',
        'cost_center'   => $campos['cost_center'] ?? '',
        'atualizado_em' => date('c'),
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

// ------------------------- SEFAZ: puxador de NF-e (distribuição por NSU) ---

/** Config do certificado A1: prioriza env (útil p/ cron), senão a config. */
function financeiro_sefaz_config(): ?array
{
    $cfg = financeiro_config() ?: [];
    $path = getenv('SEFAZ_PFX_PATH') ?: ($cfg['cert_path'] ?? '');
    $pass = getenv('SEFAZ_PFX_PASS') ?: ($cfg['cert_password'] ?? '');
    if ($path === '' || !is_file($path)) {
        return null;
    }
    return ['path' => $path, 'pass' => $pass, 'cnpj' => $cfg['cert_cnpj'] ?? ''];
}

/** True quando o puxador do SEFAZ está configurado (certificado disponível). */
function financeiro_sefaz_configurado(): bool
{
    return financeiro_sefaz_config() !== null;
}

function financeiro_nsu_state_path(): string { return __DIR__ . '/data/financeiro_nsu.json'; }
function financeiro_pendentes_path(): string { return __DIR__ . '/data/financeiro_pendentes.json'; }

function financeiro_nsu_ultimo(): string
{
    $p = financeiro_nsu_state_path();
    if (is_file($p)) {
        $d = json_decode((string) file_get_contents($p), true);
        if (is_array($d) && isset($d['ultNSU'])) { return (string) $d['ultNSU']; }
    }
    return '0';
}

function financeiro_nsu_salvar(string $ultNSU): void
{
    $dir = dirname(financeiro_nsu_state_path());
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    file_put_contents(financeiro_nsu_state_path(), json_encode(['ultNSU' => $ultNSU, 'em' => date('c')]));
}

function financeiro_pendentes_listar(): array
{
    $p = financeiro_pendentes_path();
    if (!is_file($p)) { return []; }
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function financeiro_pendente_salvar(string $chave, array $nota): bool
{
    $reg = financeiro_pendentes_listar();
    $reg[$chave] = $nota + ['recebido_em' => date('c')];
    $dir = dirname(financeiro_pendentes_path());
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return file_put_contents(financeiro_pendentes_path(), json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function financeiro_pendente_remover(string $chave): void
{
    $reg = financeiro_pendentes_listar();
    if (isset($reg[$chave])) {
        unset($reg[$chave]);
        file_put_contents(financeiro_pendentes_path(), json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

/** Converte um documento da distribuição (resNFe ou procNFe) numa nota de revisão. */
function financeiro_sefaz_doc_para_nota(array $doc): ?array
{
    $xml = $doc['xml'];
    $schema = $doc['schema'];

    // XML completo → parser completo (valor, itens, forma...).
    if (strpos($schema, 'procNFe') === 0) {
        try { return NFeParser::parse($xml); } catch (\Throwable $e) { return null; }
    }

    // Resumo (resNFe) → chave dá número/série/CNPJ/data; resumo dá nome + valor total.
    if (strpos($schema, 'resNFe') === 0) {
        if (!preg_match('/<chNFe>(\d{44})<\/chNFe>/', $xml, $mc)) { return null; }
        try { $nota = NFeChave::parse($mc[1]); } catch (\Throwable $e) { return null; }
        if (preg_match('/<xNome>(.*?)<\/xNome>/', $xml, $mn)) {
            $nota['fornecedor']['nome'] = trim($mn[1]);
            $nota['lancamento']['supplier'] = trim($mn[1]);
        }
        if (preg_match('/<vNF>([\d.]+)<\/vNF>/', $xml, $mv)) {
            $nota['valor_total'] = $mv[1];
            $nota['lancamento']['value'] = '-' . $mv[1];
        }
        $nota['natureza_operacao'] = 'NF-e (recebida do SEFAZ)';
        $nota['avisos'] = ['Recebida automaticamente do SEFAZ (resumo). Confira o valor e a categoria.'];
        return $nota;
    }

    return null; // eventos e outros: ignora
}

/**
 * Roda a distribuição por NSU: baixa novas NF-e e enfileira como pendentes.
 * @return array{novas:int,cStat:int,xMotivo:string,ultNSU:string,maxNSU:string,paginas:int}
 */
function financeiro_sefaz_puxar(int $maxPaginas = 10): array
{
    $sc = financeiro_sefaz_config();
    if (!$sc) {
        throw new SefazException('Certificado do SEFAZ não configurado (cert_path).');
    }
    $sefaz = new SefazDistribuicao(file_get_contents($sc['path']), $sc['pass'], $sc['cnpj']);

    $ult = financeiro_nsu_ultimo();
    $novas = 0; $pag = 0; $cStat = 0; $xMotivo = ''; $maxNSU = $ult;

    do {
        $r = $sefaz->consultarPorNSU($ult);
        $cStat = $r['cStat']; $xMotivo = $r['xMotivo'];
        $maxNSU = $r['maxNSU'] !== '' ? $r['maxNSU'] : $maxNSU;
        $pag++;

        foreach ($r['docs'] as $doc) {
            $nota = financeiro_sefaz_doc_para_nota($doc);
            if (!$nota || empty($nota['chave'])) { continue; }
            $ch = $nota['chave'];
            if (financeiro_ja_processada($ch) || isset(financeiro_pendentes_listar()[$ch])) { continue; }
            if (financeiro_pendente_salvar($ch, $nota)) { $novas++; }
        }

        if ($r['ultNSU'] !== '') {
            $ult = $r['ultNSU'];
            financeiro_nsu_salvar($ult);
        }
        // Continua enquanto houver mais lotes (ultNSU < maxNSU) e cStat indicar documentos.
        $temMais = ($cStat === 138) && ($r['ultNSU'] !== '') && ($r['maxNSU'] !== '')
                   && ((int) $r['ultNSU'] < (int) $r['maxNSU']);
    } while ($temMais && $pag < $maxPaginas);

    return ['novas' => $novas, 'cStat' => $cStat, 'xMotivo' => $xMotivo, 'ultNSU' => $ult, 'maxNSU' => $maxNSU, 'paginas' => $pag];
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
