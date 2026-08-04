<?php
// Setup do módulo de ponto: cria as tabelas (se não existirem). Idempotente —
// pode rodar de novo sem problema. Acesse uma vez em /admin/ponto_setup.php
// estando logado. Reutiliza estoque_colaboradores como cadastro de pessoas.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/banco.php';

header('Content-Type: text/plain; charset=utf-8');
$log = [];

try {
    // Batidas do relógio: cada linha é UMA batida (entrada ou saída). Guardamos
    // 'data' (dia de competência = DATE(momento)) para agrupar rápido.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ponto_batidas (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            colaborador_id INT NOT NULL,
            data           DATE NOT NULL,
            momento        DATETIME NOT NULL,
            tipo           ENUM('entrada','saida') NOT NULL,
            origem         ENUM('kiosk','admin') NOT NULL DEFAULT 'kiosk',
            observacao     VARCHAR(255) NULL,
            criado_por     VARCHAR(120) NULL,
            criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (colaborador_id, data),
            INDEX (data),
            CONSTRAINT fk_ponto_colab FOREIGN KEY (colaborador_id)
                REFERENCES estoque_colaboradores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ponto_batidas';

    // Jornada por pessoa: horas esperadas por dia da semana + regras de
    // intervalo/tolerância. 1 linha por colaborador (PK = colaborador_id).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ponto_jornada (
            colaborador_id         INT NOT NULL PRIMARY KEY,
            tipo                   ENUM('socio','freelancer') NOT NULL DEFAULT 'freelancer',
            tem_meta               TINYINT(1) NOT NULL DEFAULT 1,
            h_dom DECIMAL(4,2) NOT NULL DEFAULT 0,
            h_seg DECIMAL(4,2) NOT NULL DEFAULT 0,
            h_ter DECIMAL(4,2) NOT NULL DEFAULT 0,
            h_qua DECIMAL(4,2) NOT NULL DEFAULT 0,
            h_qui DECIMAL(4,2) NOT NULL DEFAULT 0,
            h_sex DECIMAL(4,2) NOT NULL DEFAULT 0,
            h_sab DECIMAL(4,2) NOT NULL DEFAULT 0,
            intervalo_desconto_min INT NOT NULL DEFAULT 60,
            intervalo_limite_h     DECIMAL(4,2) NOT NULL DEFAULT 6,
            tolerancia_min         INT NOT NULL DEFAULT 10,
            atualizado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_jornada_colab FOREIGN KEY (colaborador_id)
                REFERENCES estoque_colaboradores(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela ponto_jornada';

    $log[] = '';
    $log[] = 'Pronto. Cadastre/ajuste a jornada de cada pessoa em /admin/ponto.php';
    $log[] = 'As pessoas e PINs são os mesmos do quiosque (Estoque -> Colaboradores).';
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
