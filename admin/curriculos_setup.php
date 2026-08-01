<?php
// Setup do módulo Currículos (Trabalhe Conosco): cria a tabela se não existir.
// Idempotente — pode rodar de novo. Acesse uma vez logado em
// /admin/curriculos_setup.php
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/_auth.php';
    header('Content-Type: text/plain; charset=utf-8');
}
require_once __DIR__ . '/../includes/banco.php';
$log = [];

try {
    // Campos espelham o Google Form atual; a tabela já nasce preparada para
    // crescer (PDF do currículo, dados que a IA vai extrair, status, origem).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS curriculos (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            nome            VARCHAR(160) NULL,
            email           VARCHAR(200) NOT NULL,
            data_nascimento DATE NULL,
            contato         VARCHAR(80)  NULL,
            bairro_cidade   VARCHAR(160) NULL,
            vaga_interesse  VARCHAR(160) NULL,
            cursos          TEXT NULL,
            experiencia     TEXT NULL,
            observacoes     TEXT NULL,
            curriculo_pdf   VARCHAR(255) NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'novo',
            origem          VARCHAR(20) NOT NULL DEFAULT 'site',
            ip              VARCHAR(45) NULL,
            criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (status),
            INDEX (email),
            INDEX (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $log[] = 'OK  tabela curriculos';

    // Pasta protegida para os PDFs (fora do alcance público via .htaccess).
    $dir = __DIR__ . '/data/curriculos';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        $log[] = 'OK  pasta admin/data/curriculos criada';
    } else {
        $log[] = '..  pasta admin/data/curriculos já existe';
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Deny from all\nRequire all denied\n");
        $log[] = 'OK  .htaccess de proteção criado (PDFs só via download autenticado)';
    }

    $log[] = '';
    $log[] = 'Pronto. Landing pública: /trabalhe-conosco.php | Admin: /admin/curriculos.php';
} catch (\Throwable $e) {
    http_response_code(500);
    $log[] = 'ERRO: ' . $e->getMessage();
}

echo implode("\n", $log) . "\n";
