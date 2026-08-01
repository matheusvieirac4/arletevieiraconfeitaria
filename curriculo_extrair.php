<?php
// Endpoint público (AJAX): recebe o PDF do currículo, salva o arquivo e usa o
// Gemini para extrair os campos. Devolve JSON com os dados encontrados + o nome
// do PDF salvo, para o formulário de revisão preencher os inputs. NÃO grava a
// candidatura — quem grava é o envio final do formulário (trabalhe-conosco.php).
require_once __DIR__ . '/includes/banco.php';
require_once __DIR__ . '/admin/model_curriculos.php';
require_once __DIR__ . '/admin/model_financeiro.php';   // config/chave do Gemini

header('Content-Type: application/json; charset=utf-8');

function cv_extrair_erro(string $msg, int $http = 400): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { cv_extrair_erro('Método inválido.', 405); }
if (!empty($_POST['website'])) { cv_extrair_erro('Requisição inválida.'); }   // honeypot

if (empty($_FILES['curriculo_pdf']) || $_FILES['curriculo_pdf']['error'] === UPLOAD_ERR_NO_FILE) {
    cv_extrair_erro('Anexe o seu currículo em PDF.');
}
$arq = $_FILES['curriculo_pdf'];
if ($arq['error'] !== UPLOAD_ERR_OK)      { cv_extrair_erro('Falha ao enviar o arquivo. Tente novamente.'); }
if ($arq['size'] > 8 * 1024 * 1024)       { cv_extrair_erro('O currículo em PDF deve ter no máximo 8 MB.'); }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($arq['tmp_name']);
$ext   = strtolower(pathinfo($arq['name'], PATHINFO_EXTENSION));
if ($mime !== 'application/pdf' || $ext !== 'pdf') {
    cv_extrair_erro('Envie o currículo apenas em formato PDF.');
}

// Extrai os campos com a IA (best-effort) enquanto o arquivo ainda está no tmp.
$dados = [];
foreach (curriculos_campos() as $campo) { $dados[$campo] = ''; }
$iaUsada = false;
if (financeiro_ia_configurada()) {
    try {
        $b64 = base64_encode((string) file_get_contents($arq['tmp_name']));
        $ext_ia = financeiro_gemini()->extrairCurriculo($b64);
        foreach (curriculos_campos() as $campo) {
            $dados[$campo] = trim((string) ($ext_ia[$campo] ?? ''));
        }
        // Normaliza a data de nascimento para YYYY-MM-DD (input date).
        if ($dados['data_nascimento'] !== '' && !DateTime::createFromFormat('Y-m-d', $dados['data_nascimento'])) {
            $dados['data_nascimento'] = '';
        }
        $iaUsada = true;
    } catch (\Throwable $e) {
        $iaUsada = false;   // segue salvando o PDF; revisão vem em branco.
    }
}

// Salva o PDF na pasta protegida; o formulário de revisão referencia pelo nome.
$dir = __DIR__ . '/admin/data/curriculos';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$nome = 'cv_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.pdf';
if (!move_uploaded_file($arq['tmp_name'], $dir . '/' . $nome)) {
    cv_extrair_erro('Não foi possível salvar o arquivo enviado.', 500);
}

echo json_encode([
    'ok'       => true,
    'pdf'      => $nome,
    'ia'       => $iaUsada,
    'dados'    => $dados,
], JSON_UNESCAPED_UNICODE);
