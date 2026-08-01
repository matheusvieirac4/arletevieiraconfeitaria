<?php
// Landing "Trabalhe Conosco": apresentação da empresa + formulário de currículo.
// Processa o POST ANTES de qualquer saída HTML (padrão PRG: grava e redireciona).
require_once __DIR__ . '/includes/banco.php';
require_once __DIR__ . '/admin/model_curriculos.php';

// Para onde vai o aviso de nova candidatura. Ajuste aqui se precisar.
const CURRICULOS_EMAIL_AVISO = 'contato@arletevieiraconfeitaria.com.br';

$erros = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot antispam: bot preenche o campo escondido; humano nunca.
    if (!empty($_POST['website'])) {
        header('Location: trabalhe-conosco.php?enviado=1');
        exit;
    }

    $dados = [];
    foreach (curriculos_campos() as $campo) {
        $dados[$campo] = trim((string) ($_POST[$campo] ?? ''));
    }

    // Validação: e-mail é o único obrigatório (espelha o Google Form).
    if ($dados['email'] === '' || !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Informe um e-mail válido.';
    }
    if ($dados['data_nascimento'] !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $dados['data_nascimento']);
        if (!$d) { $dados['data_nascimento'] = ''; }
    }

    // Upload opcional do PDF do currículo.
    $pdfSalvo = null;
    if (!empty($_FILES['curriculo_pdf']) && $_FILES['curriculo_pdf']['error'] !== UPLOAD_ERR_NO_FILE) {
        $arq = $_FILES['curriculo_pdf'];
        if ($arq['error'] !== UPLOAD_ERR_OK) {
            $erros[] = 'Falha ao enviar o arquivo. Tente novamente.';
        } elseif ($arq['size'] > 8 * 1024 * 1024) {
            $erros[] = 'O currículo em PDF deve ter no máximo 8 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($arq['tmp_name']);
            $ext   = strtolower(pathinfo($arq['name'], PATHINFO_EXTENSION));
            if ($mime !== 'application/pdf' || $ext !== 'pdf') {
                $erros[] = 'Envie o currículo apenas em formato PDF.';
            } else {
                $dir = __DIR__ . '/admin/data/curriculos';
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $nome = 'cv_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.pdf';
                if (move_uploaded_file($arq['tmp_name'], $dir . '/' . $nome)) {
                    $pdfSalvo = $nome;
                } else {
                    $erros[] = 'Não foi possível salvar o arquivo enviado.';
                }
            }
        }
    }

    if (!$erros) {
        try {
            $dados['curriculo_pdf'] = $pdfSalvo;
            $dados['origem'] = 'site';
            $dados['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
            $id = curriculos_inserir($pdo, $dados);

            // Aviso por e-mail (best-effort: não bloqueia o candidato se falhar).
            $assunto = 'Nova candidatura recebida' . ($dados['vaga_interesse'] ? ' — ' . $dados['vaga_interesse'] : '');
            $corpo  = "Chegou um novo currículo pelo site.\n\n";
            $corpo .= 'Nome: '   . ($dados['nome']  ?: '(não informado)') . "\n";
            $corpo .= 'E-mail: ' . $dados['email'] . "\n";
            $corpo .= 'Contato: '. ($dados['contato'] ?: '(não informado)') . "\n";
            $corpo .= 'Vaga: '   . ($dados['vaga_interesse'] ?: '(não informada)') . "\n";
            $corpo .= 'Cidade: ' . ($dados['bairro_cidade'] ?: '(não informada)') . "\n";
            $corpo .= 'PDF anexado: ' . ($pdfSalvo ? 'sim' : 'não') . "\n\n";
            $corpo .= 'Veja no painel: https://arletevieiraconfeitaria.com.br/admin/curriculos.php?id=' . $id . "\n";
            $headers  = 'From: Site Arlete Vieira <nao-responder@arletevieiraconfeitaria.com.br>' . "\r\n";
            $headers .= 'Reply-To: ' . $dados['email'] . "\r\n";
            $headers .= 'Content-Type: text/plain; charset=utf-8' . "\r\n";
            @mail(CURRICULOS_EMAIL_AVISO, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo, $headers);

            header('Location: trabalhe-conosco.php?enviado=1');
            exit;
        } catch (\Throwable $e) {
            $erros[] = 'Ocorreu um erro ao registrar sua candidatura. Tente novamente.';
        }
    }
}

$enviado = isset($_GET['enviado']);
$old = fn($c) => htmlspecialchars($_POST[$c] ?? '');

$page_title = 'Trabalhe Conosco | Arlete Vieira Confeitaria & Doceria';
$page_description = 'Faça parte da equipe da Arlete Vieira Confeitaria & Doceria em São José/SC. Conheça nossa cultura e envie seu currículo.';
$page_keywords = 'trabalhe conosco, vagas, emprego, currículo, confeitaria, São José, SC, Arlete Vieira';
include "includes/top.php" ?>
    <div role="main" class="main">

        <!-- HERO -->
        <section class="section section-height-3 bg-light border-0 m-0">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-9 text-center">
                        <ul class="breadcrumb d-block text-center custom-font-secondary text-6 font-weight-medium mb-3">
                            <li><a style="color:#000;" href="index.php" class="text-decoration-none opacity-hover-8">INÍCIO</a></li>
                            <li class="active text-color-primary">TRABALHE CONOSCO</li>
                        </ul>
                        <h1 class="font-weight-bold text-color-primary mb-3">Faça parte da nossa história</h1>
                        <p class="lead">Na <strong>Arlete Vieira Confeitaria</strong> transformamos ingredientes simples
                            em momentos inesquecíveis. Se você tem paixão pelo que faz e vontade de crescer com a gente,
                            adoraríamos te conhecer.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- MISSÃO / VISÃO / VALORES -->
        <section class="section bg-white border-0 py-5 m-0">
            <div class="container">
                <div class="row text-center">
                    <div class="col-md-4 mb-4 mb-md-0">
                        <div class="p-4 h-100 rounded shadow-sm bg-light">
                            <i class="fas fa-bullseye text-color-primary text-8 mb-3"></i>
                            <h3 class="font-weight-bold text-5">Missão</h3>
                            <p class="mb-0">Encantar nossos clientes com produtos artesanais de qualidade, feitos com carinho,
                                levando doçura e alegria a cada ocasião.</p>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4 mb-md-0">
                        <div class="p-4 h-100 rounded shadow-sm bg-light">
                            <i class="fas fa-eye text-color-primary text-8 mb-3"></i>
                            <h3 class="font-weight-bold text-5">Visão</h3>
                            <p class="mb-0">Ser referência em confeitaria em São José e região, reconhecida pela excelência,
                                inovação e pelo relacionamento próximo com quem escolhe a gente.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-4 h-100 rounded shadow-sm bg-light">
                            <i class="fas fa-heart text-color-primary text-8 mb-3"></i>
                            <h3 class="font-weight-bold text-5">Valores</h3>
                            <p class="mb-0">Qualidade, honestidade, trabalho em equipe, respeito ao cliente e amor
                                pela arte de confeitar em cada detalhe.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- CULTURA -->
        <section class="section bg-light border-0 py-5 m-0">
            <div class="container">
                <div class="row align-items-center">
                    <div class="col-lg-7 mb-4 mb-lg-0">
                        <h2 class="font-weight-bold text-color-primary mb-3">Nossa cultura</h2>
                        <p class="mb-2">Somos uma equipe que valoriza pessoas antes de tudo. Aqui você encontra um ambiente
                            acolhedor, onde há espaço para aprender, criar e evoluir junto com a empresa.</p>
                        <ul class="list list-icons list-icons-style-2">
                            <li><i class="fas fa-check text-color-primary"></i> Ambiente familiar e colaborativo</li>
                            <li><i class="fas fa-check text-color-primary"></i> Valorização e reconhecimento do trabalho</li>
                            <li><i class="fas fa-check text-color-primary"></i> Oportunidades de aprendizado e crescimento</li>
                            <li><i class="fas fa-check text-color-primary"></i> Paixão por confeitaria e atendimento de excelência</li>
                        </ul>
                    </div>
                    <div class="col-lg-5 text-center">
                        <img src="img/logo.png" class="img-fluid" style="max-height: 220px;" alt="Arlete Vieira Confeitaria & Doceria" />
                    </div>
                </div>
            </div>
        </section>

        <!-- FORMULÁRIO -->
        <section id="candidatura" class="section bg-white border-0 py-5 m-0">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="text-center mb-4">
                            <h2 class="font-weight-bold text-color-primary mb-2">Envie seu currículo</h2>
                            <p class="lead">Preencha os dados abaixo. Leva menos de 2 minutos.</p>
                        </div>

                        <?php if ($enviado): ?>
                            <div class="alert alert-success text-center py-4">
                                <h4 class="font-weight-bold mb-1">Candidatura recebida! 🎉</h4>
                                <p class="mb-0">Obrigado pelo interesse em fazer parte da nossa equipe.
                                    Vamos avaliar seu currículo e, se fizer sentido, entraremos em contato.</p>
                            </div>
                        <?php else: ?>
                            <?php if ($erros): ?>
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        <?php foreach ($erros as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="trabalhe-conosco.php" enctype="multipart/form-data" class="p-4 rounded shadow-sm bg-light">
                                <!-- Honeypot antispam (escondido) -->
                                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                    <label>Não preencha<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                                </div>

                                <div class="row">
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Nome completo</label>
                                        <input type="text" name="nome" class="form-control" value="<?= $old('nome') ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">E-mail <span class="text-danger">*</span></label>
                                        <input type="email" name="email" class="form-control" required value="<?= $old('email') ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Data de nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control" value="<?= $old('data_nascimento') ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Melhor contato (WhatsApp)</label>
                                        <input type="text" name="contato" class="form-control" placeholder="(48) 90000-0000" value="<?= $old('contato') ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Bairro / Cidade</label>
                                        <input type="text" name="bairro_cidade" class="form-control" value="<?= $old('bairro_cidade') ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Vaga de interesse</label>
                                        <input type="text" name="vaga_interesse" class="form-control" placeholder="Ex.: Confeiteiro(a), Atendimento..." value="<?= $old('vaga_interesse') ?>">
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Possui algum curso/especialização na área?</label>
                                        <textarea name="cursos" class="form-control" rows="2"><?= $old('cursos') ?></textarea>
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Possui experiência na área?</label>
                                        <textarea name="experiencia" class="form-control" rows="2"><?= $old('experiencia') ?></textarea>
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Algo que gostaria de acrescentar?</label>
                                        <textarea name="observacoes" class="form-control" rows="3"><?= $old('observacoes') ?></textarea>
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Currículo em PDF (opcional)</label>
                                        <input type="file" name="curriculo_pdf" accept="application/pdf" class="form-control">
                                        <small class="text-muted">Apenas PDF, até 8 MB.</small>
                                    </div>
                                </div>

                                <div class="text-center mt-2">
                                    <button type="submit" class="btn btn-primary btn-lg font-weight-semibold px-5 py-3"
                                            style="background-color:#a51d32;border-color:#a51d32;">Enviar candidatura</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

    </div>
<?php include "includes/footer.php" ?>
