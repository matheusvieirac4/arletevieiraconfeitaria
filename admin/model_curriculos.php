<?php
require_once __DIR__ . '/../includes/banco.php';

// Status possíveis do currículo (chave => rótulo/cor no painel).
function curriculos_status_map(): array {
    return [
        'novo'      => ['Novo',       'primary'],
        'analise'   => ['Em análise', 'warning'],
        'backlog'   => ['Backlog',    'info'],
        'aprovado'  => ['Aprovado',   'success'],
        'reprovado' => ['Reprovado',  'secondary'],
    ];
}

// Campos preenchidos pelo candidato (fonte: Google Form). Centralizado para o
// formulário público, o insert e a futura extração por IA usarem a mesma lista.
function curriculos_campos(): array {
    return ['nome', 'email', 'data_nascimento', 'contato', 'bairro_cidade',
            'vaga_interesse', 'cursos', 'experiencia', 'observacoes'];
}

function curriculos_listar($pdo, ?string $status = null): array {
    if ($status && $status !== 'todos') {
        $stmt = $pdo->prepare("SELECT * FROM curriculos WHERE status = ? ORDER BY criado_em DESC");
        $stmt->execute([$status]);
    } else {
        $stmt = $pdo->query("SELECT * FROM curriculos ORDER BY criado_em DESC");
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function curriculos_buscar($pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM curriculos WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// Contagem por status para os badges do topo da lista.
function curriculos_contagem($pdo): array {
    $out = ['todos' => 0];
    foreach (array_keys(curriculos_status_map()) as $s) { $out[$s] = 0; }
    try {
        $stmt = $pdo->query("SELECT status, COUNT(*) c FROM curriculos GROUP BY status");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['status']] = (int) $row['c'];
            $out['todos'] += (int) $row['c'];
        }
    } catch (\Throwable $e) { /* tabela ainda não criada */ }
    return $out;
}

/**
 * Insere uma candidatura. $dados usa as chaves de curriculos_campos() +
 * opcionais 'curriculo_pdf', 'origem', 'ip'. Retorna o id inserido.
 */
function curriculos_inserir($pdo, array $dados): int {
    $cols = array_merge(curriculos_campos(), ['curriculo_pdf', 'origem', 'ip']);
    $ph   = array_map(fn($c) => ":$c", $cols);
    $sql  = "INSERT INTO curriculos (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $stmt = $pdo->prepare($sql);
    $params = [];
    foreach ($cols as $c) {
        $v = $dados[$c] ?? null;
        if (is_string($v)) { $v = trim($v); if ($v === '') { $v = null; } }
        $params[":$c"] = $v;
    }
    if (empty($params[':origem'])) { $params[':origem'] = 'site'; }
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

function curriculos_atualizar_status($pdo, int $id, string $status): bool {
    if (!array_key_exists($status, curriculos_status_map())) { return false; }
    $stmt = $pdo->prepare("UPDATE curriculos SET status = ? WHERE id = ?");
    return $stmt->execute([$status, $id]);
}

function curriculos_deletar($pdo, int $id): bool {
    $stmt = $pdo->prepare("DELETE FROM curriculos WHERE id = ?");
    return $stmt->execute([$id]);
}
