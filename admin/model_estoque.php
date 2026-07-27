<?php
require_once __DIR__ . '/../includes/banco.php';

// ============================================================================
// Controle de estoque: itens (catálogo + saldo) e movimentações (log).
// O saldo (estoque_atual) é mantido na tabela de itens E registrado em
// estoque_movimentacoes, para rastreabilidade e reconstrução.
// ============================================================================

/** As tabelas do estoque já existem? (o setup precisa ter rodado uma vez). */
function estoque_pronto(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM estoque_itens LIMIT 1");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Mostra um aviso pedindo para rodar o setup e encerra a página. */
function estoque_exigir_setup(): void
{
    echo '<div class="card" style="max-width:640px;"><div class="card-body">'
       . '<h5 class="mb-2">Módulo de estoque ainda não inicializado</h5>'
       . '<p class="text-muted">As tabelas do estoque precisam ser criadas uma vez. '
       . 'Clique abaixo para criar as tabelas e importar o catálogo da planilha.</p>'
       . '<a href="estoque_setup.php" class="btn btn-primary">Inicializar estoque agora</a>'
       . '</div></div>';
}

/** Fornecedores distintos cadastrados (para o filtro). */
function estoque_fornecedores(PDO $pdo): array
{
    $rows = $pdo->query("SELECT DISTINCT fornecedor FROM estoque_itens
                         WHERE ativo = 1 AND fornecedor IS NOT NULL AND fornecedor <> ''
                         ORDER BY fornecedor")->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

function estoque_listar(PDO $pdo, string $busca = '', bool $soAbaixoMinimo = false, string $ordem = 'nome', string $dir = 'asc', string $fornecedor = ''): array
{
    $sql = "SELECT * FROM estoque_itens WHERE ativo = 1";
    $params = [];
    if ($busca !== '') {
        $sql .= " AND (nome LIKE :b OR fornecedor LIKE :b OR codigo_barras LIKE :b)";
        $params[':b'] = '%' . $busca . '%';
    }
    if ($fornecedor !== '') {
        $sql .= " AND fornecedor = :f";
        $params[':f'] = $fornecedor;
    }
    if ($soAbaixoMinimo) {
        $sql .= " AND estoque_minimo IS NOT NULL AND estoque_atual < estoque_minimo";
    }
    // ORDER BY não aceita bind — whitelist rígida de coluna e direção.
    $col = in_array($ordem, ['nome', 'fornecedor'], true) ? $ordem : 'nome';
    $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
    // Fornecedor pode ser NULL; desempata pelo nome.
    $sql .= $col === 'fornecedor' ? " ORDER BY fornecedor $dir, nome ASC" : " ORDER BY nome $dir";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** A coluna 'responsavel' já existe em estoque_movimentacoes? (setup migra). */
function estoque_mov_tem_responsavel(PDO $pdo): bool
{
    static $tem = null;
    if ($tem === null) {
        try { $pdo->query("SELECT responsavel FROM estoque_movimentacoes LIMIT 1"); $tem = true; }
        catch (\Throwable $e) { $tem = false; }
    }
    return $tem;
}

/** A coluna 'codigo_compra' (código da nota/fardo) já existe? (setup migra). */
function estoque_tem_codigo_compra(PDO $pdo): bool
{
    static $tem = null;
    if ($tem === null) {
        try { $pdo->query("SELECT codigo_compra FROM estoque_itens LIMIT 1"); $tem = true; }
        catch (\Throwable $e) { $tem = false; }
    }
    return $tem;
}

/** As colunas 'unidade_medida'/'conteudo' já existem? (setup migra). */
function estoque_tem_unidade(PDO $pdo): bool
{
    static $tem = null;
    if ($tem === null) {
        try { $pdo->query("SELECT conteudo FROM estoque_itens LIMIT 1"); $tem = true; }
        catch (\Throwable $e) { $tem = false; }
    }
    return $tem;
}

/** A coluna 'controlar_estoque' já existe? (setup migra). */
function estoque_tem_controlar(PDO $pdo): bool
{
    static $tem = null;
    if ($tem === null) {
        try { $pdo->query("SELECT controlar_estoque FROM estoque_itens LIMIT 1"); $tem = true; }
        catch (\Throwable $e) { $tem = false; }
    }
    return $tem;
}

/** Unidades de medida aceitas (grandeza do conteúdo do item). */
function estoque_unidades_medida(): array
{
    return ['UN' => 'Unidade', 'KG' => 'Quilo (kg)', 'G' => 'Grama (g)', 'L' => 'Litro (L)', 'ML' => 'Mililitro (ml)'];
}

/**
 * Extrai unidade de medida + conteúdo da descrição ("5 KG", "1 LT", "200ML",
 * "30g", "2,05KG"). Devolve [unidade, conteudo] ou null. Usado no item novo.
 */
function estoque_medida_da_descricao(string $desc): ?array
{
    $d = mb_strtoupper($desc, 'UTF-8');
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*ML\b/u', $d, $m))        { return ['ML', (float) str_replace(',', '.', $m[1])]; }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*L(?:T|TS)?\b/u', $d, $m)) { return ['L',  (float) str_replace(',', '.', $m[1])]; }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*KG\b/u', $d, $m))        { return ['KG', (float) str_replace(',', '.', $m[1])]; }
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*G\b/u', $d, $m))         { return ['G',  (float) str_replace(',', '.', $m[1])]; }
    return null;
}

/**
 * Varre os itens e preenche unidade_medida + conteúdo lendo a descrição
 * ("5 KG", "1 LT", "200ML"...). Só mexe onde detecta e onde mudou. Devolve
 * quantos foram atualizados.
 */
function estoque_detectar_medida_todos(PDO $pdo): int
{
    if (!estoque_tem_unidade($pdo)) { return 0; }
    $itens = estoque_listar($pdo);
    $up = $pdo->prepare("UPDATE estoque_itens SET unidade_medida = :u, conteudo = :c WHERE id = :id");
    $n = 0;
    foreach ($itens as $it) {
        $m = estoque_medida_da_descricao((string) $it['nome']);
        if ($m === null) { continue; }
        $mesmaUn = strtoupper((string) ($it['unidade_medida'] ?? 'UN')) === $m[0];
        $mesmoCont = abs((float) ($it['conteudo'] ?? 0) - (float) $m[1]) < 0.0005;
        if ($mesmaUn && $mesmoCont) { continue; }
        $up->execute([':u' => $m[0], ':c' => $m[1], ':id' => (int) $it['id']]);
        $n++;
    }
    return $n;
}

/**
 * Preço por unidade base (grama/ml/unidade interna), derivado de preço + conteúdo.
 * @return array{valor:float, rotulo:string}|null  ex.: ['valor'=>0.004,'rotulo'=>'g']
 */
function estoque_preco_por_base(array $item): ?array
{
    $preco = $item['preco'] ?? null;
    $cont  = (float) ($item['conteudo'] ?? 0);
    $un    = strtoupper((string) ($item['unidade_medida'] ?? 'UN'));
    if ($preco === null || $cont <= 0) { return null; }
    $preco = (float) $preco;
    switch ($un) {
        case 'KG': return ['valor' => $preco / ($cont * 1000), 'rotulo' => 'g'];
        case 'G':  return ['valor' => $preco / $cont,          'rotulo' => 'g'];
        case 'L':  return ['valor' => $preco / ($cont * 1000), 'rotulo' => 'ml'];
        case 'ML': return ['valor' => $preco / $cont,          'rotulo' => 'ml'];
        case 'UN': return $cont > 1 ? ['valor' => $preco / $cont, 'rotulo' => 'un'] : null;
    }
    return null;
}

function estoque_buscar(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM estoque_itens WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Busca item pelo código de barras (para o quiosque). Null se não achar. */
function estoque_buscar_por_barcode(PDO $pdo, string $codigo): ?array
{
    $codigo = trim($codigo);
    if ($codigo === '') { return null; }
    $stmt = $pdo->prepare("SELECT * FROM estoque_itens WHERE codigo_barras = :c AND ativo = 1 LIMIT 1");
    $stmt->execute([':c' => $codigo]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function estoque_cols_editaveis(PDO $pdo, array &$p): array
{
    $cols = ['nome=:nome','fornecedor=:forn','preco=:preco','estoque_atual=:atual',
             'estoque_minimo=:minimo','estoque_ideal=:ideal','codigo_barras=:barras','imagem=:imagem'];
    if (estoque_tem_codigo_compra($pdo)) { $cols[] = 'codigo_compra=:compra'; } else { unset($p[':compra']); }
    if (estoque_tem_unidade($pdo)) {
        $cols[] = 'unidade_medida=:unidade';
        $cols[] = 'conteudo=:conteudo';
    } else { unset($p[':unidade'], $p[':conteudo']); }
    if (estoque_tem_controlar($pdo)) { $cols[] = 'controlar_estoque=:controlar'; } else { unset($p[':controlar']); }
    return $cols;
}

function estoque_criar(PDO $pdo, array $d): int
{
    $p = estoque_params($d);
    $cols = estoque_cols_editaveis($pdo, $p);
    $pdo->prepare("INSERT INTO estoque_itens SET " . implode(', ', $cols))->execute($p);
    return (int) $pdo->lastInsertId();
}

function estoque_atualizar(PDO $pdo, int $id, array $d): bool
{
    $p = estoque_params($d);
    $p[':id'] = $id;
    $cols = estoque_cols_editaveis($pdo, $p);
    return $pdo->prepare("UPDATE estoque_itens SET " . implode(', ', $cols) . " WHERE id=:id")->execute($p);
}

/** Normaliza campos do formulário para os binds. */
function estoque_params(array $d): array
{
    $num = function ($v) {
        $v = trim((string) ($v ?? ''));
        if ($v === '') { return null; }
        $v = str_replace('.', '', $v);           // milhar BR
        $v = str_replace(',', '.', $v);          // decimal BR
        return is_numeric($v) ? (float) $v : null;
    };
    $barras = preg_replace('/\D/', '', (string) ($d['codigo_barras'] ?? ''));
    $compra = preg_replace('/\D/', '', (string) ($d['codigo_compra'] ?? ''));
    $un     = strtoupper(trim((string) ($d['unidade_medida'] ?? 'UN')));
    if (!isset(estoque_unidades_medida()[$un])) { $un = 'UN'; }
    return [
        ':nome'     => trim((string) ($d['nome'] ?? '')),
        ':forn'     => ($f = trim((string) ($d['fornecedor'] ?? ''))) !== '' ? $f : null,
        ':preco'    => $num($d['preco'] ?? ''),
        ':atual'    => $num($d['estoque_atual'] ?? '') ?? 0,
        ':minimo'   => $num($d['estoque_minimo'] ?? ''),
        ':ideal'    => $num($d['estoque_ideal'] ?? ''),
        ':barras'   => $barras !== '' ? $barras : null,
        ':compra'   => $compra !== '' ? $compra : null,
        ':unidade'  => $un,
        ':conteudo' => $num($d['conteudo'] ?? ''),
        ':imagem'   => ($im = trim((string) ($d['imagem'] ?? ''))) !== '' ? $im : null,
        // Checkbox só vem no POST quando marcado; o form manda um marcador oculto
        // para distinguir "desmarcado" de "chamada sem o campo" (esta assume controlado).
        ':controlar' => isset($d['controlar_estoque_set']) ? (!empty($d['controlar_estoque']) ? 1 : 0) : 1,
    ];
}

function estoque_deletar(PDO $pdo, int $id): bool
{
    // Soft delete: mantém histórico de movimentações.
    return $pdo->prepare("UPDATE estoque_itens SET ativo = 0 WHERE id = :id")->execute([':id' => $id]);
}

/**
 * Aplica uma movimentação e atualiza o saldo, tudo numa transação.
 * $tipo: 'entrada' soma, 'saida' subtrai, 'ajuste' define o saldo absoluto.
 * Devolve o novo saldo.
 */
function estoque_movimentar(PDO $pdo, int $itemId, string $tipo, float $qtd, string $origem = 'manual', string $obs = '', string $responsavel = ''): float
{
    if (!in_array($tipo, ['entrada', 'saida', 'ajuste'], true)) {
        throw new InvalidArgumentException('Tipo de movimentação inválido.');
    }
    $temResp = estoque_mov_tem_responsavel($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT estoque_atual FROM estoque_itens WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $itemId]);
        $atual = $stmt->fetchColumn();
        if ($atual === false) { throw new RuntimeException('Item não encontrado.'); }
        $atual = (float) $atual;

        if ($tipo === 'entrada')      { $novo = $atual + abs($qtd); }
        elseif ($tipo === 'saida')    { $novo = $atual - abs($qtd); }
        else                          { $novo = $qtd; }   // ajuste: saldo absoluto

        $pdo->prepare("UPDATE estoque_itens SET estoque_atual = :s WHERE id = :id")
            ->execute([':s' => $novo, ':id' => $itemId]);

        $cols = ['item_id', 'tipo', 'quantidade', 'saldo_apos', 'origem', 'observacao'];
        $vals = [':item' => $itemId, ':tipo' => $tipo, ':qtd' => abs($qtd),
                 ':saldo' => $novo, ':origem' => $origem, ':obs' => $obs !== '' ? $obs : null];
        $ph   = [':item', ':tipo', ':qtd', ':saldo', ':origem', ':obs'];
        if ($temResp) { $cols[] = 'responsavel'; $ph[] = ':resp'; $vals[':resp'] = $responsavel !== '' ? $responsavel : null; }
        $pdo->prepare("INSERT INTO estoque_movimentacoes (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")")
            ->execute($vals);

        $pdo->commit();
        return (float) $novo;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Nome do admin logado, para registrar como responsável pela movimentação. */
function estoque_responsavel_atual(): string
{
    return trim((string) ($_SESSION['admin_nome'] ?? ''));
}

// ------------------- Token do quiosque (acesso sem login admin) ----------
// Guardado num arquivo em admin/data/ (fora do git). Gera um link que abre o
// quiosque sem a sessão do admin; o aparelho fica autorizado por cookie longo.

function estoque_kiosk_token_path(): string { return __DIR__ . '/data/kiosk_token.txt'; }

function estoque_kiosk_token(): string
{
    $p = estoque_kiosk_token_path();
    return is_file($p) ? trim((string) @file_get_contents($p)) : '';
}

/** Gera (ou regenera, revogando o anterior) o token do quiosque. */
function estoque_kiosk_token_gerar(): string
{
    $t = bin2hex(random_bytes(20));
    $dir = dirname(estoque_kiosk_token_path());
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    @file_put_contents(estoque_kiosk_token_path(), $t);
    return $t;
}

function estoque_kiosk_token_valido(string $t): bool
{
    $a = estoque_kiosk_token();
    return $a !== '' && $t !== '' && hash_equals($a, $t);
}

/**
 * Autoriza o acesso ao quiosque: admin logado OU token válido (link/cookie).
 * Ao chegar com ?token válido, grava um cookie longo (autoriza o aparelho).
 */
function estoque_kiosk_autorizado(): bool
{
    if (!empty($_SESSION['admin_blog'])) { return true; }
    $t = (string) ($_GET['token'] ?? $_COOKIE['kiosk_token'] ?? '');
    if (!estoque_kiosk_token_valido($t)) { return false; }
    if (isset($_GET['token'])) {   // primeiro acesso pelo link: fixa o cookie
        setcookie('kiosk_token', $t, [
            'expires' => time() + 315360000,   // ~10 anos
            'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
    }
    return true;
}

// ------------------- Colaboradores (identificação no quiosque) ----------
// Cada colaborador tem um PIN de 4 dígitos (guardado com hash). No quiosque a
// pessoa escolhe o nome e digita o PIN antes de dar baixa; o nome vira o
// "responsável" da movimentação.

function estoque_colaboradores_listar(PDO $pdo, bool $soAtivos = true): array
{
    $sql = "SELECT id, nome, ativo FROM estoque_colaboradores";
    if ($soAtivos) { $sql .= " WHERE ativo = 1"; }
    $sql .= " ORDER BY nome";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function estoque_colaborador_criar(PDO $pdo, string $nome, string $pin): void
{
    $pin = preg_replace('/\D/', '', $pin);
    if (mb_strlen(trim($nome)) < 2) { throw new InvalidArgumentException('Informe o nome.'); }
    if (strlen($pin) !== 4)         { throw new InvalidArgumentException('O PIN deve ter 4 dígitos.'); }
    $pdo->prepare("INSERT INTO estoque_colaboradores (nome, pin_hash) VALUES (:n, :p)")
        ->execute([':n' => trim($nome), ':p' => password_hash($pin, PASSWORD_DEFAULT)]);
}

function estoque_colaborador_atualizar_pin(PDO $pdo, int $id, string $pin): void
{
    $pin = preg_replace('/\D/', '', $pin);
    if (strlen($pin) !== 4) { throw new InvalidArgumentException('O PIN deve ter 4 dígitos.'); }
    $pdo->prepare("UPDATE estoque_colaboradores SET pin_hash = :p WHERE id = :id")
        ->execute([':p' => password_hash($pin, PASSWORD_DEFAULT), ':id' => $id]);
}

function estoque_colaborador_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE estoque_colaboradores SET ativo = 0 WHERE id = :id")->execute([':id' => $id]);
}

/** Verifica o PIN. Devolve o nome se bater, senão null. */
function estoque_colaborador_verificar(PDO $pdo, int $id, string $pin): ?string
{
    $pin = preg_replace('/\D/', '', $pin);
    $stmt = $pdo->prepare("SELECT nome, pin_hash FROM estoque_colaboradores WHERE id = :id AND ativo = 1");
    $stmt->execute([':id' => $id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($c && password_verify($pin, $c['pin_hash'])) { return $c['nome']; }
    return null;
}

function estoque_colaborador_nome(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare("SELECT nome FROM estoque_colaboradores WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/** A coluna 'estornado' já existe? (setup migra). */
function estoque_mov_tem_estornado(PDO $pdo): bool
{
    static $tem = null;
    if ($tem === null) {
        try { $pdo->query("SELECT estornado FROM estoque_movimentacoes LIMIT 1"); $tem = true; }
        catch (\Throwable $e) { $tem = false; }
    }
    return $tem;
}

/**
 * Estorna uma movimentação de entrada/saída: desfaz o efeito no saldo e marca
 * a linha como estornada (fica no histórico, riscada). Ajuste não é estornável.
 */
function estoque_estornar_movimentacao(PDO $pdo, int $movId, string $responsavel = ''): void
{
    if (!estoque_mov_tem_estornado($pdo)) {
        throw new RuntimeException('Recurso indisponível — rode o estoque_setup.php.');
    }
    $temPor = false;
    try { $pdo->query("SELECT estornado_por FROM estoque_movimentacoes LIMIT 1"); $temPor = true; } catch (\Throwable $e) {}
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM estoque_movimentacoes WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $movId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) { throw new RuntimeException('Movimentação não encontrada.'); }
        if (!empty($m['estornado'])) { throw new RuntimeException('Esta movimentação já foi estornada.'); }
        if (!in_array($m['tipo'], ['entrada', 'saida'], true)) {
            throw new RuntimeException('Só entrada e saída podem ser estornadas.');
        }
        $qtd = (float) $m['quantidade'];
        $delta = $m['tipo'] === 'entrada' ? -$qtd : $qtd;   // desfaz o efeito no saldo
        $pdo->prepare("UPDATE estoque_itens SET estoque_atual = estoque_atual + :d WHERE id = :id")
            ->execute([':d' => $delta, ':id' => (int) $m['item_id']]);
        if ($temPor) {
            $pdo->prepare("UPDATE estoque_movimentacoes SET estornado = 1, estornado_por = :p, estornado_em = NOW() WHERE id = :id")
                ->execute([':p' => $responsavel !== '' ? $responsavel : null, ':id' => $movId]);
        } else {
            $pdo->prepare("UPDATE estoque_movimentacoes SET estornado = 1 WHERE id = :id")->execute([':id' => $movId]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Últimas movimentações de um item. */
function estoque_movimentacoes(PDO $pdo, int $itemId, int $limite = 30): array
{
    $stmt = $pdo->prepare("SELECT * FROM estoque_movimentacoes WHERE item_id = :id ORDER BY criado_em DESC, id DESC LIMIT $limite");
    $stmt->execute([':id' => $itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Normaliza um nome/descrição para comparar (minúsculo, sem acento, sem espaço extra). */
function estoque_normalizar_nome(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e',
                    'í'=>'i','ì'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
                    'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Mapa alias_normalizado => item_id (só itens ativos). [] se a tabela não existir. */
function estoque_aliases_map(PDO $pdo): array
{
    try {
        $rows = $pdo->query("
            SELECT a.alias, a.item_id
            FROM estoque_item_aliases a
            JOIN estoque_itens i ON i.id = a.item_id AND i.ativo = 1
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/** Aprende: liga a descrição (ex.: como vem no cupom) a um item do estoque. */
function estoque_alias_salvar(PDO $pdo, string $descricao, int $itemId): void
{
    $alias = mb_substr(estoque_normalizar_nome($descricao), 0, 190, 'UTF-8');
    if ($alias === '' || $itemId <= 0) { return; }
    try {
        $pdo->prepare("INSERT INTO estoque_item_aliases (alias, item_id) VALUES (:a, :i)
                       ON DUPLICATE KEY UPDATE item_id = :i2")
            ->execute([':a' => $alias, ':i' => $itemId, ':i2' => $itemId]);
    } catch (\Throwable $e) { /* tabela ausente: ignora silenciosamente */ }
}

/**
 * Casa um item da nota/cupom (código de barras + descrição) com um item do estoque.
 * Prioridade: ALIAS aprendido > código de barras > semelhança de nome (>=60%) > nenhum.
 * Recebe $itensCache (itens ativos) e $aliases (mapa aprendido) para não consultar por linha.
 *
 * @return array{item_id:?int, match:string}  match: 'alias'|'barcode'|'nome'|'nenhum'
 */
function estoque_casar_item(string $ean, string $descricao, array $itensCache, array $aliases = []): array
{
    // 1) Alias aprendido (a mesma descrição já foi casada antes).
    $norm = estoque_normalizar_nome($descricao);
    if ($norm !== '' && isset($aliases[$norm])) {
        return ['item_id' => (int) $aliases[$norm], 'match' => 'alias'];
    }
    // 2) Código exato: o da nota (fardo) pode estar no código de compra OU no de barras.
    $ean = preg_replace('/\D/', '', $ean);
    if ($ean !== '') {
        foreach ($itensCache as $it) {
            $cb = preg_replace('/\D/', '', (string) ($it['codigo_barras'] ?? ''));
            $cc = preg_replace('/\D/', '', (string) ($it['codigo_compra'] ?? ''));
            if (($cb !== '' && $cb === $ean) || ($cc !== '' && $cc === $ean)) {
                return ['item_id' => (int) $it['id'], 'match' => 'barcode'];
            }
        }
    }
    $alvo = mb_strtolower(trim($descricao), 'UTF-8');
    if ($alvo !== '') {
        $melhorId = null; $melhorScore = 0.0;
        foreach ($itensCache as $it) {
            $pct = 0.0;
            similar_text($alvo, mb_strtolower((string) $it['nome'], 'UTF-8'), $pct);
            if ($pct > $melhorScore) { $melhorScore = $pct; $melhorId = (int) $it['id']; }
        }
        if ($melhorScore >= 60.0 && $melhorId !== null) {
            // Trava anti-falso-positivo: o 1º termo precisa bater (igual financeiro),
            // pra "COCA 2L" não casar com "SOS EMBALAGENS" só por palavra genérica.
            $t1 = explode(' ', $alvo)[0] ?? '';
            $nomeM = '';
            foreach ($itensCache as $it) { if ((int) $it['id'] === $melhorId) { $nomeM = (string) $it['nome']; break; } }
            $t2 = explode(' ', mb_strtolower($nomeM, 'UTF-8'))[0] ?? '';
            $pctP = 0.0;
            if ($t1 !== '' && $t2 !== '') { similar_text($t1, $t2, $pctP); }
            if ($pctP >= 70.0) {
                return ['item_id' => $melhorId, 'match' => 'nome'];
            }
        }
    }
    return ['item_id' => null, 'match' => 'nenhum'];
}

/** A nota (chave de acesso) já foi lançada no estoque? Devolve a data ou null. */
function estoque_nota_processada(PDO $pdo, string $chave): ?string
{
    $chave = preg_replace('/\D/', '', $chave);
    if ($chave === '') { return null; }
    try {
        $stmt = $pdo->prepare("SELECT criado_em FROM estoque_notas_processadas WHERE chave = :c");
        $stmt->execute([':c' => $chave]);
        $r = $stmt->fetchColumn();
        return $r ?: null;
    } catch (\Throwable $e) { return null; }
}

/** Registra a nota como processada (evita dobrar o estoque em reenvio). */
function estoque_nota_registrar(PDO $pdo, string $chave, string $desc = ''): void
{
    $chave = preg_replace('/\D/', '', $chave);
    if ($chave === '') { return; }
    try {
        $pdo->prepare("INSERT IGNORE INTO estoque_notas_processadas (chave, descricao) VALUES (:c, :d)")
            ->execute([':c' => $chave, ':d' => $desc !== '' ? mb_substr($desc, 0, 250) : null]);
    } catch (\Throwable $e) { /* tabela ausente: ignora */ }
}

/**
 * Extrai a quantidade por embalagem da DESCRIÇÃO (fardo/caixa costuma ter na
 * descrição): "COM 20 UN", "25 Unid", "10 Unid.", "C/ 50 UN". Null se não achar.
 */
function estoque_qtde_da_descricao(string $desc): ?int
{
    $d = mb_strtoupper($desc, 'UTF-8');
    if (preg_match('/\b(?:COM|C\/)\s*(\d{1,4})\s*UN/u', $d, $m)) { return (int) $m[1]; }  // COM 20 UN
    if (preg_match('/(\d{1,4})\s*UNID/u', $d, $m))               { return (int) $m[1]; }  // 25 UNID
    if (preg_match('/(\d{1,4})\s*UN\b/u', $d, $m))               { return (int) $m[1]; }  // 20 UN
    return null;
}


/** Atualiza o preço (valor unitário) de um item — usado na entrada por nota/cupom. */
function estoque_atualizar_preco(PDO $pdo, int $itemId, float $preco): void
{
    if ($preco <= 0 || $itemId <= 0) { return; }
    $pdo->prepare("UPDATE estoque_itens SET preco = :p WHERE id = :id")
        ->execute([':p' => round($preco, 2), ':id' => $itemId]);
}

/** Grava o código de barras (UNIDADE, escaneado no quiosque) se ainda não tiver. */
function estoque_definir_barcode(PDO $pdo, int $itemId, string $ean): void
{
    $ean = preg_replace('/\D/', '', $ean);
    if ($ean === '') { return; }
    $pdo->prepare("UPDATE estoque_itens SET codigo_barras = :c WHERE id = :id AND (codigo_barras IS NULL OR codigo_barras = '')")
        ->execute([':c' => $ean, ':id' => $itemId]);
}

/** Grava o código de COMPRA (o que vem na nota/fardo) se ainda não tiver. */
function estoque_definir_codigo_compra(PDO $pdo, int $itemId, string $ean): void
{
    $ean = preg_replace('/\D/', '', $ean);
    if ($ean === '' || !estoque_tem_codigo_compra($pdo)) { return; }
    $pdo->prepare("UPDATE estoque_itens SET codigo_compra = :c WHERE id = :id AND (codigo_compra IS NULL OR codigo_compra = '')")
        ->execute([':c' => $ean, ':id' => $itemId]);
}

/**
 * Lista de compra: itens com saldo abaixo do mínimo. Para cada um, calcula a
 * quantidade a comprar para chegar ao ideal e o custo estimado.
 */
function estoque_lista_compra(PDO $pdo): array
{
    // Só sugere comprar o que está sob controle de estoque.
    $soControlados = estoque_tem_controlar($pdo) ? ' AND controlar_estoque = 1' : '';
    $itens = $pdo->query("
        SELECT * FROM estoque_itens
        WHERE ativo = 1 AND estoque_minimo IS NOT NULL AND estoque_atual < estoque_minimo" . $soControlados . "
        ORDER BY fornecedor, nome
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($itens as &$it) {
        $ideal = $it['estoque_ideal'] !== null ? (float) $it['estoque_ideal'] : (float) $it['estoque_minimo'];
        $falta = max(0, $ideal - (float) $it['estoque_atual']);
        $it['qtde_comprar'] = $falta;
        $it['custo_estimado'] = $it['preco'] !== null ? $falta * (float) $it['preco'] : null;
    }
    return $itens;
}
