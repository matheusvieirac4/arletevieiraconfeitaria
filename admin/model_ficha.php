<?php
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_estoque.php';   // estoque_preco_por_base, estoque_buscar

// ============================================================================
// Ficha técnica: receitas (bases/recheios/massas) e produtos (vendáveis).
//
// Custo SEMPRE ao vivo: o preço vem do estoque na hora de calcular, então
// reabastecer com preço novo reprecifica toda a ficha. O histórico de CMV
// (ficha_cmv_snapshots) congela o custo do dia como métrica de gestão.
//
// Regra-chave (igual à planilha da confeitaria):
//   peso_total = Σ quantidades dos ingredientes
//   peso_final = peso_total × (1 − % evaporação)
//   custo/g    = custo_total ÷ peso_final   ← sobre o peso ACABADO, não o cru
// ============================================================================

/** As tabelas da ficha técnica já existem? (o setup precisa ter rodado). */
function ficha_pronto(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM ficha_receitas LIMIT 1");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Aviso pedindo para rodar o setup. */
function ficha_exigir_setup(): void
{
    echo '<div class="card" style="max-width:640px;"><div class="card-body">'
       . '<h5 class="mb-2">Módulo de ficha técnica ainda não inicializado</h5>'
       . '<p class="text-muted">As tabelas precisam ser criadas uma vez. '
       . 'Clique abaixo para criá-las.</p>'
       . '<a href="ficha_setup.php" class="btn btn-primary">Inicializar ficha técnica agora</a>'
       . '</div></div>';
}

// ---------------------------------------------------------------------------
// RECEITAS
// ---------------------------------------------------------------------------

function ficha_receitas_listar(PDO $pdo, string $busca = ''): array
{
    $sql = "SELECT * FROM ficha_receitas WHERE ativo = 1";
    $params = [];
    if ($busca !== '') {
        $sql .= " AND (nome LIKE :b OR categoria LIKE :b)";
        $params[':b'] = '%' . $busca . '%';
    }
    $sql .= " ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ficha_receita_buscar(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM ficha_receitas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Ingredientes de uma receita, já com o item do estoque anexado. */
function ficha_receita_itens(PDO $pdo, int $receitaId): array
{
    $stmt = $pdo->prepare("
        SELECT ri.*, i.nome AS item_nome, i.preco AS item_preco,
               i.unidade_medida, i.conteudo
        FROM ficha_receita_itens ri
        JOIN estoque_itens i ON i.id = ri.item_id
        WHERE ri.receita_id = :id
        ORDER BY ri.ordem, ri.id
    ");
    $stmt->execute([':id' => $receitaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Cria/atualiza a receita (cabeçalho). Devolve o id. */
function ficha_receita_salvar(PDO $pdo, int $id, array $d): int
{
    $nome = trim((string) ($d['nome'] ?? ''));
    if ($nome === '') { throw new InvalidArgumentException('Informe o nome da receita.'); }
    $evap = estoque_num_manual($d['percentual_evaporacao'] ?? '0') ?? 0;
    if ($evap < 0 || $evap >= 100) { throw new InvalidArgumentException('% de evaporação deve ficar entre 0 e 99.'); }
    $un = strtoupper(trim((string) ($d['unidade_rendimento'] ?? 'G')));
    if (!isset(estoque_unidades_medida()[$un])) { $un = 'G'; }
    $cat = ($c = trim((string) ($d['categoria'] ?? ''))) !== '' ? $c : null;
    $prep = ($p = trim((string) ($d['preparo'] ?? ''))) !== '' ? $p : null;
    $params = [':nome' => $nome, ':cat' => $cat, ':evap' => $evap, ':un' => $un, ':prep' => $prep];
    if ($id > 0) {
        $params[':id'] = $id;
        $pdo->prepare("UPDATE ficha_receitas SET nome=:nome, categoria=:cat,
                       percentual_evaporacao=:evap, unidade_rendimento=:un, preparo=:prep
                       WHERE id=:id")->execute($params);
        return $id;
    }
    $pdo->prepare("INSERT INTO ficha_receitas (nome, categoria, percentual_evaporacao, unidade_rendimento, preparo)
                   VALUES (:nome, :cat, :evap, :un, :prep)")->execute($params);
    return (int) $pdo->lastInsertId();
}

/** Substitui todos os ingredientes da receita pelos informados (arrays paralelos). */
function ficha_receita_itens_salvar(PDO $pdo, int $receitaId, array $itemIds, array $qtds): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM ficha_receita_itens WHERE receita_id = :id")->execute([':id' => $receitaId]);
        $ins = $pdo->prepare("INSERT INTO ficha_receita_itens (receita_id, item_id, quantidade, ordem)
                              VALUES (:r, :i, :q, :o)");
        $ordem = 0;
        foreach ($itemIds as $k => $iid) {
            $iid = (int) $iid;
            $q = estoque_num_manual($qtds[$k] ?? '0') ?? 0;
            if ($iid <= 0 || $q <= 0) { continue; }
            $ins->execute([':r' => $receitaId, ':i' => $iid, ':q' => $q, ':o' => $ordem++]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ficha_receita_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE ficha_receitas SET ativo = 0 WHERE id = :id")->execute([':id' => $id]);
}

/**
 * Calcula o custo de uma receita ao vivo (preço atual do estoque).
 * Devolve:
 *   custo_total  R$ somando (qtd × custo/base do item)
 *   peso_total   Σ das quantidades
 *   peso_final   peso_total × (1 − evaporação)
 *   custo_por_g  custo_total ÷ peso_final  (R$ por unidade de rendimento)
 *   linhas       cada ingrediente com seu custo e situação de preço
 */
function ficha_receita_custo(PDO $pdo, int $receitaId): array
{
    $rec = ficha_receita_buscar($pdo, $receitaId);
    $evap = $rec ? (float) $rec['percentual_evaporacao'] : 0.0;
    $itens = ficha_receita_itens($pdo, $receitaId);

    $custoTotal = 0.0;
    $pesoTotal  = 0.0;
    $linhas = [];
    foreach ($itens as $it) {
        $qtd = (float) $it['quantidade'];
        $pb  = estoque_preco_por_base($it);   // ['valor'=>R$/base, 'rotulo'=>'g'|'ml'|'un'] ou null
        $custoLinha = $pb !== null ? $qtd * $pb['valor'] : null;
        if ($custoLinha !== null) { $custoTotal += $custoLinha; }
        $pesoTotal += $qtd;
        $linhas[] = [
            'item_id'     => (int) $it['item_id'],
            'item_nome'   => $it['item_nome'],
            'quantidade'  => $qtd,
            'preco_base'  => $pb['valor'] ?? null,
            'rotulo_base' => $pb['rotulo'] ?? null,
            'custo'       => $custoLinha,
            'sem_preco'   => $pb === null,
        ];
    }
    $pesoFinal = $pesoTotal * (1 - $evap / 100);
    $custoPorG = $pesoFinal > 0 ? $custoTotal / $pesoFinal : null;

    return [
        'custo_total' => $custoTotal,
        'peso_total'  => $pesoTotal,
        'peso_final'  => $pesoFinal,
        'evaporacao'  => $evap,
        'custo_por_g' => $custoPorG,
        'unidade'     => $rec['unidade_rendimento'] ?? 'G',
        'linhas'      => $linhas,
    ];
}

// ---------------------------------------------------------------------------
// PRODUTOS
// ---------------------------------------------------------------------------

function ficha_produtos_listar(PDO $pdo, string $busca = ''): array
{
    $sql = "SELECT * FROM ficha_produtos WHERE ativo = 1";
    $params = [];
    if ($busca !== '') {
        $sql .= " AND (nome LIKE :b OR categoria LIKE :b)";
        $params[':b'] = '%' . $busca . '%';
    }
    $sql .= " ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ficha_produto_buscar(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM ficha_produtos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Componentes de um produto (recheios + ingredientes), na ordem de exibição. */
function ficha_produto_componentes(PDO $pdo, int $produtoId): array
{
    $stmt = $pdo->prepare("SELECT * FROM ficha_produto_componentes
                           WHERE produto_id = :id ORDER BY bloco, ordem, id");
    $stmt->execute([':id' => $produtoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ficha_produto_salvar(PDO $pdo, int $id, array $d): int
{
    $nome = trim((string) ($d['nome'] ?? ''));
    if ($nome === '') { throw new InvalidArgumentException('Informe o nome do produto.'); }
    $cat = ($c = trim((string) ($d['categoria'] ?? ''))) !== '' ? $c : null;
    $preco = estoque_num_manual($d['preco_venda'] ?? '');
    $params = [':nome' => $nome, ':cat' => $cat, ':preco' => $preco];
    if ($id > 0) {
        $params[':id'] = $id;
        $pdo->prepare("UPDATE ficha_produtos SET nome=:nome, categoria=:cat, preco_venda=:preco WHERE id=:id")
            ->execute($params);
        return $id;
    }
    $pdo->prepare("INSERT INTO ficha_produtos (nome, categoria, preco_venda) VALUES (:nome, :cat, :preco)")
        ->execute($params);
    return (int) $pdo->lastInsertId();
}

/**
 * Substitui os componentes do produto. Recebe arrays paralelos, cada linha com
 * bloco (ingrediente|recheio), tipo (receita|item), ref_id e quantidade.
 */
function ficha_produto_componentes_salvar(PDO $pdo, int $produtoId, array $blocos, array $tipos, array $refs, array $qtds): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM ficha_produto_componentes WHERE produto_id = :id")->execute([':id' => $produtoId]);
        $ins = $pdo->prepare("INSERT INTO ficha_produto_componentes (produto_id, bloco, tipo, ref_id, quantidade, ordem)
                              VALUES (:p, :b, :t, :r, :q, :o)");
        $ordem = 0;
        foreach ($refs as $k => $ref) {
            $ref = (int) $ref;
            $q   = estoque_num_manual($qtds[$k] ?? '0') ?? 0;
            $tipo  = ($tipos[$k] ?? '') === 'receita' ? 'receita' : 'item';
            $bloco = ($blocos[$k] ?? '') === 'recheio' ? 'recheio' : 'ingrediente';
            if ($ref <= 0 || $q <= 0) { continue; }
            $ins->execute([':p' => $produtoId, ':b' => $bloco, ':t' => $tipo, ':r' => $ref, ':q' => $q, ':o' => $ordem++]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ficha_produto_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("UPDATE ficha_produtos SET ativo = 0 WHERE id = :id")->execute([':id' => $id]);
}

/**
 * Custo de um produto ao vivo. Para cada componente:
 *   - recheio (receita): quantidade (g) × custo/g da receita
 *   - ingrediente (item): quantidade (base) × custo/base do item
 * Devolve custo_total, cmv (%), margem e as linhas detalhadas por bloco.
 */
function ficha_produto_custo(PDO $pdo, int $produtoId): array
{
    $prod = ficha_produto_buscar($pdo, $produtoId);
    $preco = $prod && $prod['preco_venda'] !== null ? (float) $prod['preco_venda'] : null;
    $comps = ficha_produto_componentes($pdo, $produtoId);

    // Cache de custo/g das receitas usadas (evita recalcular a mesma várias vezes).
    $custoReceitaG = [];
    $custoTotal = 0.0;
    $linhas = ['ingrediente' => [], 'recheio' => []];

    foreach ($comps as $c) {
        $qtd = (float) $c['quantidade'];
        $ref = (int) $c['ref_id'];
        $nome = ''; $custoLinha = null; $rotulo = null; $semPreco = false;

        if ($c['tipo'] === 'receita') {
            if (!isset($custoReceitaG[$ref])) {
                $rc = ficha_receita_custo($pdo, $ref);
                $custoReceitaG[$ref] = $rc['custo_por_g'];
            }
            $rec = ficha_receita_buscar($pdo, $ref);
            $nome = $rec['nome'] ?? '(receita removida)';
            $cpg = $custoReceitaG[$ref];
            $custoLinha = $cpg !== null ? $qtd * $cpg : null;
            $rotulo = strtolower($rec['unidade_rendimento'] ?? 'g');
            $semPreco = $cpg === null;
        } else {
            $item = estoque_buscar($pdo, $ref);
            $nome = $item['nome'] ?? '(item removido)';
            $pb = $item ? estoque_preco_por_base($item) : null;
            if ($pb !== null) {
                $custoLinha = $qtd * $pb['valor'];
                $rotulo = $pb['rotulo'];
            } elseif ($item && $item['preco'] !== null) {
                // Item vendido por unidade simples (conteudo<=1): custo = preço × qtd.
                $custoLinha = $qtd * (float) $item['preco'];
                $rotulo = 'un';
            } else {
                $semPreco = true;
            }
        }
        if ($custoLinha !== null) { $custoTotal += $custoLinha; }
        $linhas[$c['bloco']][] = [
            'tipo'       => $c['tipo'],
            'ref_id'     => $ref,
            'nome'       => $nome,
            'quantidade' => $qtd,
            'rotulo'     => $rotulo,
            'custo'      => $custoLinha,
            'sem_preco'  => $semPreco,
        ];
    }

    $cmv = ($preco !== null && $preco > 0) ? ($custoTotal / $preco) * 100 : null;
    return [
        'custo_total' => $custoTotal,
        'preco_venda' => $preco,
        'cmv_pct'     => $cmv,
        'margem'      => $preco !== null ? $preco - $custoTotal : null,
        'linhas'      => $linhas,
    ];
}

// ---------------------------------------------------------------------------
// CMV — histórico (snapshots)
// ---------------------------------------------------------------------------

/** Congela o custo/CMV atual de um produto no histórico. */
function ficha_cmv_registrar(PDO $pdo, int $produtoId, string $responsavel = '', string $obs = ''): void
{
    $c = ficha_produto_custo($pdo, $produtoId);
    $pdo->prepare("INSERT INTO ficha_cmv_snapshots (produto_id, custo, preco_venda, cmv_pct, responsavel, observacao)
                   VALUES (:p, :c, :v, :m, :r, :o)")
        ->execute([
            ':p' => $produtoId,
            ':c' => round($c['custo_total'], 4),
            ':v' => $c['preco_venda'],
            ':m' => $c['cmv_pct'] !== null ? round($c['cmv_pct'], 2) : null,
            ':r' => $responsavel !== '' ? $responsavel : null,
            ':o' => $obs !== '' ? mb_substr($obs, 0, 250) : null,
        ]);
}

/** Snapshots de um produto (mais recentes primeiro). */
function ficha_cmv_historico(PDO $pdo, int $produtoId, int $limite = 60): array
{
    $limite = max(1, min(500, $limite));
    $stmt = $pdo->prepare("SELECT * FROM ficha_cmv_snapshots WHERE produto_id = :id
                           ORDER BY criado_em DESC, id DESC LIMIT $limite");
    $stmt->execute([':id' => $produtoId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
