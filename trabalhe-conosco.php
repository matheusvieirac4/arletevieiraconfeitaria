<?php
// Landing "Trabalhe Conosco": apresentação da empresa + formulário de currículo.
// Processa o POST ANTES de qualquer saída HTML (padrão PRG: grava e redireciona).
require_once __DIR__ . '/includes/banco.php';
require_once __DIR__ . '/admin/model_curriculos.php';
require_once __DIR__ . '/admin/model_financeiro.php';   // reusa a config/chave do Gemini

// Para onde vai o aviso de nova candidatura. Ajuste aqui se precisar.
const CURRICULOS_EMAIL_AVISO = 'contato@arletevieiraconfeitaria.com.br';

$erros = [];
// Qual aba enviou: 'pdf' (currículo em PDF + IA) ou 'manual' (formulário).
$modo = ($_POST['modo'] ?? '') === 'pdf' ? 'pdf' : 'manual';

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

    $pdfSalvo = null;

    if ($modo === 'pdf') {
        // ---- Envio por PDF: arquivo obrigatório; o Gemini lê e preenche. ----
        if (empty($_FILES['curriculo_pdf']) || $_FILES['curriculo_pdf']['error'] === UPLOAD_ERR_NO_FILE) {
            $erros[] = 'Anexe o seu currículo em PDF.';
        } else {
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
                    // Lê o PDF com a IA ANTES de mover o arquivo (usa o tmp).
                    if (financeiro_ia_configurada()) {
                        try {
                            $b64 = base64_encode((string) file_get_contents($arq['tmp_name']));
                            $ext_ia = financeiro_gemini()->extrairCurriculo($b64);
                            foreach (curriculos_campos() as $campo) {
                                $v = trim((string) ($ext_ia[$campo] ?? ''));
                                // O que o candidato digitou (ex.: e-mail) tem prioridade;
                                // a IA só preenche o que ficou em branco.
                                if ($v !== '' && ($dados[$campo] ?? '') === '') {
                                    $dados[$campo] = $v;
                                }
                            }
                        } catch (\Throwable $e) {
                            // IA falhou: segue salvando o PDF; o RH lê manualmente.
                        }
                    }
                    if ($dados['data_nascimento'] !== '') {
                        $d = DateTime::createFromFormat('Y-m-d', $dados['data_nascimento']);
                        if (!$d) { $dados['data_nascimento'] = ''; }
                    }
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
        // No envio por PDF o e-mail pode vir do formulário OU do próprio currículo.
        if (!$erros && ($dados['email'] === '' || !filter_var($dados['email'], FILTER_VALIDATE_EMAIL))) {
            $erros[] = 'Não encontramos um e-mail no currículo. Informe seu e-mail para concluir.';
        }
    } else {
        // ---- Envio pelo formulário: sem anexo novo, e-mail obrigatório. ----
        if ($dados['email'] === '' || !filter_var($dados['email'], FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'Informe um e-mail válido.';
        }
        if ($dados['data_nascimento'] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $dados['data_nascimento']);
            if (!$d) { $dados['data_nascimento'] = ''; }
        }
        // Se veio de um PDF já enviado (fluxo de revisão), reancora o arquivo.
        $ref = basename((string) ($_POST['curriculo_pdf_ref'] ?? ''));
        if ($ref !== '' && preg_match('/^cv_[\w]+\.pdf$/', $ref)
            && is_file(__DIR__ . '/admin/data/curriculos/' . $ref)) {
            $pdfSalvo = $ref;
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
                            <p class="lead">Escolha como prefere se candidatar.</p>
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

                            <div id="cv-wrap" style="position:relative;">
                            <!-- Overlay de loading (igual ao "novo lançamento" do Financeiro) -->
                            <div id="cv-overlay" class="d-none" style="position:absolute; inset:0; background:rgba(255,255,255,.88); z-index:10; display:flex; align-items:center; justify-content:center; border-radius:.5rem;">
                                <div class="text-center">
                                    <div class="spinner-border mb-2" style="color:#a51d32;" role="status"></div>
                                    <div id="cv-overlay-msg" class="fw-semibold text-muted">Lendo seu currículo…</div>
                                </div>
                            </div>

                            <!-- Escolha do modo de envio -->
                            <div id="cv-opcoes" class="row g-3 mb-2">
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-outline-primary w-100 h-100 py-4 js-cv-modo" data-alvo="cv-form-pdf"
                                            style="border-color:#a51d32;color:#a51d32;">
                                        <i class="fas fa-file-pdf text-6 d-block mb-2"></i>
                                        <span class="font-weight-bold d-block text-4">Enviar currículo (PDF)</span>
                                        <small class="text-muted">Anexe seu PDF e a gente já preenche seus dados pra você.</small>
                                    </button>
                                </div>
                                <div class="col-md-6">
                                    <button type="button" class="btn btn-outline-primary w-100 h-100 py-4 js-cv-modo" data-alvo="cv-form-manual"
                                            style="border-color:#a51d32;color:#a51d32;">
                                        <i class="fas fa-keyboard text-6 d-block mb-2"></i>
                                        <span class="font-weight-bold d-block text-4">Preencher formulário</span>
                                        <small class="text-muted">Responda algumas perguntas rápidas. Leva menos de 2 minutos.</small>
                                    </button>
                                </div>
                            </div>

                            <!-- FORM: envio por PDF (IA preenche os dados) -->
                            <form id="cv-form-pdf" method="post" action="trabalhe-conosco.php" enctype="multipart/form-data"
                                  class="p-4 rounded shadow-sm bg-light cv-form" style="display:none;">
                                <input type="hidden" name="modo" value="pdf">
                                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                    <label>Não preencha<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4 class="font-weight-bold mb-0">Enviar currículo em PDF</h4>
                                    <button type="button" class="btn btn-sm btn-link text-muted js-cv-voltar">&larr; Trocar opção</button>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-label">Currículo em PDF <span class="text-danger">*</span></label>
                                    <input type="file" name="curriculo_pdf" accept="application/pdf" class="form-control" required>
                                    <small class="text-muted">Apenas PDF, até 8 MB. Vamos ler o arquivo e preencher seus dados para você revisar.</small>
                                </div>
                                <div class="form-group mb-3">
                                    <label class="form-label">Seu e-mail</label>
                                    <input type="email" name="email" class="form-control" placeholder="Deixe em branco se já estiver no currículo" value="<?= $modo === 'pdf' ? $old('email') : '' ?>">
                                    <small class="text-muted">Só precisamos caso ele não esteja no PDF.</small>
                                </div>
                                <div class="text-center mt-2">
                                    <button type="submit" class="btn btn-primary btn-lg font-weight-semibold px-5 py-3"
                                            style="background-color:#a51d32;border-color:#a51d32;">Ler currículo e revisar</button>
                                </div>
                            </form>

                            <!-- FORM: preenchimento manual -->
                            <form id="cv-form-manual" method="post" action="trabalhe-conosco.php"
                                  class="p-4 rounded shadow-sm bg-light cv-form" style="display:none;">
                                <input type="hidden" name="modo" value="manual">
                                <input type="hidden" name="curriculo_pdf_ref" value="<?= $modo === 'manual' ? htmlspecialchars($_POST['curriculo_pdf_ref'] ?? '') : '' ?>">
                                <div style="position:absolute;left:-9999px;" aria-hidden="true">
                                    <label>Não preencha<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4 class="font-weight-bold mb-0">Preencha seus dados</h4>
                                    <button type="button" class="btn btn-sm btn-link text-muted js-cv-voltar">&larr; Trocar opção</button>
                                </div>
                                <div id="cv-review-aviso" class="alert alert-info d-none">
                                    <i class="fas fa-robot me-1"></i> Preenchemos os campos com base no seu currículo.
                                    <strong>Revise</strong> e ajuste o que precisar antes de enviar.
                                </div>
                                <div class="row">
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Nome completo</label>
                                        <input type="text" name="nome" class="form-control" value="<?= $modo === 'manual' ? $old('nome') : '' ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">E-mail <span class="text-danger">*</span></label>
                                        <input type="email" name="email" class="form-control" value="<?= $modo === 'manual' ? $old('email') : '' ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Data de nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control" value="<?= $modo === 'manual' ? $old('data_nascimento') : '' ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Melhor contato (WhatsApp)</label>
                                        <input type="text" name="contato" class="form-control" placeholder="(48) 90000-0000" value="<?= $modo === 'manual' ? $old('contato') : '' ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Bairro / Cidade</label>
                                        <input type="text" name="bairro_cidade" class="form-control" value="<?= $modo === 'manual' ? $old('bairro_cidade') : '' ?>">
                                    </div>
                                    <div class="form-group col-md-6 mb-3">
                                        <label class="form-label">Vaga de interesse</label>
                                        <input type="text" name="vaga_interesse" class="form-control" placeholder="Ex.: Confeiteiro(a), Atendimento..." value="<?= $modo === 'manual' ? $old('vaga_interesse') : '' ?>">
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Possui algum curso/especialização na área?</label>
                                        <textarea name="cursos" class="form-control" rows="2"><?= $modo === 'manual' ? $old('cursos') : '' ?></textarea>
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Possui experiência na área?</label>
                                        <textarea name="experiencia" class="form-control" rows="2"><?= $modo === 'manual' ? $old('experiencia') : '' ?></textarea>
                                    </div>
                                    <div class="form-group col-12 mb-3">
                                        <label class="form-label">Algo que gostaria de acrescentar?</label>
                                        <textarea name="observacoes" class="form-control" rows="3"><?= $modo === 'manual' ? $old('observacoes') : '' ?></textarea>
                                    </div>
                                </div>
                                <div class="text-center mt-2">
                                    <button type="submit" class="btn btn-primary btn-lg font-weight-semibold px-5 py-3"
                                            style="background-color:#a51d32;border-color:#a51d32;">Enviar candidatura</button>
                                </div>
                            </form>

                            </div><!-- /#cv-wrap -->

                            <script>
                            (function () {
                                var opcoes  = document.getElementById('cv-opcoes');
                                var overlay = document.getElementById('cv-overlay');
                                var overMsg = document.getElementById('cv-overlay-msg');
                                var formPdf = document.getElementById('cv-form-pdf');
                                var formMan = document.getElementById('cv-form-manual');
                                var aviso   = document.getElementById('cv-review-aviso');

                                function travar(t) { if (overMsg) { overMsg.textContent = t; } overlay.classList.remove('d-none'); }
                                function destravar() { overlay.classList.add('d-none'); }
                                function abrir(id) {
                                    document.querySelectorAll('.cv-form').forEach(function (f) { f.style.display = 'none'; });
                                    var alvo = document.getElementById(id);
                                    if (!alvo) { return; }
                                    if (opcoes) { opcoes.style.display = 'none'; }
                                    alvo.style.display = 'block';
                                }
                                function voltar() {
                                    document.querySelectorAll('.cv-form').forEach(function (f) { f.style.display = 'none'; });
                                    if (aviso) { aviso.classList.add('d-none'); }
                                    if (opcoes) { opcoes.style.display = ''; }
                                }
                                document.querySelectorAll('.js-cv-modo').forEach(function (b) {
                                    b.addEventListener('click', function () { abrir(b.dataset.alvo); });
                                });
                                document.querySelectorAll('.js-cv-voltar').forEach(function (b) {
                                    b.addEventListener('click', voltar);
                                });

                                // Envio por PDF: NÃO grava direto. Manda para a IA ler, preenche o
                                // formulário de revisão e mostra para o candidato conferir/enviar.
                                if (formPdf) {
                                    formPdf.addEventListener('submit', function (e) {
                                        e.preventDefault();
                                        var file = formPdf.querySelector('input[type=file]');
                                        if (!file || !file.files.length) { return; }
                                        travar('Lendo seu currículo…');
                                        fetch('curriculo_extrair.php', { method: 'POST', body: new FormData(formPdf) })
                                            .then(function (r) { return r.json(); })
                                            .then(function (res) {
                                                if (!res || !res.ok) {
                                                    destravar();
                                                    alert((res && res.error) || 'Não foi possível ler o PDF. Tente novamente ou preencha o formulário.');
                                                    return;
                                                }
                                                var d = res.dados || {};
                                                Object.keys(d).forEach(function (k) {
                                                    var el = formMan.querySelector('[name="' + k + '"]');
                                                    if (el && d[k]) { el.value = d[k]; }
                                                });
                                                // E-mail digitado na tela do PDF entra se o currículo não trouxe um.
                                                var emailPdf = (formPdf.querySelector('[name=email]') || {}).value || '';
                                                var emailMan = formMan.querySelector('[name=email]');
                                                if (emailPdf.trim() && emailMan && !emailMan.value.trim()) { emailMan.value = emailPdf.trim(); }
                                                var ref = formMan.querySelector('[name=curriculo_pdf_ref]');
                                                if (ref) { ref.value = res.pdf || ''; }
                                                destravar();
                                                abrir('cv-form-manual');
                                                if (aviso) {
                                                    aviso.classList.remove('d-none');
                                                    if (!res.ia) {
                                                        aviso.innerHTML = '<i class="fas fa-info-circle me-1"></i> Recebemos seu currículo em PDF. Confira e complete os campos abaixo antes de enviar.';
                                                    }
                                                }
                                            })
                                            .catch(function () { destravar(); alert('Falha de conexão. Tente novamente.'); });
                                    });
                                }

                                // Evita envio duplicado do formulário final (o clique duplo do usuário).
                                if (formMan) {
                                    formMan.addEventListener('submit', function (e) {
                                        if (formMan.dataset.enviando === '1') { e.preventDefault(); return; }
                                        formMan.dataset.enviando = '1';
                                        var btn = formMan.querySelector('button[type=submit]');
                                        if (btn) { btn.disabled = true; }
                                        travar('Enviando sua candidatura…');
                                    });
                                }

                                <?php if ($erros): ?>
                                abrir('cv-form-<?= $modo ?>');
                                <?php endif; ?>
                            })();
                            </script>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

    </div>
<?php include "includes/footer.php" ?>
