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

function estoque_listar(PDO $pdo, string $busca = '', bool $soAbaixoMinimo = false, string $ordem = 'nome', string $dir = 'asc'): array
{
    $sql = "SELECT * FROM estoque_itens WHERE ativo = 1";
    $params = [];
    if ($busca !== '') {
        $sql .= " AND (nome LIKE :b OR fornecedor LIKE :b OR codigo_barras LIKE :b)";
        $params[':b'] = '%' . $busca . '%';
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

function estoque_criar(PDO $pdo, array $d): int
{
    $stmt = $pdo->prepare("
        INSERT INTO estoque_itens (nome, fornecedor, preco, peso_gramas, estoque_atual, estoque_minimo, estoque_ideal, codigo_barras, imagem)
        VALUES (:nome, :forn, :preco, :peso, :atual, :minimo, :ideal, :barras, :imagem)
    ");
    $stmt->execute(estoque_params($d));
    return (int) $pdo->lastInsertId();
}

function estoque_atualizar(PDO $pdo, int $id, array $d): bool
{
    $p = estoque_params($d);
    $p[':id'] = $id;
    $stmt = $pdo->prepare("
        UPDATE estoque_itens SET nome=:nome, fornecedor=:forn, preco=:preco, peso_gramas=:peso,
               estoque_atual=:atual, estoque_minimo=:minimo, estoque_ideal=:ideal,
               codigo_barras=:barras, imagem=:imagem
        WHERE id=:id
    ");
    return $stmt->execute($p);
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
    return [
        ':nome'   => trim((string) ($d['nome'] ?? '')),
        ':forn'   => ($f = trim((string) ($d['fornecedor'] ?? ''))) !== '' ? $f : null,
        ':preco'  => $num($d['preco'] ?? ''),
        ':peso'   => ($pe = $num($d['peso_gramas'] ?? '')) !== null ? (int) $pe : null,
        ':atual'  => $num($d['estoque_atual'] ?? '') ?? 0,
        ':minimo' => $num($d['estoque_minimo'] ?? ''),
        ':ideal'  => $num($d['estoque_ideal'] ?? ''),
        ':barras' => $barras !== '' ? $barras : null,
        ':imagem' => ($im = trim((string) ($d['imagem'] ?? ''))) !== '' ? $im : null,
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

/** Últimas movimentações de um item. */
function estoque_movimentacoes(PDO $pdo, int $itemId, int $limite = 30): array
{
    $stmt = $pdo->prepare("SELECT * FROM estoque_movimentacoes WHERE item_id = :id ORDER BY criado_em DESC, id DESC LIMIT $limite");
    $stmt->execute([':id' => $itemId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Casa um item da nota (código de barras + descrição) com um item do estoque.
 * Prioridade: código de barras (exato) > semelhança de nome (>= 60%) > nenhum.
 * Recebe $itensCache (lista de itens ativos) para não consultar a cada linha.
 *
 * @return array{item_id:?int, match:string}  match: 'barcode'|'nome'|'nenhum'
 */
function estoque_casar_item(string $ean, string $descricao, array $itensCache): array
{
    $ean = preg_replace('/\D/', '', $ean);
    if ($ean !== '') {
        foreach ($itensCache as $it) {
            if (preg_replace('/\D/', '', (string) ($it['codigo_barras'] ?? '')) === $ean) {
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
        if ($melhorScore >= 60.0) {
            return ['item_id' => $melhorId, 'match' => 'nome'];
        }
    }
    return ['item_id' => null, 'match' => 'nenhum'];
}

/** Grava o código de barras num item se ele ainda não tiver. */
function estoque_definir_barcode(PDO $pdo, int $itemId, string $ean): void
{
    $ean = preg_replace('/\D/', '', $ean);
    if ($ean === '') { return; }
    $pdo->prepare("UPDATE estoque_itens SET codigo_barras = :c WHERE id = :id AND (codigo_barras IS NULL OR codigo_barras = '')")
        ->execute([':c' => $ean, ':id' => $itemId]);
}

/**
 * Lista de compra: itens com saldo abaixo do mínimo. Para cada um, calcula a
 * quantidade a comprar para chegar ao ideal e o custo estimado.
 */
function estoque_lista_compra(PDO $pdo): array
{
    $itens = $pdo->query("
        SELECT * FROM estoque_itens
        WHERE ativo = 1 AND estoque_minimo IS NOT NULL AND estoque_atual < estoque_minimo
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
