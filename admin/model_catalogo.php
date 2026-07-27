<?php
// Modelo do Catálogo: Categorias -> Subcategorias -> Produtos -> Preços (faixas).
// Segue os padrões do módulo de estoque (tolerância a colunas, funções puras
// sobre $pdo). O front público (doces.php etc.) e o admin consomem daqui.
require_once __DIR__ . '/../includes/banco.php';

/** Normaliza um texto para slug (a-z0-9 e hífens). */
function catalogo_slug(string $txt): string
{
    $txt = trim($txt);
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
        if ($conv !== false) { $txt = $conv; }
    }
    $txt = strtolower($txt);
    $txt = preg_replace('/[^a-z0-9]+/', '-', $txt);
    return trim($txt, '-');
}

/** Formata um valor DECIMAL como "R$ 145,00". */
function catalogo_moeda($valor): string
{
    return 'R$ ' . number_format((float) $valor, 2, ',', '.');
}

/**
 * Página pública de uma categoria pelo slug. Categorias com página dedicada
 * (URLs já indexadas) usam o arquivo próprio; as demais caem no router genérico.
 */
function catalogo_pagina_do_slug(string $slug): string
{
    $map = [
        'docinhos'       => 'doces.php',
        'salgadinhos'    => 'salgados.php',
        'bolos'          => 'bolos.php',
        'bolos-caseiros' => 'bolos-caseiros.php',
        'cupcakes'       => 'cupcakes.php',
        'bento-cake'     => 'bento-cake.php',
        'bem-casado'     => 'bem-casado.php',
        'combos'         => 'combos.php',
    ];
    return $map[$slug] ?? ('cardapio.php?cat=' . urlencode($slug));
}

/**
 * Link da categoria no MODO CATÁLOGO (folder) — sempre pelo router, para manter
 * o header enxuto. Usado pela landing e pela navegação do folder. O menu do
 * SITE usa catalogo_pagina_do_slug() (páginas próprias, com o header do site).
 */
function catalogo_folder_do_slug(string $slug): string
{
    return 'cardapio.php?cat=' . rawurlencode($slug);
}

/** As tabelas do catálogo já existem? */
function catalogo_pronto(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM catalogo_produtos LIMIT 1");
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Mensagem padrão quando o setup ainda não rodou. */
function catalogo_exigir_setup(): void
{
    echo '<div class="alert alert-warning bg-white">'
       . 'O catálogo ainda não foi inicializado. Rode uma vez '
       . '<a href="catalogo_setup.php" target="_blank">catalogo_setup.php</a> e recarregue.'
       . '</div>';
}

// ------------------------------------------------------------------ Categorias

function catalogo_categorias_listar(PDO $pdo, bool $incluirInativas = false): array
{
    $sql = "SELECT * FROM catalogo_categorias";
    if (!$incluirInativas) { $sql .= " WHERE ativo = 1"; }
    $sql .= " ORDER BY ordem ASC, nome ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function catalogo_categoria_por_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM catalogo_categorias WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function catalogo_categoria_por_slug(PDO $pdo, string $slug): ?array
{
    $st = $pdo->prepare("SELECT * FROM catalogo_categorias WHERE slug = ?");
    $st->execute([$slug]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Insere/atualiza uma categoria. Retorna o id. */
function catalogo_categoria_salvar(PDO $pdo, array $d): int
{
    $id     = (int) ($d['id'] ?? 0);
    $nome   = trim((string) ($d['nome'] ?? ''));
    $slug   = catalogo_slug($d['slug'] ?? $nome) ?: catalogo_slug($nome);
    $desc   = trim((string) ($d['descricao'] ?? '')) ?: null;
    $capa   = trim((string) ($d['imagem_capa'] ?? '')) ?: null;
    $ordem  = (int) ($d['ordem'] ?? 0);
    $ativo  = !empty($d['ativo']) ? 1 : 0;

    if ($id > 0) {
        $st = $pdo->prepare(
            "UPDATE catalogo_categorias
                SET nome=?, slug=?, descricao=?, imagem_capa=?, ordem=?, ativo=?
              WHERE id=?"
        );
        $st->execute([$nome, $slug, $desc, $capa, $ordem, $ativo, $id]);
        return $id;
    }
    $st = $pdo->prepare(
        "INSERT INTO catalogo_categorias (nome, slug, descricao, imagem_capa, ordem, ativo)
         VALUES (?,?,?,?,?,?)"
    );
    $st->execute([$nome, $slug, $desc, $capa, $ordem, $ativo]);
    return (int) $pdo->lastInsertId();
}

function catalogo_categoria_excluir(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("DELETE FROM catalogo_categorias WHERE id = ?");
    $st->execute([$id]);
}

// --------------------------------------------------------------- Subcategorias

function catalogo_subcategorias_listar(PDO $pdo, int $categoriaId, bool $incluirInativas = true): array
{
    $sql = "SELECT * FROM catalogo_subcategorias WHERE categoria_id = ?";
    if (!$incluirInativas) { $sql .= " AND ativo = 1"; }
    $sql .= " ORDER BY ordem ASC, nome ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$categoriaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function catalogo_subcategoria_por_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM catalogo_subcategorias WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function catalogo_subcategoria_salvar(PDO $pdo, array $d): int
{
    $id    = (int) ($d['id'] ?? 0);
    $catId = (int) ($d['categoria_id'] ?? 0);
    $nome  = trim((string) ($d['nome'] ?? ''));
    $slug  = catalogo_slug($d['slug'] ?? $nome) ?: catalogo_slug($nome);
    $ordem = (int) ($d['ordem'] ?? 0);
    $ativo = !empty($d['ativo']) ? 1 : 0;

    if ($id > 0) {
        $st = $pdo->prepare(
            "UPDATE catalogo_subcategorias SET categoria_id=?, nome=?, slug=?, ordem=?, ativo=? WHERE id=?"
        );
        $st->execute([$catId, $nome, $slug, $ordem, $ativo, $id]);
        return $id;
    }
    $st = $pdo->prepare(
        "INSERT INTO catalogo_subcategorias (categoria_id, nome, slug, ordem, ativo) VALUES (?,?,?,?,?)"
    );
    $st->execute([$catId, $nome, $slug, $ordem, $ativo]);
    return (int) $pdo->lastInsertId();
}

function catalogo_subcategoria_excluir(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("DELETE FROM catalogo_subcategorias WHERE id = ?");
    $st->execute([$id]);
}

// -------------------------------------------------------------------- Produtos

/**
 * Lista produtos. $filtros: ['categoria_id'=>int, 'subcategoria_id'=>int,
 * 'busca'=>string, 'incluir_inativos'=>bool]. Cada item traz sub/categoria
 * (nome/slug) e o array 'precos'.
 */
function catalogo_produtos_listar(PDO $pdo, array $filtros = []): array
{
    $where = [];
    $args  = [];
    if (!empty($filtros['subcategoria_id'])) {
        $where[] = "p.subcategoria_id = ?"; $args[] = (int) $filtros['subcategoria_id'];
    }
    if (!empty($filtros['categoria_id'])) {
        $where[] = "s.categoria_id = ?"; $args[] = (int) $filtros['categoria_id'];
    }
    if (!empty($filtros['busca'])) {
        $where[] = "p.nome LIKE ?"; $args[] = '%' . $filtros['busca'] . '%';
    }
    if (empty($filtros['incluir_inativos'])) {
        $where[] = "p.ativo = 1";
    }
    $sql = "SELECT p.*, s.nome AS sub_nome, s.slug AS sub_slug,
                   s.categoria_id AS categoria_id, c.nome AS cat_nome, c.slug AS cat_slug
              FROM catalogo_produtos p
              JOIN catalogo_subcategorias s ON s.id = p.subcategoria_id
              JOIN catalogo_categorias   c ON c.id = s.categoria_id";
    if ($where) { $sql .= " WHERE " . implode(' AND ', $where); }
    $sql .= " ORDER BY s.ordem ASC, s.id ASC, p.ordem ASC, p.nome ASC";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $produtos = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($produtos as &$p) {
        $p['precos'] = catalogo_precos_do_produto($pdo, (int) $p['id']);
    }
    return $produtos;
}

function catalogo_produto_por_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM catalogo_produtos WHERE id = ?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { return null; }
    $p['precos'] = catalogo_precos_do_produto($pdo, $id);
    return $p;
}

function catalogo_produto_salvar(PDO $pdo, array $d): int
{
    $id       = (int) ($d['id'] ?? 0);
    $subId    = (int) ($d['subcategoria_id'] ?? 0);
    $nome     = trim((string) ($d['nome'] ?? ''));
    $desc     = trim((string) ($d['descricao'] ?? '')) ?: null;
    $imagem   = trim((string) ($d['imagem'] ?? '')) ?: null;
    $instr    = trim((string) ($d['instrucao_pedido'] ?? '')) ?: null;
    $rotulo   = trim((string) ($d['preco_rotulo'] ?? '')) ?: null;
    $ordem    = (int) ($d['ordem'] ?? 0);
    $ativo    = !empty($d['ativo']) ? 1 : 0;

    if ($id > 0) {
        $st = $pdo->prepare(
            "UPDATE catalogo_produtos
                SET subcategoria_id=?, nome=?, descricao=?, imagem=?, instrucao_pedido=?, preco_rotulo=?, ordem=?, ativo=?
              WHERE id=?"
        );
        $st->execute([$subId, $nome, $desc, $imagem, $instr, $rotulo, $ordem, $ativo, $id]);
    } else {
        $st = $pdo->prepare(
            "INSERT INTO catalogo_produtos
                (subcategoria_id, nome, descricao, imagem, instrucao_pedido, preco_rotulo, ordem, ativo)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        $st->execute([$subId, $nome, $desc, $imagem, $instr, $rotulo, $ordem, $ativo]);
        $id = (int) $pdo->lastInsertId();
    }

    if (array_key_exists('precos', $d) && is_array($d['precos'])) {
        catalogo_precos_substituir($pdo, $id, $d['precos']);
    }
    return $id;
}

function catalogo_produto_excluir(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("DELETE FROM catalogo_produtos WHERE id = ?");
    $st->execute([$id]);
}

// --------------------------------------------------------------------- Preços

function catalogo_precos_do_produto(PDO $pdo, int $produtoId): array
{
    $st = $pdo->prepare(
        "SELECT * FROM catalogo_precos WHERE produto_id = ? ORDER BY ordem ASC, id ASC"
    );
    $st->execute([$produtoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Substitui todas as faixas de preço do produto pelas informadas.
 * Cada faixa: ['rotulo'=>string, 'valor'=>num, 'valor_cheio'=>num|'', 'obs'=>string].
 */
function catalogo_precos_substituir(PDO $pdo, int $produtoId, array $precos): void
{
    $del = $pdo->prepare("DELETE FROM catalogo_precos WHERE produto_id = ?");
    $del->execute([$produtoId]);

    $ins = $pdo->prepare(
        "INSERT INTO catalogo_precos (produto_id, rotulo, valor, valor_cheio, obs, ordem)
         VALUES (?,?,?,?,?,?)"
    );
    $ordem = 0;
    foreach ($precos as $p) {
        $valor = (float) str_replace(',', '.', (string) ($p['valor'] ?? 0));
        if ($valor <= 0) { continue; }
        $rotulo = trim((string) ($p['rotulo'] ?? '')) ?: null;
        $cheioN = (float) str_replace(',', '.', (string) ($p['valor_cheio'] ?? 0));
        $cheio  = $cheioN > 0 ? $cheioN : null;
        $obs    = trim((string) ($p['obs'] ?? '')) ?: null;
        $ins->execute([$produtoId, $rotulo, $valor, $cheio, $obs, $ordem++]);
    }
}

/**
 * Valor de referência para o "A partir de" — a PRIMEIRA faixa (menor ordem),
 * não a mais barata. Assim o admin controla o destaque (ex.: o cento nos
 * docinhos, o menor tamanho nos bolos, o preço "por" nos combos). null se sem preço.
 */
function catalogo_preco_a_partir(array $produto): ?float
{
    if (empty($produto['precos'])) { return null; }
    $primeiro = $produto['precos'][0];
    return isset($primeiro['valor']) ? (float) $primeiro['valor'] : null;
}
