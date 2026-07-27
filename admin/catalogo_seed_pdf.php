<?php
// Pré-cadastro do catálogo a partir dos PDFs de cardápio (av_festas, av_caseiros,
// av_cupcakes, av_bentocake, av_bemcasados, av_combos). Idempotente: pula produto
// que já exista (mesmo nome na subcategoria). Roda por web (logado) ou CLI.
// Fonte dos valores: memory/catalogo-precos-pdf.md
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/_auth.php';
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_catalogo.php';

$log = [];
$img = fn(string $f) => '/img/imagens/doces/' . $f . '.png';

/** Monta uma faixa de preço. */
function f(string $rotulo, string $valor, string $obs = '', string $cheio = ''): array
{
    return ['rotulo' => $rotulo, 'valor' => $valor, 'obs' => $obs, 'valor_cheio' => $cheio];
}

// -------- Blocos de preço reutilizados (mesma tabela para vários sabores) ----
$docTrad = [f('100 unidades', '145,00', 'escolha até 5 variações'),
            f('50 unidades', '77,50', 'escolha até 3 variações'),
            f('25 unidades', '40,00', 'escolha até 2 variações')];
$docEsp  = [f('100 unidades', '185,00', 'escolha até 5 variações'),
            f('50 unidades', '95,00', 'escolha até 3 variações'),
            f('25 unidades', '50,00', 'escolha até 2 variações')];

$salTab = fn($a, $b, $c) => [f('100 unidades', $a, 'escolha até 5 variações'),
                             f('80 unidades', $b, 'escolha até 4 variações'),
                             f('60 unidades', $c, 'escolha até 3 variações')];

$boloTab = fn($v) => [f('12 fatias', $v[0]), f('20 fatias', $v[1]), f('25 fatias', $v[2]),
                      f('30 fatias', $v[3]), f('40 fatias', $v[4]), f('50 fatias', $v[5]),
                      f('70 fatias', $v[6])];
$caseiroTab = [f('Pequeno — sem recheio', '40,00', 'rende até 10 pedaços'),
               f('Pequeno — com recheio', '60,00', 'rende até 10 pedaços'),
               f('Médio — sem recheio', '55,00', 'rende até 20 pedaços'),
               f('Médio — com recheio', '80,00', 'rende até 20 pedaços'),
               f('Grande — sem recheio', '70,00', 'rende até 30 pedaços'),
               f('Grande — com recheio', '110,00', 'rende até 30 pedaços')];

// ---------------------------------------------------------- Estrutura completa
// cada categoria => subcategoria => ['rotulo','instrucao','precos'(default),'itens'=>[nome=>imagemFile|null]]
$dados = [
  'docinhos' => [
    'Tradicionais' => ['rotulo' => 'valor para o cento', 'instrucao' => 'Pedido a partir de 25 unidades.', 'precos' => $docTrad,
        'itens' => ['Beijinho'=>'beijinho','Brigadeiro'=>'brigadeiro','Brigadeiro Branco'=>null,'Cajuzinho'=>'cajuzinho','Casadinho'=>'casadinho','Nesquik'=>null,'Ninho'=>null,'Paçoca'=>null]],
    'Especiais' => ['rotulo' => 'valor para o cento', 'instrucao' => 'Pedido a partir de 25 unidades.', 'precos' => $docEsp,
        'itens' => ['Brûlée'=>'brule','Coco Queimado'=>'coco-queimado','Churros com Doce de Leite'=>'churros-docedeleite','Damasco'=>'damasco','Ferrero'=>'ferrero','Ninho com Nutella'=>'ninho-nutella','Nozes'=>'nozes','Olho de Sogra'=>'olho-sogra']],
    'Gourmet' => ['rotulo' => '', 'instrucao' => 'Mínimo 20 unidades por sabor.', 'precos' => [f('20 unidades','50,00')],
        'itens' => ['Brigadeiro ao Leite'=>'brigadeiro-aoleite','Brigadeiro Meio Amargo'=>'brigadeiro-meioamargo','Café'=>'cafe','Caramelo Salgado'=>'caramelo-salgado','Pistache'=>'pistache']],
    'Bombons' => ['rotulo' => '', 'instrucao' => 'Mínimo 20 unidades por sabor.', 'precos' => [f('20 unidades','70,00'), f('Unidade','3,50')],
        'itens' => ['Bombom de Amendoim'=>'bombom-amendoim','Bombom de Brigadeiro'=>null,'Bombom de Coco'=>'bombom-coco','Bombom de Damasco'=>null,'Bombom de Ninho'=>'bombom-ninho','Bombom de Nozes'=>null,'Bombom de Pedaços de Morango'=>'bombom-morango']],
    'Copinhos' => ['rotulo' => '', 'instrucao' => 'Mínimo 20 unidades por sabor.', 'precos' => [f('20 unidades','95,00'), f('Unidade','4,75')],
        'itens' => ['Copinho de Cereja'=>'copinho-cereja','Copinho de Maracujá'=>'copinho-maracuja','Copinho de Morango'=>'copinho-morango','Copinho de Physalis'=>'copinho-phisalys','Copinho de Uva'=>'copinho-uva']],
    'Finos Especiais' => ['rotulo' => '', 'instrucao' => 'Mínimo 20 unidades por sabor.', 'precos' => [f('Unidade','5,50')],
        'itens' => ['Almofadinha de Nutella'=>'almofada-nutella','Romeu e Julieta'=>'romeu-julieta','Mini Brownie'=>'mini-brownie','Tortinha de Limão'=>'torta-limao']],
  ],
  'salgadinhos' => [
    'Tradicionais' => ['rotulo' => 'valor para o cento', 'instrucao' => 'Pedido mínimo: 60 unidades.', 'precos' => $salTab('115,00','100,00','75,00'),
        'itens' => ['Bolinha de Queijo'=>null,'Coxinha'=>null,'Croquete de Brócolis'=>null,'Croquete de Calabresa e Queijo'=>null,'Pastel de Carne'=>null,'Pastel de Frango'=>null,'Quibe'=>null,'Risole de Carne'=>null,'Risole de Palmito'=>null,'Risole de Presunto e Queijo'=>null,'Salsicha Rodela'=>null]],
    'Especiais' => ['rotulo' => 'valor para o cento', 'instrucao' => 'Pedido mínimo: 60 unidades.', 'precos' => $salTab('140,00','116,00','90,00'),
        'itens' => ['Almôndega'=>null,'Frango Empanado'=>null,'Pastel de Camarão'=>null,'Risole de Camarão'=>null]],
    'Assados' => ['rotulo' => 'valor para o cento', 'instrucao' => 'Pedido mínimo: 60 unidades.', 'precos' => $salTab('185,00','152,00','117,00'),
        'itens' => ['Calzone de Palmito'=>null,'Calzone de Calabresa'=>null,'Calzone de Camarão'=>null,'Esfirra de Carne'=>null,'Esfirra de Frango'=>null,'Pastel de Brócolis'=>null,'Pastel de Carne'=>null,'Pastel de Frango'=>null]],
    'Artesanais' => ['rotulo' => 'valor para o cento', 'instrucao' => 'Pedido mínimo: 60 unidades.', 'precos' => null,  // preços variam por item
        'itens_full' => [
            ['nome'=>'Empada de Camarão', 'precos'=>$salTab('215,00','176,00','135,00')],
            ['nome'=>'Empada de Frango',  'precos'=>$salTab('215,00','176,00','135,00')],
            ['nome'=>'Empada de Palmito', 'precos'=>$salTab('215,00','176,00','135,00')],
            ['nome'=>'Mini Pizza de Calabresa', 'precos'=>$salTab('250,00','204,00','156,00')],
            ['nome'=>'Mini Pizza de Frango',    'precos'=>$salTab('250,00','204,00','156,00')],
            ['nome'=>'Mini Pizza de Queijo',    'precos'=>$salTab('250,00','204,00','156,00')],
        ]],
  ],
  'bolos' => [
    'Tradicionais' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Massa branca ou chocolate. ~100g por fatia. Decoração padrão.', 'precos' => $boloTab(['125,00','165,00','205,00','240,00','315,00','385,00','525,00']),
        'itens' => ['2 Amores'=>null,'4 Leites'=>null,'Amendoim'=>null,'Brigadeiro'=>null,'Brigadeiro de Ninho'=>null,'Doce de Leite'=>null,'Coco'=>null]],
    'Especiais' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Massa branca ou chocolate. Decoração padrão.', 'precos' => $boloTab(['145,00','195,00','240,00','280,00','365,00','445,00','615,00']),
        'itens' => ['Ameixa com Doce de Leite'=>null,'Damasco'=>null,'Coco com Doce de Leite'=>null,'Nozes'=>null,'Abacaxi com Coco'=>null,'Ouro Branco'=>null,'Morango com Nata'=>null,'Sonho de Valsa'=>null]],
    'Especiais II' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Recheios com morango: decoração no acetato.', 'precos' => $boloTab(['150,00','200,00','245,00','290,00','375,00','460,00','635,00']),
        'itens' => ['4 Leites com Morango'=>null,'Ninho com Morango'=>null,'Brigadeiro com Morango'=>null,'Strogonoff de Nozes'=>null]],
    'Gourmet' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Massa branca ou chocolate. Decoração padrão.', 'precos' => null,
        'itens_full' => [
            ['nome'=>'Meio Amargo',      'precos'=>$boloTab(['160,00','215,00','260,00','305,00','405,00','495,00','675,00'])],
            ['nome'=>'Ninho com Nutella','precos'=>$boloTab(['160,00','215,00','260,00','305,00','405,00','495,00','675,00'])],
            ['nome'=>'Brigadeiro Meio Amargo com Morango', 'precos'=>$boloTab(['175,00','235,00','285,00','335,00','440,00','540,00','740,00'])],
        ]],
    'Especiais III' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Massa branca ou chocolate. Decoração padrão.', 'precos' => null,
        'itens_full' => [
            ['nome'=>'Marta Rocha', 'precos'=>$boloTab(['185,00','245,00','305,00','355,00','465,00','570,00','780,00'])],
            ['nome'=>'Pistache',    'precos'=>$boloTab(['185,00','245,00','305,00','355,00','465,00','570,00','780,00'])],
        ]],
  ],
  'bolos-caseiros' => [
    'Chocolate' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Pequeno só forma redonda; médio e grande têm opção retangular.', 'precos' => $caseiroTab,
        'itens' => ['Bolo de Chocolate'=>null]],
    'Cenoura' => ['rotulo' => 'conforme o tamanho', 'instrucao' => 'Pequeno só forma redonda; médio e grande têm opção retangular.', 'precos' => $caseiroTab,
        'itens' => ['Bolo de Cenoura'=>null]],
  ],
  'cupcakes' => [
    'Cupcakes' => ['rotulo' => '', 'instrucao' => 'Mínimo 20 unidades por sabor. Massa branca ou chocolate; recheio brigadeiro, ninho ou doce de leite.', 'precos' => [f('20 unidades','110,00'), f('Unidade','5,50')],
        'itens' => ['Cupcake'=>null]],
  ],
  'bento-cake' => [
    'Bento Cake' => ['rotulo' => '', 'instrucao' => 'Porção individual (~10cm), 2 camadas de massa e 1 recheio.', 'precos' => [f('Valor','50,00')],
        'itens' => ['Bento Cake'=>null]],
  ],
  'bem-casado' => [
    'Bem-Casado' => ['rotulo' => '', 'instrucao' => 'Mínimo 30 unidades.', 'precos' => [f('30 unidades','255,00'), f('Unidade','8,50'), f('Adicional tag','8,00','para 30 unidades')],
        'itens' => ['Bem-Casado'=>null]],
  ],
  'combos' => [
    'Festa Completa' => ['rotulo' => 'o combo', 'instrucao' => 'Salgados e doces tradicionais + bolo recheios tradicionais, decoração padrão.', 'precos' => null,
        'itens_full' => [
            ['nome'=>'Só um Bolinho',   'precos'=>[f('Combo','337,50','Bolo 12 fatias + 150 salgadinhos + 50 docinhos (até 10 pessoas)','375,00')]],
            ['nome'=>'Vai Ter Festinha','precos'=>[f('Combo','795,25','Bolo 25 fatias + 400 salgadinhos + 150 docinhos (até 25 pessoas)','882,50')]],
            ['nome'=>'Festa Grande',    'precos'=>[f('Combo','1449,00','Bolo copa 50 fatias + 750 salgadinhos + 250 docinhos (até 50 pessoas)','1610,00')]],
        ]],
    'Coffee' => ['rotulo' => 'o combo', 'instrucao' => '', 'precos' => null,
        'itens_full' => [
            ['nome'=>'Coffee Simples', 'precos'=>[f('Combo','254,25','1 bolo caseiro médio + 100 salgadinhos + 50 docinhos + 2 refri 2L (até 10 pessoas)','282,50')]],
        ]],
  ],
];

try {
    $criados = 0; $pulados = 0;
    foreach ($dados as $catSlug => $subs) {
        $cat = catalogo_categoria_por_slug($pdo, $catSlug);
        if (!$cat) { $log[] = "!!  categoria '$catSlug' não existe (rode catalogo_setup.php)"; continue; }
        $subsBanco = catalogo_subcategorias_listar($pdo, (int) $cat['id']);
        $mapSub = [];
        foreach ($subsBanco as $s) { $mapSub[$s['nome']] = (int) $s['id']; }

        foreach ($subs as $subNome => $def) {
            $subId = $mapSub[$subNome] ?? null;
            if (!$subId) { $log[] = "!!  subcategoria '$catSlug / $subNome' não existe"; continue; }
            $ordem = 0;

            // Monta a lista uniforme de itens: [nome, imagem(file|null), precos]
            $itens = [];
            if (isset($def['itens_full'])) {
                foreach ($def['itens_full'] as $it) {
                    $itens[] = ['nome' => $it['nome'], 'imagem' => $it['imagem'] ?? null, 'precos' => $it['precos']];
                }
            } else {
                foreach ($def['itens'] as $nome => $imgFile) {
                    $precos = $def['precos'];
                    // Exceções de preço por sabor:
                    if ($catSlug === 'docinhos' && $subNome === 'Gourmet' && $nome === 'Pistache') { $precos = [f('20 unidades','60,00')]; }
                    if ($catSlug === 'docinhos' && $subNome === 'Finos Especiais' && $nome === 'Espiral de Pistache') { $precos = [f('Unidade','6,00')]; }
                    $itens[] = ['nome' => $nome, 'imagem' => $imgFile, 'precos' => $precos];
                }
                // Espiral de Pistache faz parte dos Finos, mas não estava no map principal:
                if ($catSlug === 'docinhos' && $subNome === 'Finos Especiais') {
                    $itens[] = ['nome' => 'Espiral de Pistache', 'imagem' => 'espiral-pistache', 'precos' => [f('Unidade','6,00')]];
                }
            }

            foreach ($itens as $it) {
                $ordem++;
                // Idempotência: pula se já existe produto com esse nome na subcategoria.
                $ja = $pdo->prepare("SELECT id FROM catalogo_produtos WHERE subcategoria_id = ? AND nome = ?");
                $ja->execute([$subId, $it['nome']]);
                if ($ja->fetchColumn()) { $pulados++; continue; }

                $imagem = null;
                if (!empty($it['imagem'])) {
                    $rel = '/img/imagens/doces/' . $it['imagem'] . '.png';
                    if (is_file(__DIR__ . '/..' . $rel)) { $imagem = $rel; }
                }
                catalogo_produto_salvar($pdo, [
                    'subcategoria_id'  => $subId,
                    'nome'             => $it['nome'],
                    'imagem'           => $imagem,
                    'instrucao_pedido' => $def['instrucao'] ?? '',
                    'preco_rotulo'     => $def['rotulo'] ?? '',
                    'ordem'            => $ordem,
                    'ativo'            => 1,
                    'precos'           => $it['precos'],
                ]);
                $criados++;
            }
        }
    }
    $log[] = "";
    $log[] = "OK  $criados produto(s) criado(s), $pulados já existiam (pulados).";
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
