<?php
// Setup do módulo de Ficha Técnica: cria as tabelas de receitas, produtos e o
// histórico de CMV. Idempotente — pode rodar de novo sem duplicar.
// Acesse uma vez em /admin/ficha_setup.php estando logado.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/banco.php';

header('Content-Type: text/plain; charset=utf-8');
$log = [];

try {
    // -- Receitas (bases, recheios, massas...): rendem em peso, com evaporação --
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_receitas (
            id                    INT AUTO_INCREMENT PRIMARY KEY,
            nome                  VARCHAR(255) NOT NULL,
            categoria             VARCHAR(120) NULL,
            percentual_evaporacao DECIMAL(5,2) NOT NULL DEFAULT 0,
            unidade_rendimento    VARCHAR(4) NOT NULL DEFAULT 'G',
            preparo               TEXT NULL,
            ativo                 TINYINT(1) NOT NULL DEFAULT 1,
            criado_em             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_receitas';

    // -- Ingredientes de cada receita: apontam para um item do estoque ----------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_receita_itens (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            receita_id INT NOT NULL,
            item_id    INT NOT NULL,
            quantidade DECIMAL(12,3) NOT NULL DEFAULT 0,
            ordem      INT NOT NULL DEFAULT 0,
            criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (receita_id),
            INDEX (item_id),
            CONSTRAINT fk_ri_receita FOREIGN KEY (receita_id) REFERENCES ficha_receitas(id) ON DELETE CASCADE,
            CONSTRAINT fk_ri_item    FOREIGN KEY (item_id)    REFERENCES estoque_itens(id)  ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_receita_itens';

    // -- Produtos vendáveis: têm preço de venda e um CMV -----------------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_produtos (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            nome          VARCHAR(255) NOT NULL,
            categoria     VARCHAR(120) NULL,
            preco_venda   DECIMAL(10,2) NULL,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_produtos';

    // -- Componentes do produto: recheio (=receita) OU ingrediente (=item) -----
    // bloco separa a exibição (Ingredientes / Recheios); tipo diz de onde vem o
    // custo. ref_id aponta para ficha_receitas.id (tipo=receita) ou estoque_itens.id.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_produto_componentes (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            produto_id INT NOT NULL,
            bloco      ENUM('ingrediente','recheio') NOT NULL DEFAULT 'ingrediente',
            tipo       ENUM('receita','item') NOT NULL,
            ref_id     INT NOT NULL,
            quantidade DECIMAL(12,3) NOT NULL DEFAULT 0,
            ordem      INT NOT NULL DEFAULT 0,
            criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (produto_id),
            CONSTRAINT fk_pc_produto FOREIGN KEY (produto_id) REFERENCES ficha_produtos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_produto_componentes';

    // -- Histórico de CMV: custo congelado por produto numa data ---------------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_cmv_snapshots (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            produto_id  INT NOT NULL,
            custo       DECIMAL(12,4) NOT NULL DEFAULT 0,
            preco_venda DECIMAL(10,2) NULL,
            cmv_pct     DECIMAL(6,2) NULL,
            responsavel VARCHAR(120) NULL,
            observacao  VARCHAR(255) NULL,
            criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (produto_id),
            INDEX (criado_em),
            CONSTRAINT fk_cmv_produto FOREIGN KEY (produto_id) REFERENCES ficha_produtos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_cmv_snapshots';

    $log[] = '';
    $log[] = 'Pronto. Acesse /admin/ficha_receitas.php para começar cadastrando as receitas.';
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
