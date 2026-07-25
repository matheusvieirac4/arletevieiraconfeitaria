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

function estoque_listar(PDO $pdo, string $busca = '', bool $soAbaixoMinimo = false): array
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
    $sql .= " ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
function estoque_movimentar(PDO $pdo, int $itemId, string $tipo, float $qtd, string $origem = 'manual', string $obs = ''): float
{
    if (!in_array($tipo, ['entrada', 'saida', 'ajuste'], true)) {
        throw new InvalidArgumentException('Tipo de movimentação inválido.');
    }
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

        $pdo->prepare("
            INSERT INTO estoque_movimentacoes (item_id, tipo, quantidade, saldo_apos, origem, observacao)
            VALUES (:item, :tipo, :qtd, :saldo, :origem, :obs)
        ")->execute([
            ':item' => $itemId, ':tipo' => $tipo, ':qtd' => abs($qtd),
            ':saldo' => $novo, ':origem' => $origem, ':obs' => $obs !== '' ? $obs : null,
        ]);

        $pdo->commit();
        return (float) $novo;
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
