<?php
// Setup do módulo Catálogo: cria as tabelas (se não existirem) e semeia as
// categorias/subcategorias iniciais a partir das páginas atuais do site.
// Idempotente — pode rodar de novo sem duplicar. Acesse uma vez logado em
// /admin/catalogo_setup.php
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/_auth.php';
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_catalogo.php';   // catalogo_slug
$log = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS catalogo_categorias (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            slug          VARCHAR(80)  NOT NULL,
            nome          VARCHAR(120) NOT NULL,
            descricao     TEXT NULL,
            imagem_capa   VARCHAR(255) NULL,
            ordem         INT NOT NULL DEFAULT 0,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cat_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela catalogo_categorias';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS catalogo_subcategorias (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            categoria_id  INT NOT NULL,
            slug          VARCHAR(80)  NOT NULL,
            nome          VARCHAR(120) NOT NULL,
            ordem         INT NOT NULL DEFAULT 0,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sub (categoria_id, slug),
            INDEX (categoria_id),
            CONSTRAINT fk_sub_cat FOREIGN KEY (categoria_id)
                REFERENCES catalogo_categorias(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela catalogo_subcategorias';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS catalogo_produtos (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            subcategoria_id  INT NOT NULL,
            nome             VARCHAR(160) NOT NULL,
            descricao        TEXT NULL,
            imagem           VARCHAR(255) NULL,
            instrucao_pedido VARCHAR(200) NULL,
            preco_rotulo     VARCHAR(80)  NULL,
            ordem            INT NOT NULL DEFAULT 0,
            ativo            TINYINT(1) NOT NULL DEFAULT 1,
            criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (subcategoria_id),
            INDEX (nome),
            CONSTRAINT fk_prod_sub FOREIGN KEY (subcategoria_id)
                REFERENCES catalogo_subcategorias(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela catalogo_produtos';

    // Faixa de preço flexível: 'rotulo' é texto livre ("100 unidades",
    // "25 fatias", "Pequeno c/ recheio"); valor_cheio cobre o "de/por" dos
    // combos; obs guarda "escolha até 5 variações" etc.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS catalogo_precos (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            produto_id  INT NOT NULL,
            rotulo      VARCHAR(80) NULL,
            valor       DECIMAL(10,2) NOT NULL,
            valor_cheio DECIMAL(10,2) NULL,
            obs         VARCHAR(160) NULL,
            ordem       INT NOT NULL DEFAULT 0,
            INDEX (produto_id),
            CONSTRAINT fk_preco_prod FOREIGN KEY (produto_id)
                REFERENCES catalogo_produtos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela catalogo_precos';

    // --- Semeia categorias + subcategorias iniciais (só as que faltarem) ------
    // Espelha as páginas atuais do site; produtos entram pelo CRUD ou migração.
    // Estrutura conforme os PDFs de cardápio (av_festas, av_caseiros etc.).
    $seed = [
        'docinhos' => [
            'nome' => 'Docinhos', 'ordem' => 1,
            'subs' => ['Tradicionais', 'Especiais', 'Gourmet', 'Bombons', 'Copinhos', 'Finos Especiais'],
        ],
        'salgadinhos' => [
            'nome' => 'Salgadinhos', 'ordem' => 2,
            'subs' => ['Tradicionais', 'Especiais', 'Assados', 'Artesanais'],
        ],
        'bolos' => [
            'nome' => 'Bolos', 'ordem' => 3,
            'subs' => ['Tradicionais', 'Especiais', 'Especiais II', 'Especiais III', 'Gourmet', 'Decorações'],
        ],
        'bolos-caseiros' => [
            'nome' => 'Bolos Caseiros', 'ordem' => 4,
            'subs' => ['Chocolate', 'Cenoura'],
        ],
        'cupcakes' => [
            'nome' => 'Cupcakes', 'ordem' => 5,
            'subs' => ['Cupcakes'],
        ],
        'bento-cake' => [
            'nome' => 'Bento Cake', 'ordem' => 6,
            'subs' => ['Bento Cake'],
        ],
        'bem-casado' => [
            'nome' => 'Bem-Casado', 'ordem' => 7,
            'subs' => ['Bem-Casado'],
        ],
        'combos' => [
            'nome' => 'Combos', 'ordem' => 8,
            'subs' => ['Festa Completa', 'Coffee'],
        ],
    ];

    $catSel  = $pdo->prepare("SELECT id FROM catalogo_categorias WHERE slug = ?");
    $catIns  = $pdo->prepare("INSERT INTO catalogo_categorias (slug, nome, ordem) VALUES (?,?,?)");
    $subSel  = $pdo->prepare("SELECT id FROM catalogo_subcategorias WHERE categoria_id = ? AND slug = ?");
    $subIns  = $pdo->prepare("INSERT INTO catalogo_subcategorias (categoria_id, slug, nome, ordem) VALUES (?,?,?,?)");

    foreach ($seed as $slug => $cat) {
        $catSel->execute([$slug]);
        $catId = (int) $catSel->fetchColumn();
        if (!$catId) {
            $catIns->execute([$slug, $cat['nome'], $cat['ordem']]);
            $catId = (int) $pdo->lastInsertId();
            $log[] = "OK  categoria '{$cat['nome']}' criada";
        } else {
            $log[] = "..  categoria '{$cat['nome']}' já existe";
        }
        $ordemSub = 0;
        foreach ($cat['subs'] as $subNome) {
            $subSlug = catalogo_slug($subNome);
            $subSel->execute([$catId, $subSlug]);
            if (!$subSel->fetchColumn()) {
                $subIns->execute([$catId, $subSlug, $subNome, $ordemSub]);
                $log[] = "    + subcategoria '{$subNome}'";
            }
            $ordemSub++;
        }
    }

    $log[] = '';
    $log[] = 'Pronto. Próximo: cadastre produtos e preços em /admin/catalogo.php';
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
