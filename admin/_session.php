<?php
// ============================================================================
// Bootstrap da sessão do painel. Inclua ANTES de qualquer saída HTML.
//
// Por padrão o PHP encerra a sessão após ~24 min de inatividade e, em hospedagem
// compartilhada, o coletor de lixo de OUTROS sites pode apagar nossos arquivos
// de sessão. Aqui usamos uma pasta própria (protegida por .htaccess) e um tempo
// longo, de modo que a sessão só cai quando o usuário clica em "Sair" —
// ou em 30 dias, se ele marcou "Confiar neste dispositivo".
// ============================================================================

define('ADMIN_SESSAO_VIDA', 60 * 60 * 24 * 30); // 30 dias

/** (Re)envia o cookie de sessão com validade longa (dispositivo confiável). */
function admin_sessao_cookie_longo(): void
{
    setcookie(session_name(), session_id(), [
        'expires'  => time() + ADMIN_SESSAO_VIDA,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

/** Remove o cookie de sessão (usado no logout). */
function admin_sessao_cookie_limpar(): void
{
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

if (session_status() === PHP_SESSION_NONE) {
    // Não expirar por inatividade curta.
    @ini_set('session.gc_maxlifetime', (string) ADMIN_SESSAO_VIDA);

    // Pasta própria para os arquivos de sessão (fica sob admin/data, bloqueada
    // ao acesso web pelo .htaccess) — evita o GC de outros sites do servidor.
    $dirSessao = __DIR__ . '/data/sessions';
    if (!is_dir($dirSessao)) { @mkdir($dirSessao, 0700, true); }
    if (is_dir($dirSessao) && is_writable($dirSessao)) {
        @session_save_path($dirSessao);
    }

    session_set_cookie_params([
        'lifetime' => 0,   // padrão: encerra ao fechar o navegador
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();

    // Dispositivo confiável: renova os 30 dias a cada visita (janela deslizante).
    if (!empty($_SESSION['confiado'])) {
        admin_sessao_cookie_longo();
    }
}
