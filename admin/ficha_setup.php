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

    // -- Histórico de custo/CMV: custo congelado numa data. Serve produtos (com
    // CMV) e receitas (com custo/un). ref_id aponta para ficha_produtos.id OU
    // ficha_receitas.id conforme 'tipo' — sem FK porque a referência é dupla. --
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_cmv_snapshots (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            tipo        ENUM('produto','receita') NOT NULL DEFAULT 'produto',
            ref_id      INT NOT NULL,
            custo       DECIMAL(12,4) NOT NULL DEFAULT 0,
            custo_por_g DECIMAL(14,6) NULL,
            preco_venda DECIMAL(10,2) NULL,
            cmv_pct     DECIMAL(6,2) NULL,
            motivo      VARCHAR(64) NULL,
            responsavel VARCHAR(120) NULL,
            observacao  VARCHAR(255) NULL,
            criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (tipo, ref_id),
            INDEX (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_cmv_snapshots';

    // -- Categorias das fichas (por tipo), para filtrar receitas/produtos ------
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_categorias (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            tipo      ENUM('receita','produto') NOT NULL,
            nome      VARCHAR(120) NOT NULL,
            ativo     TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cat (tipo, nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_categorias';

    // Semeia categorias de exemplo (só as que faltam — idempotente).
    $seedCats = [
        'receita' => ['Massas', 'Doces', 'Recheios', 'Coberturas', 'Ganaches'],
        'produto' => ['Doces', 'Salgados', 'Bolos', 'Cookies', 'Brownies', 'Bolos de pote', 'Tortas'],
    ];
    $insCat = $pdo->prepare("INSERT IGNORE INTO ficha_categorias (tipo, nome) VALUES (:t, :n)");
    $nCat = 0;
    foreach ($seedCats as $tp => $nomes) {
        foreach ($nomes as $nm) {
            if ($insCat->execute([':t' => $tp, ':n' => $nm]) && $insCat->rowCount() > 0) { $nCat++; }
        }
    }
    $log[] = "OK  $nCat categoria(s) de exemplo semeada(s).";

    // Migração: tabela de snapshots da 1ª versão (só produto_id/FK) → modelo novo.
    try {
        $temRef = $pdo->query("SHOW COLUMNS FROM ficha_cmv_snapshots LIKE 'ref_id'")->fetch();
        $temProd = $pdo->query("SHOW COLUMNS FROM ficha_cmv_snapshots LIKE 'produto_id'")->fetch();
        if ($temProd && !$temRef) {
            // Versão antiga detectada; recria vazia no formato novo (não havia dados
            // porque o setup ainda não tinha rodado em produção).
            $pdo->exec("DROP TABLE ficha_cmv_snapshots");
            $pdo->exec("
                CREATE TABLE ficha_cmv_snapshots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tipo ENUM('produto','receita') NOT NULL DEFAULT 'produto',
                    ref_id INT NOT NULL,
                    custo DECIMAL(12,4) NOT NULL DEFAULT 0,
                    custo_por_g DECIMAL(14,6) NULL,
                    preco_venda DECIMAL(10,2) NULL,
                    cmv_pct DECIMAL(6,2) NULL,
                    motivo VARCHAR(64) NULL,
                    responsavel VARCHAR(120) NULL,
                    observacao VARCHAR(255) NULL,
                    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX (tipo, ref_id), INDEX (criado_em)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $log[] = 'OK  ficha_cmv_snapshots migrada para o formato novo (tipo/ref_id).';
        }
    } catch (\Throwable $e) {
        $log[] = 'ERRO ao migrar ficha_cmv_snapshots: ' . $e->getMessage();
    }

    // -- Configuração do negócio (precificação): overhead, taxas e markups -----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ficha_config (
            chave VARCHAR(64) NOT NULL PRIMARY KEY,
            valor VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ficha_config';

    // Valores padrão (só insere o que faltar — não sobrescreve o que você editou).
    $seedCfg = [
        'overhead_pct'         => '50.638924',  // custos fixos + variáveis (%)
        'taxa_cartao'          => '0',
        'taxa_ifood'           => '23',
        'taxa_imposto'         => '4',
        'taxa_pgto_app'        => '3.5',
        'markup_direta_padrao' => '42.30',
        'markup_ifood_padrao'  => '40.50',
    ];
    $insCfg = $pdo->prepare("INSERT IGNORE INTO ficha_config (chave, valor) VALUES (:c, :v)");
    foreach ($seedCfg as $c => $v) { $insCfg->execute([':c' => $c, ':v' => $v]); }
    $log[] = 'OK  configuração do negócio semeada (edite em ficha_config.php).';

    // Migração: colunas de precificação por produto (markups + flag iFood).
    try {
        if (!$pdo->query("SHOW COLUMNS FROM ficha_produtos LIKE 'markup_direta'")->fetch()) {
            $pdo->exec("ALTER TABLE ficha_produtos ADD COLUMN markup_direta DECIMAL(6,3) NULL AFTER preco_venda");
            $pdo->exec("ALTER TABLE ficha_produtos ADD COLUMN markup_ifood  DECIMAL(6,3) NULL AFTER markup_direta");
            $pdo->exec("ALTER TABLE ficha_produtos ADD COLUMN vende_ifood   TINYINT(1) NOT NULL DEFAULT 1 AFTER markup_ifood");
            $log[] = 'OK  colunas markup_direta/markup_ifood/vende_ifood adicionadas em ficha_produtos.';
        } else {
            $log[] = '..  colunas de precificação já existem em ficha_produtos.';
        }
        // Incentivo (cupons/campanhas/di-ci/hits): R$ somado ao custo do produto.
        if (!$pdo->query("SHOW COLUMNS FROM ficha_produtos LIKE 'incentivo'")->fetch()) {
            $pdo->exec("ALTER TABLE ficha_produtos ADD COLUMN incentivo DECIMAL(10,2) NULL AFTER markup_ifood");
            $log[] = 'OK  coluna incentivo adicionada em ficha_produtos.';
        } else {
            $log[] = '..  coluna incentivo já existe em ficha_produtos.';
        }
    } catch (\Throwable $e) {
        $log[] = 'ERRO ao migrar precificação: ' . $e->getMessage();
    }

    $log[] = '';
    $log[] = 'Pronto. Acesse /admin/ficha_receitas.php para começar cadastrando as receitas.';
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
