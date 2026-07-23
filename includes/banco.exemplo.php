<?php

// ====== CONEXÃO COM O BANCO (MODELO) ======
// Copie este arquivo para "banco.php" e preencha com as credenciais reais.
// O arquivo "banco.php" está no .gitignore e NUNCA deve ser versionado.

$host = 'localhost';
$db   = 'NOME_DO_BANCO';
$user = 'USUARIO_DO_BANCO';
$pass = 'SENHA_DO_BANCO';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (\PDOException $e) {
    echo "Erro na conexão: " . $e->getMessage();
    exit;
}

?>
