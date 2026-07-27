<?php
// Vincula as fotos extraídas dos PDFs (já processadas em img/imagens/catalogo/)
// aos produtos e às capas de categoria. Idempotente. Roda por web (logado) ou CLI.
// Rodar UMA vez após catalogo_seed_pdf.php (inclusive em produção após o deploy).
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/_auth.php';
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/../includes/banco.php';

$dir = '/img/imagens/catalogo/';
$base = dirname(__DIR__);

// Produto (nome exato) => arquivo em img/imagens/catalogo/
$produtos = [
    'Cupcake'              => 'cupcake.jpg',
    'Bento Cake'           => 'bento-cake.jpg',
    'Bem-Casado'           => 'bem-casado.jpg',
    'Bolo de Chocolate'    => 'bolo-chocolate.jpg',
    'Bolinha de Queijo'    => 'bolinha-queijo.jpg',
    'Bombom de Brigadeiro' => 'bombom-brigadeiro.jpg',
];
// Categoria (slug) => capa
$capas = [
    'docinhos'    => 'capa-docinhos.jpg',
    'salgadinhos' => 'capa-salgadinhos.jpg',
    'combos'      => 'capa-combos.jpg',
    'bolos'       => 'capa-bolos.jpg',
];

$log = [];
$upP = $pdo->prepare("UPDATE catalogo_produtos SET imagem = ? WHERE nome = ? AND (imagem IS NULL OR imagem = '' OR imagem LIKE ?)");
foreach ($produtos as $nome => $arq) {
    if (!is_file($base . $dir . $arq)) { $log[] = "!!  falta arquivo $arq"; continue; }
    $upP->execute([$dir . $arq, $nome, $dir . '%']);
    $log[] = "produto  $nome -> $arq";
}
$upC = $pdo->prepare("UPDATE catalogo_categorias SET imagem_capa = ? WHERE slug = ?");
foreach ($capas as $slug => $arq) {
    if (!is_file($base . $dir . $arq)) { $log[] = "!!  falta arquivo $arq"; continue; }
    $upC->execute([$dir . $arq, $slug]);
    $log[] = "capa     $slug -> $arq";
}

// Recheios de bolo (sem foto própria) herdam a capa da categoria Bolos.
// Não toca nas Decorações (têm fotos próprias) nem sobrescreve fotos manuais.
if (is_file($base . $dir . 'capa-bolos.jpg')) {
    $st = $pdo->prepare(
        "UPDATE catalogo_produtos p
           JOIN catalogo_subcategorias s ON s.id = p.subcategoria_id
           JOIN catalogo_categorias   c ON c.id = s.categoria_id
            SET p.imagem = ?
          WHERE c.slug = 'bolos' AND s.slug <> 'decoracoes'
            AND (p.imagem IS NULL OR p.imagem = '')"
    );
    $st->execute([$dir . 'capa-bolos.jpg']);
    $log[] = 'bolos    ' . $st->rowCount() . ' recheio(s) herdaram a capa';
}

$log[] = 'Pronto.';
echo implode("\n", $log) . "\n";
