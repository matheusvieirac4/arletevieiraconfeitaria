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

/**
 * Garante a pasta de dados COM proteção contra acesso web (defesa em profundidade:
 * vale mesmo que o .htaccess de admin/ não seja aplicado pelo servidor).
 */
function financeiro_data_dir(): string
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
    return $dir;
}

/** Arquivo gravável com as credenciais editadas pelo painel (sobrescreve a config). */
function financeiro_config_override_path(): string
{
    return __DIR__ . '/data/financeiro_config.json';
}

/** Campos que podem ser editados pela interface. */
function financeiro_config_campos(): array
{
    return [
        'company_id'     => ['rotulo' => 'Company ID (Cardápio Web)', 'secreto' => false],
        'refresh_token'  => ['rotulo' => 'Refresh token do Cardápio Web', 'secreto' => true],
        'gemini_api_key' => ['rotulo' => 'Chave da IA (Gemini)', 'secreto' => true],
        'gemini_model'   => ['rotulo' => 'Modelo do Gemini', 'secreto' => false],
        'cert_path'      => ['rotulo' => 'Caminho do certificado A1 (.p12)', 'secreto' => false],
        'cert_password'  => ['rotulo' => 'Senha do certificado', 'secreto' => true],
        'cron_token'     => ['rotulo' => 'Token do cron', 'secreto' => true],
    ];
}

/** Lê só as sobrescritas gravadas pelo painel. */
function financeiro_config_override(): array
{
    $p = financeiro_config_override_path();
    if (!is_file($p)) {
        return [];
    }
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

/**
 * Config efetiva: o arquivo config_financeiro.php (base) + o que foi salvo pelo
 * painel (tem prioridade). Assim dá para trocar o token pela interface.
 */
function financeiro_config(): ?array
{
    $base = [];
    $path = financeiro_config_path();
    if (is_file($path)) {
        $c = require $path;
        if (is_array($c)) { $base = $c; }
    }
    $cfg = array_merge($base, financeiro_config_override());
    return $cfg ?: null;
}

/**
 * Salva credenciais vindas do painel. Só grava os campos permitidos e não
 * apaga o que veio em branco (branco = manter o valor atual).
 */
function financeiro_config_salvar(array $novos): bool
{
    $permitidos = array_keys(financeiro_config_campos());
    $atual = financeiro_config_override();

    foreach ($novos as $k => $v) {
        if (!in_array($k, $permitidos, true)) { continue; }
        $v = trim((string) $v);
        if ($v === '') { continue; }          // em branco = não mexe
        $atual[$k] = $v;
    }

    if (!is_dir(financeiro_data_dir())) {
        return false;
    }
    return file_put_contents(
        financeiro_config_override_path(),
        json_encode($atual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
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
        // Para a IA, só as subcategorias escolhíveis (sem os rótulos numerados).
        'categorias'   => financeiro_categorias_selecionaveis(financeiro_categorias_agrupadas($api->listarCategorias())),
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

/** Formata uma data ISO (YYYY-MM-DD) para exibição em BR. Vazio vira "—". */
function financeiro_data_br(string $iso): string
{
    $iso = trim($iso);
    if ($iso === '') { return '—'; }
    $d = \DateTime::createFromFormat('Y-m-d', substr($iso, 0, 10));
    return $d ? $d->format('d/m/Y') : $iso;
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

    // Nome curto/parcial ("matheus" para "Matheus Vieira Cardoso"): a similaridade
    // por caractere não pega isso (dá ~48%, abaixo do corte). Antes dela, tenta
    // casar por PALAVRAS: se todas as palavras do que foi digitado estiverem no
    // nome de um cadastro, e de UM SÓ, é ele. Havendo empate (dois "Casa ..."),
    // não adivinha — deixa o usuário escolher.
    $palavras = array_values(array_filter(
        preg_split('/\s+/', $alvo) ?: [],
        fn($p) => mb_strlen($p, 'UTF-8') >= 3      // ignora "de", "da", "do"
    ));
    if ($palavras) {
        $candidatos = [];
        foreach ($fornecedores as $f) {
            $nome = (string) ($f['name'] ?? '');
            if ($nome === '') { continue; }
            $tokens = preg_split('/\s+/', financeiro_normalizar_nome($nome)) ?: [];
            $contemTodas = true;
            foreach ($palavras as $p) {
                if (!in_array($p, $tokens, true)) { $contemTodas = false; break; }
            }
            if ($contemTodas) { $candidatos[] = $nome; }
        }
        $candidatos = array_values(array_unique($candidatos));
        if (count($candidatos) === 1) {
            return ['name' => $candidatos[0], 'match' => 'nome'];
        }
    }

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

/**
 * Garante que o fornecedor exista no CW com o documento informado.
 *  - Ninguém com esse nome/doc  -> cria (POST)            => 'criado'
 *  - Existe pelo nome, SEM doc   -> completa o doc (PUT)   => 'atualizado'
 *  - Já existe com doc igual/qualquer -> não mexe          => 'existe'
 * Nunca lança — falha vira 'ignorado' (não deve travar o lançamento).
 */
function financeiro_fornecedor_garantir(CardapioWebApi $api, string $nome, string $documento): string
{
    $doc = preg_replace('/\D/', '', $documento);
    $nome = trim($nome);
    if ($doc === '' || $nome === '' || (strlen($doc) !== 14 && strlen($doc) !== 11)) {
        return 'ignorado';
    }
    try {
        $lista = financeiro_extrair_lista($api->listarFornecedores());
        $nomeNorm = financeiro_normalizar_nome($nome);

        // 1) Já existe alguém com esse mesmo documento? Nada a fazer.
        foreach ($lista as $f) {
            $fdoc = preg_replace('/\D/', '', (string) ($f['document'] ?? ''));
            if ($fdoc !== '' && $fdoc === $doc) { return 'existe'; }
        }
        // 2) Existe pelo nome: se estiver SEM documento, completa; senão respeita.
        foreach ($lista as $f) {
            if (financeiro_normalizar_nome((string) ($f['name'] ?? '')) !== $nomeNorm) { continue; }
            $fdoc = preg_replace('/\D/', '', (string) ($f['document'] ?? ''));
            if ($fdoc === '' && !empty($f['id'])) {
                $api->atualizarFornecedor((int) $f['id'], $f, $doc);
                return 'atualizado';
            }
            return 'existe';   // já tem documento próprio — não sobrescreve
        }
        // 3) Ninguém: cria novo.
        $api->criarFornecedor($nome, $doc);
        return 'criado';
    } catch (\Throwable $e) {
        return 'ignorado';
    }
}

/** Acha o id de um item de lookup pelo nome (case-insensitive). Null se não achar. */
function financeiro_id_por_nome($resp, string $nome): ?int
{
    $alvo = mb_strtolower(trim($nome), 'UTF-8');
    if ($alvo === '') { return null; }
    foreach (financeiro_extrair_lista($resp) as $it) {
        if (is_array($it) && mb_strtolower(trim((string) ($it['name'] ?? '')), 'UTF-8') === $alvo) {
            return isset($it['id']) ? (int) $it['id'] : null;
        }
    }
    return null;
}

/**
 * Procura contas a pagar JÁ EXISTENTES no Cardápio Web (ex.: criadas por
 * recorrência) que casem com o comprovante em revisão, para evitar duplicar.
 *
 * Casamento por FORNECEDOR + VENCIMENTO no mês (a marca da recorrência). O VALOR
 * NÃO entra no filtro de propósito: conta fixa varia bastante (ex.: Celesc
 * cadastrada em 600 mas vem 400 ou 800), então descartar por valor perderia a
 * duplicata. O valor só é comparado para SINALIZAR diferença na tela.
 *
 * Exige identificar o fornecedor (id, senão nome); sem isso devolve vazio, para
 * não sugerir contas de outros fornecedores. NUNCA lança e NUNCA dá baixa —
 * só devolve candidatos para o usuário confirmar.
 *
 * @return array<int,array{id:int,descricao:string,valor:string,vencimento:string,fornecedor:string,valor_bate:bool}>
 */
function financeiro_contas_abertas_semelhantes(CardapioWebApi $api, string $supplierNome, ?int $supplierId, string $valor, string $venc): array
{
    $alvoForn = financeiro_normalizar_nome($supplierNome);
    if ($supplierId === null && $alvoForn === '') {
        return [];   // sem como confirmar o fornecedor -> não arrisca
    }
    $alvoVal = abs((float) str_replace(',', '.', (string) $valor));

    // Janela centrada no vencimento (±18 dias). Conta fixa é mensal, então o mês
    // vizinho (~30 dias) fica de fora e sobra a do próprio mês.
    $ts   = strtotime($venc) ?: time();
    $iniQ = date('Y-m-d', $ts - 18 * 86400);
    $fimQ = date('Y-m-d', $ts + 18 * 86400);

    try {
        $resp = $api->listarTransacoes([
            'q' => [
                'activity_type_eq' => 'out',
                'due_date_gteq'    => $iniQ,
                'due_date_lteq'    => $fimQ,
            ],
            'page'     => 1,
            'per_page' => 100,
        ]);
    } catch (\Throwable $e) {
        return [];   // falha na consulta nunca trava o fluxo normal
    }

    $out = [];
    foreach (financeiro_extrair_lista($resp) as $t) {
        if (!is_array($t)) { continue; }

        // 1) Só em aberto: sem data de pagamento (settlement).
        $sett = $t['settlement_date'] ?? ($t['settled_at'] ?? null);
        if (!empty($sett)) { continue; }

        // 2) Fornecedor: por id quando ambos existem; senão por nome. Sem
        //    confirmação de fornecedor, descarta (evita sugerir conta alheia).
        $tid   = isset($t['supplier_id']) ? (int) $t['supplier_id'] : (int) ($t['supplier']['id'] ?? 0);
        $tnome = (string) ($t['supplier']['name'] ?? ($t['supplier_name'] ?? ''));
        $bate  = false;
        if ($supplierId !== null && $tid > 0) {
            $bate = ($tid === $supplierId);
        } elseif ($alvoForn !== '' && $tnome !== '') {
            $bate = (financeiro_normalizar_nome($tnome) === $alvoForn);
        }
        if (!$bate) { continue; }

        // 3) Valor NÃO filtra — só sinaliza se bate (tolerância de centavos).
        $v = $t['value'] ?? ($t['amount'] ?? null);
        $valorBate = $v !== null && $alvoVal > 0
            && abs(abs((float) str_replace(',', '.', (string) $v)) - $alvoVal) <= 0.05;

        $out[] = [
            'id'         => (int) ($t['id'] ?? 0),
            'descricao'  => (string) ($t['description'] ?? ''),
            'valor'      => (string) ($v ?? ''),
            'vencimento' => (string) ($t['due_date'] ?? ''),
            'fornecedor' => $tnome,
            'valor_bate' => $valorBate,
        ];
    }
    return $out;
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

/**
 * Agrupa as categorias por subcategoria/grupo para montar <optgroup> no select.
 *
 * O Cardápio Web devolve uma lista PLANA, na ordem certa, em que a numeração do
 * nome indica o nível ("4.02 Custos com produtos e serviços" = categoria; os
 * itens seguintes sem número = subcategorias dela). Também tratamos os formatos
 * genéricos (children, parent_id, rótulo de grupo) caso a API mude.
 *
 * @return array<string, string[]> ['Grupo' => ['Categoria', ...]]
 */
function financeiro_categorias_agrupadas($resp): array
{
    $itens = financeiro_extrair_lista($resp);

    // --- Caso do Cardápio Web: lista PLANA, hierarquia pela numeração ---------
    // Vem na ordem certa e o nome diz o nível: "4.02 Custos com produtos e
    // serviços" é categoria (rótulo, não selecionável) e tudo que vem depois
    // sem numeração ("Bebidas", "Embalagens"...) é subcategoria dela. É assim
    // que o próprio CW monta o select. Só usamos este caminho se realmente
    // houver itens numerados, senão caímos na detecção genérica abaixo.
    $temNumerado = false;
    foreach ($itens as $it) {
        if (is_array($it) && !empty($it['name']) && preg_match('/^\d+(\.\d+)+\s/u', (string) $it['name'])) {
            $temNumerado = true;
            break;
        }
    }
    if ($temNumerado) {
        $grupos  = [];
        $atual   = 'Outras';
        $ordem   = [];   // preserva a ordem de chegada dos grupos
        foreach ($itens as $it) {
            if (!is_array($it) || empty($it['name'])) { continue; }
            $nome = trim((string) $it['name']);
            if (preg_match('/^\d+(\.\d+)+\s/u', $nome)) {
                $atual = $nome;                       // abre uma nova categoria
                if (!isset($grupos[$atual])) { $grupos[$atual] = []; $ordem[] = $atual; }
                continue;
            }
            if (!isset($grupos[$atual])) { $grupos[$atual] = []; $ordem[] = $atual; }
            $grupos[$atual][] = $nome;                // subcategoria da atual
        }
        // Categoria numerada sem nenhuma subcategoria continua selecionável
        // (senão ela sumiria do select).
        foreach ($grupos as $g => $subs) {
            if (!$subs) { $grupos[$g] = [$g]; }
        }
        $ordenado = [];
        foreach ($ordem as $g) {
            $ordenado[$g] = array_values(array_unique($grupos[$g]));
        }
        return $ordenado;
    }

    // Mapa id => nome, para resolver parent_id.
    $porId = [];
    foreach ($itens as $it) {
        if (is_array($it) && isset($it['id'], $it['name'])) {
            $porId[(string) $it['id']] = (string) $it['name'];
        }
    }

    // Quem é pai de alguém só serve de rótulo de grupo — não vira opção sozinho.
    $ehPai = [];
    foreach ($itens as $it) {
        if (!is_array($it)) { continue; }
        foreach (['parent_id', 'category_id', 'group_id', 'father_id'] as $k) {
            if (!empty($it[$k]) && isset($porId[(string) $it[$k]])) { $ehPai[$porId[(string) $it[$k]]] = true; }
        }
    }

    $grupos = [];
    $add = function (string $grupo, string $nome) use (&$grupos): void {
        if ($nome === '') { return; }
        if (!isset($grupos[$grupo])) { $grupos[$grupo] = []; }
        $grupos[$grupo][] = $nome;
    };

    foreach ($itens as $it) {
        if (!is_array($it) || empty($it['name'])) { continue; }
        $nome = (string) $it['name'];
        if (isset($ehPai[$nome])) { continue; }

        // Caso A: o item traz filhos — ele é o grupo.
        if (!empty($it['children']) && is_array($it['children'])) {
            foreach ($it['children'] as $f) {
                if (is_array($f) && !empty($f['name'])) { $add($nome, (string) $f['name']); }
            }
            continue;
        }

        // Caso B: aponta para um pai.
        $pai = '';
        foreach (['parent', 'category', 'group', 'category_group', 'father'] as $k) {
            if (!isset($it[$k])) { continue; }
            if (is_array($it[$k]) && !empty($it[$k]['name'])) { $pai = (string) $it[$k]['name']; break; }
            if (is_string($it[$k]) && $it[$k] !== '') { $pai = $it[$k]; break; }
        }
        if ($pai === '') {
            foreach (['parent_id', 'category_id', 'group_id', 'father_id'] as $k) {
                if (!empty($it[$k]) && isset($porId[(string) $it[$k]])) { $pai = $porId[(string) $it[$k]]; break; }
            }
        }

        // Caso C: hierarquia embutida no próprio nome ("Insumos > Farinha").
        if ($pai === '') {
            foreach ([' > ', ' / ', ' - '] as $sep) {
                if (strpos($nome, $sep) !== false) {
                    [$pai, $nome] = array_map('trim', explode($sep, $nome, 2));
                    break;
                }
            }
        }

        $add($pai !== '' ? $pai : 'Outras', $nome);
    }

    foreach ($grupos as $g => $lista) {
        $lista = array_values(array_unique($lista));
        sort($lista, SORT_NATURAL | SORT_FLAG_CASE);
        $grupos[$g] = $lista;
    }
    uksort($grupos, function ($a, $b) {
        if ($a === 'Outras') { return 1; }
        if ($b === 'Outras') { return -1; }
        return strnatcasecmp($a, $b);
    });

    return $grupos;
}

/**
 * Nomes de categoria que podem de fato ser escolhidos (as folhas do agrupamento).
 * Rótulos de categoria numerada não entram — no CW eles são só cabeçalho.
 */
function financeiro_categorias_selecionaveis(array $grupos): array
{
    $nomes = [];
    foreach ($grupos as $subs) {
        foreach ($subs as $s) { $nomes[] = $s; }
    }
    return array_values(array_unique($nomes));
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

/** Lê o estado do NSU preservando as demais chaves do arquivo. */
function financeiro_nsu_state(): array
{
    $p = financeiro_nsu_state_path();
    if (!is_file($p)) { return []; }
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

function financeiro_nsu_state_gravar(array $state): void
{
    $dir = dirname(financeiro_nsu_state_path());
    financeiro_data_dir();
    file_put_contents(financeiro_nsu_state_path(), json_encode($state, JSON_PRETTY_PRINT));
}

function financeiro_nsu_salvar(string $ultNSU): void
{
    $s = financeiro_nsu_state();
    $s['ultNSU'] = $ultNSU;
    $s['em'] = date('c');
    financeiro_nsu_state_gravar($s);
}

/** Intervalo mínimo entre consultas ao SEFAZ (anti "consumo indevido"). */
function financeiro_sefaz_intervalo(): int { return 3600; } // 1 hora

/** Timestamp (unix) da última consulta feita ao SEFAZ. 0 = nunca. */
function financeiro_sefaz_ultima_execucao(): int
{
    $s = financeiro_nsu_state();
    return isset($s['ultima_execucao']) ? (int) $s['ultima_execucao'] : 0;
}

/** Registra que acabamos de consultar o SEFAZ (usado para a janela de espera). */
function financeiro_sefaz_marcar_execucao(): void
{
    $s = financeiro_nsu_state();
    $s['ultima_execucao'] = time();
    $s['ultima_execucao_em'] = date('c');
    financeiro_nsu_state_gravar($s);
}

/** Segundos que faltam para poder consultar de novo (0 = liberado). */
function financeiro_sefaz_espera_restante(): int
{
    $u = financeiro_sefaz_ultima_execucao();
    if ($u <= 0) { return 0; }
    return max(0, financeiro_sefaz_intervalo() - (time() - $u));
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
    financeiro_data_dir();
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
        // Data exata do resumo (a chave só traz ano/mês).
        if (preg_match('/<dhEmi>(\d{4}-\d{2}-\d{2})/', $xml, $md)) {
            $nota['emissao'] = $md[1];
            $nota['lancamento']['due_date'] = $md[1];
            $nota['lancamento']['competence_date'] = $md[1];
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
        financeiro_sefaz_marcar_execucao(); // conta para a janela de 1h
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

// ------------------- Log leve de enviados (dedup por valor/fornecedor/data) ---

function financeiro_enviados_path(): string { return __DIR__ . '/data/financeiro_enviados.json'; }

function financeiro_enviados_listar(): array
{
    $p = financeiro_enviados_path();
    if (!is_file($p)) { return []; }
    $d = json_decode((string) file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

/** Chave de comparação: fornecedor normalizado + valor + data. */
function financeiro_enviado_assinatura(string $supplier, string $value, string $data): string
{
    $forn = mb_strtolower(trim($supplier), 'UTF-8');
    $val  = str_replace(',', '.', trim($value));
    return $forn . '|' . $val . '|' . trim($data);
}

/**
 * Verifica se já foi enviado um lançamento igual (mesmo fornecedor, valor e data)
 * nos últimos $dias dias. Devolve a data/hora do envio anterior, ou null.
 */
function financeiro_enviado_duplicado(string $supplier, string $value, string $data, int $dias = 7): ?string
{
    $limite = time() - $dias * 86400;
    $assin = financeiro_enviado_assinatura($supplier, $value, $data);
    foreach (financeiro_enviados_listar() as $e) {
        if (($e['ts'] ?? 0) >= $limite && ($e['assinatura'] ?? '') === $assin) {
            return isset($e['em']) ? substr($e['em'], 0, 16) : date('d/m/Y', $e['ts']);
        }
    }
    return null;
}

/** Quantos envios o log guarda (também é o tamanho do histórico na tela). */
const FINANCEIRO_ENVIADOS_MAX = 100;

/**
 * Registra um envio bem-sucedido, mantendo os últimos FINANCEIRO_ENVIADOS_MAX.
 * A dedup só olha os últimos 7 dias (ver financeiro_enviado_duplicado); o resto
 * fica para o histórico na tela.
 *
 * @param array $extra Campos opcionais do lançamento (descrição, categoria...).
 */
function financeiro_enviado_registrar(string $supplier, string $value, string $data, array $extra = []): void
{
    $lista = financeiro_enviados_listar();
    $lista[] = $extra + [
        'assinatura' => financeiro_enviado_assinatura($supplier, $value, $data),
        'fornecedor' => $supplier,
        'valor'      => $value,
        'data'       => $data,
        'ts'         => time(),
        'em'         => date('c'),
    ];
    if (count($lista) > FINANCEIRO_ENVIADOS_MAX) {
        $lista = array_slice($lista, -FINANCEIRO_ENVIADOS_MAX);
    }
    $dir = dirname(financeiro_enviados_path());
    financeiro_data_dir();
    file_put_contents(financeiro_enviados_path(), json_encode($lista, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
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
