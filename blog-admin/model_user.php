<?php
require_once __DIR__ . '/../includes/banco.php';

function user_listar($pdo) {
    $stmt = $pdo->query("SELECT id, nome, login FROM usuarios ORDER BY nome");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function user_buscar($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function user_inserir($pdo, $dados) {
    $sql = "INSERT INTO usuarios (nome, login, senha) VALUES (:nome, :login, :senha)";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([
        ':nome' => $dados['nome'],
        ':login' => $dados['login'],
        ':senha' => password_hash($dados['senha'], PASSWORD_DEFAULT)
    ]);
}
function user_atualizar($pdo, $id, $dados) {
    $sql = "UPDATE usuarios SET nome = :nome, login = :login";
    $params = [':nome' => $dados['nome'], ':login' => $dados['login'], ':id' => $id];
    if (!empty($dados['senha'])) {
        $sql .= ", senha = :senha";
        $params[':senha'] = password_hash($dados['senha'], PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}
function user_deletar($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
    return $stmt->execute([':id' => $id]);
}
function user_buscar_por_login($pdo, $login) {
    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE login = :login");
    $stmt->execute([':login' => $login]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
} 