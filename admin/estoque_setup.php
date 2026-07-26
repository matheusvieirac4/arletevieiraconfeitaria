<?php
// Setup do módulo de estoque: cria as tabelas (se não existirem) e importa o
// catálogo inicial da planilha. Idempotente — pode rodar de novo sem duplicar.
// Acesse uma vez em /admin/estoque_setup.php estando logado.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_estoque.php';   // estoque_medida_da_descricao

header('Content-Type: text/plain; charset=utf-8');
$log = [];

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS estoque_itens (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            nome           VARCHAR(255) NOT NULL,
            fornecedor     VARCHAR(255) NULL,
            preco          DECIMAL(10,2) NULL,
            peso_gramas    INT NULL,
            estoque_atual  DECIMAL(10,3) NOT NULL DEFAULT 0,
            estoque_minimo DECIMAL(10,3) NULL,
            estoque_ideal  DECIMAL(10,3) NULL,
            codigo_barras  VARCHAR(64) NULL,
            codigo_compra  VARCHAR(64) NULL,
            unidade_medida VARCHAR(4) NOT NULL DEFAULT 'UN',
            conteudo       DECIMAL(10,3) NULL,
            imagem         VARCHAR(255) NULL,
            ativo          TINYINT(1) NOT NULL DEFAULT 1,
            criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (codigo_barras),
            INDEX (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela estoque_itens';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS estoque_movimentacoes (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            item_id     INT NOT NULL,
            tipo        ENUM('entrada','saida','ajuste') NOT NULL,
            quantidade  DECIMAL(10,3) NOT NULL,
            saldo_apos  DECIMAL(10,3) NULL,
            origem      VARCHAR(32) NOT NULL DEFAULT 'manual',
            observacao  VARCHAR(255) NULL,
            responsavel VARCHAR(120) NULL,
            criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (item_id),
            INDEX (criado_em),
            CONSTRAINT fk_mov_item FOREIGN KEY (item_id) REFERENCES estoque_itens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela estoque_movimentacoes';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS estoque_colaboradores (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            nome      VARCHAR(120) NOT NULL,
            pin_hash  VARCHAR(255) NOT NULL,
            ativo     TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela estoque_colaboradores';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS estoque_item_aliases (
            alias     VARCHAR(190) NOT NULL PRIMARY KEY,
            item_id   INT NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela estoque_item_aliases';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS estoque_notas_processadas (
            chave     VARCHAR(64) NOT NULL PRIMARY KEY,
            descricao VARCHAR(255) NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela estoque_notas_processadas';

    // Migração: adiciona 'responsavel' se a tabela veio de uma versão anterior.
    try {
        $tem = $pdo->query("SHOW COLUMNS FROM estoque_movimentacoes LIKE 'responsavel'")->fetch();
        if (!$tem) {
            $pdo->exec("ALTER TABLE estoque_movimentacoes ADD COLUMN responsavel VARCHAR(120) NULL AFTER observacao");
            $log[] = 'OK  coluna responsavel adicionada';
        } else {
            $log[] = '..  coluna responsavel já existe';
        }
    } catch (\Throwable $e) {
        $log[] = 'ERRO ao migrar responsavel: ' . $e->getMessage();
    }

    // Migração: código de compra (o da nota/fardo, diferente do código da unidade).
    try {
        $tem = $pdo->query("SHOW COLUMNS FROM estoque_itens LIKE 'codigo_compra'")->fetch();
        if (!$tem) {
            $pdo->exec("ALTER TABLE estoque_itens ADD COLUMN codigo_compra VARCHAR(64) NULL AFTER codigo_barras");
            $log[] = 'OK  coluna codigo_compra adicionada';
        } else {
            $log[] = '..  coluna codigo_compra já existe';
        }
    } catch (\Throwable $e) {
        $log[] = 'ERRO ao migrar codigo_compra: ' . $e->getMessage();
    }

    // Migração: unidade de medida + conteúdo (modelo novo; substitui peso_gramas).
    try {
        $tem = $pdo->query("SHOW COLUMNS FROM estoque_itens LIKE 'unidade_medida'")->fetch();
        if (!$tem) {
            $pdo->exec("ALTER TABLE estoque_itens ADD COLUMN unidade_medida VARCHAR(4) NOT NULL DEFAULT 'UN' AFTER codigo_compra");
            $pdo->exec("ALTER TABLE estoque_itens ADD COLUMN conteudo DECIMAL(10,3) NULL AFTER unidade_medida");
            $log[] = 'OK  colunas unidade_medida e conteudo adicionadas';
        } else {
            $log[] = '..  colunas unidade_medida/conteudo já existem';
        }
    } catch (\Throwable $e) {
        $log[] = 'ERRO ao migrar unidade_medida: ' . $e->getMessage();
    }

    // Importa o catálogo só se a tabela estiver vazia (não duplica em re-runs).
    $qtd = (int) $pdo->query("SELECT COUNT(*) FROM estoque_itens")->fetchColumn();
    if ($qtd > 0) {
        $log[] = "..  catálogo já tem $qtd itens — importação pulada.";
    } else {
        $seed = require __DIR__ . '/lib/estoque_seed.php';
        $ins = $pdo->prepare("
            INSERT INTO estoque_itens (nome, fornecedor, preco, unidade_medida, conteudo, estoque_ideal, estoque_minimo, estoque_atual)
            VALUES (:nome, :forn, :preco, :un, :cont, :ideal, :minimo, 0)
        ");
        $n = 0;
        foreach ($seed as $r) {
            [$nome, $forn, $preco, $peso, $ideal] = $r;
            $medida = estoque_medida_da_descricao($nome);   // unidade + conteúdo da descrição
            $ins->execute([
                ':nome'   => $nome,
                ':forn'   => $forn !== '' ? $forn : null,
                ':preco'  => $preco,
                ':un'     => $medida[0] ?? 'UN',
                ':cont'   => $medida !== null ? $medida[1] : null,
                ':ideal'  => $ideal,
                ':minimo' => $ideal,   // mínimo começa igual ao ideal; ajuste depois
            ]);
            $n++;
        }
        $log[] = "OK  importados $n itens (unidade/conteúdo pela descrição; estoque=0, mínimo=ideal).";
    }
    $log[] = '';
    $log[] = 'Pronto. Próximo: cadastre a contagem inicial e comece a usar o /admin/estoque.php';
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
