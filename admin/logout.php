<?php
require_once __DIR__ . '/_session.php';

$_SESSION = [];
admin_sessao_cookie_limpar();   // apaga o cookie, inclusive o de 30 dias
session_destroy();

header('Location: login.php');
exit;
