<?php
// Cria a subcategoria "Decorações" em Bolos e cadastra as decorações do PDF de
// Festas (foto + descrição, sem preço — valor sob consulta). Idempotente: pula
// o que já existe. Roda por web (logado) ou CLI. Imagens em img/imagens/catalogo/.
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/_auth.php';
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_catalogo.php';

$img = fn(string $f) => '/img/imagens/catalogo/' . $f;

// nome, arquivo, descrição (ordem = posição no array)
$decos = [
    ['Decoração Padrão',    'deco-padrao.jpg',        'Nossa decoração padrão, inclusa em todos os bolos.', 'Inclusa em todos os bolos.'],
    ['Semi Naked',          'deco-semi-naked.jpg',    'Uma fina camada de cobertura, que deixa partes da massa aparentes.'],
    ['Naked Cake',          'deco-naked.jpg',         'Um bolo rústico e elegante, sem cobertura, deixando visíveis as camadas de massa e recheio.'],
    ['Cor adicional',       'deco-cor-adicional.jpg', 'Adicione cores ao seu bolo e personalize no tema da sua festa.'],
    ['Pó parcial',          'deco-po-parcial.jpg',    'Pó cobrindo uma parte do bolo.'],
    ['Pó total',            'deco-po-total.jpg',      'Pó cobrindo todo o bolo.'],
    ['Pinceladas modelo 1', 'deco-pinceladas-1.jpg',  'Pinceladas coloridas subindo no bolo com as cores de sua preferência.'],
    ['Pinceladas modelo 2', 'deco-pinceladas-2.jpg',  'Pinceladas aleatórias por todo o bolo com as cores que você preferir.'],
    ['Floral',              'deco-floral.jpg',        'Flores feitas à mão com espátulas.'],
    ['Flork',               'deco-flork.jpg',         'Desenho estilo Flork em todo o bolo.'],
    ['Kit Kat',             'deco-kitkat.jpg',        'Kit Kat em volta de todo o bolo, finalizado com laço nas cores disponíveis.'],
    ['Morango',             'deco-morango.jpg',       'Adicione morangos na sua decoração.'],
    ['Docinhos',            'deco-docinhos.jpg',      'Adicione docinhos na sua decoração.'],
    ['Acetato + laço',      'deco-acetato-laco.jpg',  'Acetato transparente em volta do bolo com finalização de laço na cor de sua preferência.'],
    ['Red Velvet',          'deco-red-velvet.jpg',    'Troque a massa do seu bolo por Red Velvet.'],
    ['Vintage Cake',        'deco-vintage.jpg',       'Decoração em alta, com um toque vintage e moderno.'],
    ['Topo de Bolo',        'deco-topo.jpg',          'Personalize o seu bolo com o tema da sua festa.'],
    ['Personalizado',       'deco-personalizado.jpg', 'Envie sua foto e monte seu bolo conforme a sua ocasião ou tema.'],
];

$log = [];
$base = dirname(__DIR__);
try {
    $cat = catalogo_categoria_por_slug($pdo, 'bolos');
    if (!$cat) { throw new RuntimeException("categoria 'bolos' não existe (rode catalogo_setup.php)."); }
    $catId = (int) $cat['id'];

    // Garante a subcategoria Decorações (ordem 6, depois de Gourmet).
    $sel = $pdo->prepare("SELECT id FROM catalogo_subcategorias WHERE categoria_id = ? AND slug = 'decoracoes'");
    $sel->execute([$catId]);
    $subId = (int) $sel->fetchColumn();
    if (!$subId) {
        $subId = catalogo_subcategoria_salvar($pdo, [
            'categoria_id' => $catId, 'nome' => 'Decorações', 'slug' => 'decoracoes', 'ordem' => 6, 'ativo' => 1,
        ]);
        $log[] = "OK  subcategoria 'Decorações' criada";
    } else {
        $log[] = "..  subcategoria 'Decorações' já existe";
    }

    $ja = $pdo->prepare("SELECT id FROM catalogo_produtos WHERE subcategoria_id = ? AND nome = ?");
    $criados = 0; $pulados = 0;
    foreach ($decos as $i => $d) {
        [$nome, $arq, $desc] = $d;
        $instr = $d[3] ?? 'Valor adicional sob consulta pelo WhatsApp.';
        $ja->execute([$subId, $nome]);
        if ($ja->fetchColumn()) { $pulados++; continue; }
        $imagem = is_file($base . $img($arq)) ? $img($arq) : null;
        catalogo_produto_salvar($pdo, [
            'subcategoria_id'  => $subId,
            'nome'             => $nome,
            'descricao'        => $desc,
            'imagem'           => $imagem,
            'instrucao_pedido' => $instr,
            'ordem'            => $i + 1,
            'ativo'            => 1,
            // sem 'precos': decoração é valor sob consulta (card mostra "Consulte").
        ]);
        $criados++;
    }
    $log[] = "OK  $criados decoração(ões) criada(s), $pulados já existiam.";
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}
echo implode("\n", $log) . "\n";
