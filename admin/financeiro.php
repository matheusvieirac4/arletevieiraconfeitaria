<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_financeiro.php';

$configurado = financeiro_configurado();
$cfgAtual = financeiro_config() ?: [];

$flash = null;
if (isset($_SESSION['financeiro_flash'])) {
    $flash = $_SESSION['financeiro_flash'];
    unset($_SESSION['financeiro_flash']);
}
$teste = null;
if (isset($_SESSION['financeiro_teste'])) {
    $teste = $_SESSION['financeiro_teste'];
    unset($_SESSION['financeiro_teste']);
}

// Nota em revisão (após upload do XML).
$revisao = $_SESSION['financeiro_revisao'] ?? null;

// Fila de notas recebidas automaticamente do SEFAZ.
$sefazOn     = financeiro_sefaz_configurado();
$pendentes   = financeiro_pendentes_listar();
$sefazEspera = financeiro_sefaz_espera_restante();      // segundos até liberar
$sefazUltima = financeiro_sefaz_ultima_execucao();      // timestamp da última busca

// Se há revisão, busca os cadastros para montar os campos.
$listas = ['contas' => [], 'categorias' => [], 'fornecedores' => [], 'formas' => [], 'centros' => []];
$categoriasGrupos = [];   // ['Grupo' => ['Categoria', ...]] para o <optgroup>
$fornecedoresFull = [];
$erroListas = '';
if ($revisao && $configurado) {
    try {
        $api = financeiro_api();
        $fornecedoresFull       = financeiro_extrair_lista($api->listarFornecedores());
        $listas['contas']       = financeiro_nomes($api->listarContas());
        $respCategorias         = $api->listarCategorias();
        $categoriasGrupos       = financeiro_categorias_agrupadas($respCategorias);
        // Só as folhas: os rótulos numerados são cabeçalho, não são escolhíveis.
        $listas['categorias']   = financeiro_categorias_selecionaveis($categoriasGrupos);
        $listas['fornecedores'] = financeiro_nomes($api->listarFornecedores());
        $listas['formas']       = financeiro_nomes($api->listarFormasPagamento());
        $listas['centros']      = financeiro_nomes($api->listarCentrosCusto());
    } catch (\Throwable $e) {
        $erroListas = $e->getMessage();
    }
}

$page_title = 'Financeiro';
$active = 'financeiro';
// Choices.js — o mesmo componente de select que o AdminKit usa nos exemplos de
// formulário. Dá busca e agrupamento legível na lista longa de categorias
// (o <select> nativo do celular não mostra bem os optgroups).
$extra_head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@10.2.0/public/assets/styles/choices.min.css">';
$extra_css = '
        .status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .mono { font-family: monospace; font-size: 0.85rem; }

        /* Bolinha de status ao lado do título */
        .status-dot-lg { width: 14px; height: 14px; margin-right: 0; flex: 0 0 auto; }
        .status-checando { background-color: #adb5bd; }
        .status-ok  { background-color: #28a745; animation: pulso-ok 2s ease-out infinite; }
        .status-erro { background-color: #d9534f; }
        @keyframes pulso-ok {
            0%   { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.6); }
            70%  { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .status-ok { animation: none; }
        }

        /* --- Choices.js no visual do AdminKit/Bootstrap ------------------- */
        .choices { margin-bottom: 0; }
        .choices__inner {
            min-height: calc(1.5em + 0.5rem + 2px);
            padding: 0.25rem 0.75rem;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 0.2rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: #495057;
        }
        .choices[data-type*="select-one"] .choices__inner { padding-bottom: 0.25rem; }
        .choices.is-focused .choices__inner, .choices.is-open .choices__inner {
            border-color: #a51d32;
            box-shadow: 0 0 0 0.2rem rgba(165, 29, 50, 0.15);
        }
        .choices.is-open .choices__inner { border-radius: 0.2rem 0.2rem 0 0; }
        .choices[data-type*="select-one"]::after {
            border-color: #495057 transparent transparent;
        }
        .choices__list--dropdown, .choices__list[aria-expanded] {
            border-color: #ced4da;
            border-radius: 0 0 0.2rem 0.2rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
            font-size: 0.875rem;
            z-index: 1056;   /* acima de modais do Bootstrap */
        }
        .choices__list--dropdown .choices__item--selectable.is-highlighted,
        .choices__list[aria-expanded] .choices__item--selectable.is-highlighted {
            background-color: #a51d32;
            color: #fff;
        }
        .choices__list--dropdown .choices__item, .choices__list[aria-expanded] .choices__item {
            padding: 0.4rem 0.75rem;
        }
        /* Cabeçalho do grupo (a categoria numerada) */
        .choices__group .choices__heading {
            border-bottom: 0;
            padding: 0.5rem 0.75rem 0.2rem;
            color: #a51d32;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .choices__group .choices__item { padding-left: 1.25rem; }
        .choices__input { background-color: #fff; font-size: 0.875rem; margin-bottom: 0; }
        .choices[data-type*="select-one"] .choices__input {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #eceef1;
        }
        /* Campo obrigatório não preenchido */
        .campo-invalido .choices__inner, select.campo-invalido {
            border-color: #d9534f;
            box-shadow: 0 0 0 0.2rem rgba(217, 83, 79, 0.15);
        }';
require __DIR__ . '/_header.php';

// helper local para <datalist>
$datalist = function (string $id, array $opts): string {
    $h = "<datalist id=\"$id\">";
    foreach ($opts as $o) { $h .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '"></option>'; }
    return $h . '</datalist>';
};
?>
        <div class="d-flex justify-content-between align-items-start mb-2">
            <h1 class="mb-0 d-flex align-items-center">
                Financeiro — Contas a pagar
                <?php if ($configurado): ?>
                    <span id="status-dot" class="status-dot status-dot-lg status-checando ms-3"
                          role="status" title="Verificando a conexão com o Cardápio Web..."></span>
                <?php else: ?>
                    <span class="status-dot status-dot-lg bg-warning ms-3" title="Integração ainda não configurada."></span>
                <?php endif; ?>
            </h1>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modal-credenciais" title="Credenciais da integração">
                <i data-feather="settings" class="align-middle"></i>
            </button>
        </div>
        <p class="text-muted mb-4">
            Importe notas fiscais (XML) e lance como contas a pagar no Cardápio Web,
            sem digitar uma por uma. As contas a receber continuam sendo geradas pelo sistema de pedidos.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <!-- Credenciais: só no pop-up da engrenagem -->
        <div class="modal fade" id="modal-credenciais" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Credenciais da integração</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <form method="post" action="controller_financeiro.php?acao=salvar_config">
                        <div class="modal-body">
                            <p class="text-muted small">
                                O <strong>refresh token</strong> do Cardápio Web expira a cada 5 dias — quando a bolinha ficar
                                vermelha, cole o novo aqui. Campos em branco <strong>mantêm</strong> o valor atual.
                            </p>
                            <div class="row g-3">
                                <?php foreach (financeiro_config_campos() as $k => $meta): $def = !empty($cfgAtual[$k]); ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-normal"><?= htmlspecialchars($meta['rotulo']) ?></label>
                                        <?php if ($meta['secreto']): ?>
                                            <input type="password" name="<?= $k ?>" class="form-control" autocomplete="new-password"
                                                   placeholder="<?= $def ? '•••••••• (definido)' : 'não definido' ?>">
                                            <div class="form-text"><?= $def ? '✓ já definido' : 'ainda não definido' ?></div>
                                        <?php else: ?>
                                            <input type="text" name="<?= $k ?>" class="form-control"
                                                   value="<?= htmlspecialchars((string) ($cfgAtual[$k] ?? '')) ?>" placeholder="não definido">
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Salvar credenciais</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!$configurado): ?>
            <div class="card" style="max-width: 820px;">
                <div class="card-body text-muted">
                    Integração ainda não configurada. Abra a <strong>engrenagem</strong> no canto superior direito e
                    informe pelo menos o <span class="mono">company_id</span> e o <span class="mono">refresh_token</span>
                    (da resposta do login em <span class="mono">/auth/token</span>).
                </div>
            </div>

        <?php elseif (!$revisao): ?>
            <?php if ($sefazOn || $pendentes): ?>
            <!-- Fila de NF-e recebidas automaticamente do SEFAZ -->
            <div class="card mb-4" style="max-width: 820px;">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Notas recebidas do SEFAZ <?php if ($pendentes): ?><span class="badge bg-danger"><?= count($pendentes) ?></span><?php endif; ?></span>
                    <?php if ($sefazOn): ?>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted fw-normal" id="sefaz-espera" data-restam="<?= (int) $sefazEspera ?>"></small>
                        <form method="post" action="controller_financeiro.php?acao=sefaz_puxar" class="m-0">
                            <button id="sefaz-btn" class="btn btn-outline-secondary btn-sm" type="submit"
                                    <?= $sefazEspera > 0 ? 'disabled' : '' ?>>Buscar agora</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$pendentes): ?>
                        <p class="text-muted mb-0">Nenhuma nota nova. As NF-e (modelo 55) dos seus fornecedores aparecem aqui automaticamente.</p>
                    <?php else: ?>
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Fornecedor</th><th>Valor</th><th>Emissão</th><th class="text-end">Ações</th></tr></thead>
                            <tbody>
                            <?php foreach ($pendentes as $ch => $nt): ?>
                                <tr>
                                    <td><?= htmlspecialchars($nt['fornecedor']['nome'] ?: $nt['fornecedor']['cnpj']) ?></td>
                                    <td>R$ <?= htmlspecialchars(financeiro_valor_br($nt['valor_total'])) ?></td>
                                    <td><?= htmlspecialchars($nt['emissao']) ?></td>
                                    <td class="text-end text-nowrap">
                                        <a class="btn btn-primary btn-sm" href="controller_financeiro.php?acao=pendente_revisar&chave=<?= urlencode($ch) ?>">Revisar</a>
                                        <a class="btn btn-outline-danger btn-sm" href="controller_financeiro.php?acao=pendente_descartar&chave=<?= urlencode($ch) ?>" onclick="return confirm('Descartar esta nota recebida?')">Descartar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($sefazOn && $sefazUltima > 0): ?>
                        <p class="text-muted small mb-0 mt-3">
                            Última busca: <?= htmlspecialchars(date('d/m/Y H:i', $sefazUltima)) ?>.
                            O SEFAZ permite uma consulta por hora — o cron faz isso sozinho.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($sefazOn): ?>
            <script>
            (function () {
                const el = document.getElementById('sefaz-espera');
                const btn = document.getElementById('sefaz-btn');
                if (!el || !btn) { return; }
                let restam = parseInt(el.dataset.restam || '0', 10);
                const fmt = (s) => {
                    const m = Math.floor(s / 60), r = s % 60;
                    return String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
                };
                (function tick() {
                    if (restam > 0) {
                        el.textContent = 'Liberado em ' + fmt(restam);
                        btn.disabled = true;
                        restam--;
                        setTimeout(tick, 1000);
                    } else {
                        el.textContent = 'Pronto para buscar';
                        btn.disabled = false;
                    }
                })();
            })();
            </script>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Estado 1: composer (texto + anexos: XML / foto / câmera) -->
            <div class="card" style="max-width: 820px;">
                <div class="card-header fw-semibold">Novo lançamento</div>
                <div class="card-body">
                    <div id="cp-msg"></div>
                    <input type="file" id="cp-cam-input" accept="image/*" capture="environment" class="d-none">
                    <div id="cp-chips" class="d-flex flex-wrap gap-2 mb-2"></div>
                    <textarea id="cp-text" class="form-control mb-2" rows="2"
                        placeholder="Descreva a compra (ex.: 'paguei 84,90 no pix, conta Sicredi, embalagens da SOS')&#10;ou anexe um XML / foto pelo botão +. Foto + texto juntos funcionam: o cupom dá fornecedor e valor, o texto explica a finalidade (ex.: 'é vale do Matheus')."></textarea>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex gap-2">
                            <div class="dropdown">
                                <button class="btn btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">+ Anexar</button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" id="cp-add-xml">📄 Anexar XML da nota</a></li>
                                    <li><a class="dropdown-item" href="#" id="cp-add-foto">🖼️ Anexar foto (QR ou cupom)</a></li>
                                    <li><a class="dropdown-item" href="#" id="cp-add-cam" data-bs-toggle="modal" data-bs-target="#modalCamera">📷 Escanear QR com a câmera</a></li>
                                </ul>
                            </div>
                            <button type="button" id="cp-cam-quick" class="btn btn-outline-secondary" title="Tirar foto do cupom">
                                <i class="align-middle" data-feather="camera"></i>
                            </button>
                        </div>
                        <button id="cp-enviar" class="btn btn-primary" type="button">Enviar</button>
                    </div>
                    <p class="text-muted small mb-0 mt-2">Nada é lançado sem sua confirmação — o próximo passo é sempre a tela de revisão.</p>

                    <!-- inputs e forms ocultos -->
                    <input type="file" id="cp-file-xml" accept=".xml,text/xml,application/xml" class="d-none">
                    <input type="file" id="cp-file-foto" accept="image/*" class="d-none">
                    <form id="cp-form-xml" method="post" action="controller_financeiro.php?acao=upload" enctype="multipart/form-data" class="d-none">
                        <input type="file" name="xml" id="cp-xml-submit">
                    </form>
                    <form id="cp-form-qr" method="post" action="controller_financeiro.php?acao=qr" class="d-none">
                        <input type="hidden" name="chave" id="cp-qr-chave">
                    </form>
                    <form id="cp-form-texto" method="post" action="controller_financeiro.php?acao=texto" class="d-none">
                        <input type="hidden" name="texto" id="cp-texto-submit">
                    </form>
                    <form id="cp-form-cupom" method="post" action="controller_financeiro.php?acao=cupom" enctype="multipart/form-data" class="d-none">
                        <input type="file" name="foto" id="cp-foto-submit">
                        <input type="hidden" name="texto" id="cp-cupom-texto">
                    </form>
                </div>
            </div>

            <!-- Modal câmera (QR ao vivo) -->
            <div class="modal fade" id="modalCamera" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Escanear QR Code</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                  </div>
                  <div class="modal-body text-center">
                    <video id="cp-video" playsinline style="width:100%; border-radius:8px; background:#000;"></video>
                    <p id="cp-cam-status" class="text-muted small mb-0 mt-2">Aponte a câmera para o QR Code do cupom…</p>
                  </div>
                </div>
              </div>
            </div>

            <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
            <script>
            (function () {
                const IA_ON = <?= financeiro_ia_configurada() ? 'true' : 'false' ?>;
                const chips = document.getElementById('cp-chips');
                const txt = document.getElementById('cp-text');
                function showMsg(texto, tipo) {
                    const box = document.getElementById('cp-msg');
                    box.innerHTML = '<div class="alert alert-' + (tipo || 'warning') + ' alert-dismissible">'
                        + texto.replace(/</g, '&lt;')
                        + '<button type="button" class="btn-close" onclick="this.parentNode.remove()"></button></div>';
                }
                const fileXml = document.getElementById('cp-file-xml');
                const fileFoto = document.getElementById('cp-file-foto');
                let anexoXml = null, anexoFoto = null;

                function renderChips() {
                    chips.innerHTML = '';
                    const add = (label, onRemove) => {
                        const s = document.createElement('span');
                        s.className = 'badge bg-light text-dark border';
                        s.innerHTML = label + ' <a href="#" class="text-danger text-decoration-none">✕</a>';
                        s.querySelector('a').onclick = (e) => { e.preventDefault(); onRemove(); renderChips(); };
                        chips.appendChild(s);
                    };
                    if (anexoXml) add('📄 ' + anexoXml.name, () => { anexoXml = null; fileXml.value = ''; });
                    if (anexoFoto) add('🖼️ ' + anexoFoto.name, () => { anexoFoto = null; fileFoto.value = ''; });
                }

                document.getElementById('cp-add-xml').onclick = (e) => { e.preventDefault(); fileXml.click(); };
                document.getElementById('cp-add-foto').onclick = (e) => { e.preventDefault(); fileFoto.click(); };
                fileXml.onchange = () => { anexoXml = fileXml.files[0] || null; anexoFoto = null; fileFoto.value=''; renderChips(); };
                fileFoto.onchange = () => { anexoFoto = fileFoto.files[0] || null; anexoXml = null; fileXml.value=''; renderChips(); };

                // Lê o QR de um File (imagem) via jsQR; devolve o texto ou null.
                function lerQrDaImagem(file) {
                    return new Promise((resolve) => {
                        const img = new Image();
                        img.onload = () => {
                            const cv = document.createElement('canvas');
                            const max = 1400, scale = Math.min(1, max / Math.max(img.width, img.height));
                            cv.width = Math.round(img.width * scale);
                            cv.height = Math.round(img.height * scale);
                            const ctx = cv.getContext('2d');
                            ctx.drawImage(img, 0, 0, cv.width, cv.height);
                            const data = ctx.getImageData(0, 0, cv.width, cv.height);
                            const code = window.jsQR ? window.jsQR(data.data, cv.width, cv.height) : null;
                            resolve(code ? code.data : null);
                        };
                        img.onerror = () => resolve(null);
                        img.src = URL.createObjectURL(file);
                    });
                }

                function enviarChave(texto) {
                    document.getElementById('cp-qr-chave').value = texto;
                    document.getElementById('cp-form-qr').submit();
                }
                function enviarXml(file) {
                    const dt = new DataTransfer(); dt.items.add(file);
                    document.getElementById('cp-xml-submit').files = dt.files;
                    document.getElementById('cp-form-xml').submit();
                }

                function enviarCupom(file, texto) {
                    const dt = new DataTransfer(); dt.items.add(file);
                    document.getElementById('cp-foto-submit').files = dt.files;
                    document.getElementById('cp-cupom-texto').value = texto || '';
                    document.getElementById('cp-form-cupom').submit();
                }
                function enviarTexto(texto) {
                    document.getElementById('cp-texto-submit').value = texto;
                    document.getElementById('cp-form-texto').submit();
                }

                const btnEnviar = document.getElementById('cp-enviar');
                function travar(msg) {
                    if (!btnEnviar.classList.contains('is-loading')) {
                        btnEnviar.dataset.htmlOriginal = btnEnviar.innerHTML; // salva o original só uma vez
                    }
                    btnEnviar.classList.add('is-loading');
                    btnEnviar.disabled = true;
                    btnEnviar.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (msg || 'Enviando…');
                }
                function destravar() {
                    if (btnEnviar.dataset.htmlOriginal) { btnEnviar.innerHTML = btnEnviar.dataset.htmlOriginal; }
                    btnEnviar.classList.remove('is-loading');
                    btnEnviar.disabled = false;
                }

                // Processa uma FOTO de cupom: a visão computacional vem primeiro.
                // A foto do cupom inteiro já traz fornecedor, valor, itens e forma
                // de pagamento; o QR só devolve a chave de acesso, e desde que o
                // SEFAZ passou a exigir captcha a chave quase não rende dado. Por
                // isso o QR ficou como plano B (IA não configurada ou falhou).
                // Escanear QR ao vivo continua tendo o fluxo dele, sem foto.
                async function processarFoto(file, textoExtra) {
                    const instrucao = (textoExtra || '').trim();
                    travar('Lendo cupom…');
                    if (IA_ON) {
                        showMsg(instrucao ? 'Lendo o cupom junto com a sua instrução…' : 'Lendo o cupom…', 'info');
                        enviarCupom(file, instrucao);
                        return;
                    }
                    showMsg('Procurando o QR Code na foto…', 'info');
                    const qr = await lerQrDaImagem(file);
                    if (qr) { enviarChave(qr); return; }
                    destravar();
                    showMsg('A IA não está configurada e não encontrei QR nessa foto.', 'warning');
                }

                // Botão de câmera no topo: abre a câmera direto e já processa a foto.
                const camInput = document.getElementById('cp-cam-input');
                document.getElementById('cp-cam-quick').onclick = () => camInput.click();
                camInput.onchange = () => {
                    const f = camInput.files[0];
                    if (f) { processarFoto(f, txt.value.trim()); }
                };

                btnEnviar.onclick = async () => {
                    if (anexoXml) { travar(); enviarXml(anexoXml); return; }
                    if (anexoFoto) { processarFoto(anexoFoto, txt.value.trim()); return; }
                    if (txt.value.trim()) {
                        if (IA_ON) { travar('Lendo…'); enviarTexto(txt.value.trim()); return; }
                        showMsg('A leitura de texto precisa da IA (Gemini) configurada.', 'warning');
                        return;
                    }
                    showMsg('Anexe um XML, uma foto (QR) ou escreva a compra no campo de texto.', 'info');
                };

                // ----- Câmera ao vivo (QR) -----
                const modalEl = document.getElementById('modalCamera');
                const video = document.getElementById('cp-video');
                const status = document.getElementById('cp-cam-status');
                let stream = null, raf = null, scanCanvas = document.createElement('canvas');

                function pararCamera() {
                    if (raf) cancelAnimationFrame(raf);
                    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
                }
                function loopScan() {
                    if (!stream) return;
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                        scanCanvas.width = video.videoWidth; scanCanvas.height = video.videoHeight;
                        const ctx = scanCanvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
                        const data = ctx.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
                        const code = window.jsQR ? window.jsQR(data.data, scanCanvas.width, scanCanvas.height) : null;
                        if (code && code.data) {
                            status.textContent = 'QR lido! Processando…';
                            pararCamera();
                            enviarChave(code.data);
                            return;
                        }
                    }
                    raf = requestAnimationFrame(loopScan);
                }
                modalEl.addEventListener('shown.bs.modal', async () => {
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        video.srcObject = stream; await video.play();
                        status.textContent = 'Aponte a câmera para o QR Code do cupom…';
                        loopScan();
                    } catch (e) {
                        status.textContent = 'Não foi possível acessar a câmera (' + e.name + '). Use HTTPS e permita o acesso.';
                    }
                });
                modalEl.addEventListener('hidden.bs.modal', pararCamera);
            })();
            </script>

        <?php else: ?>
            <!-- Estado 2: revisão antes de enviar -->
            <?php
            $l = $revisao['lancamento'];
            $forcarEnvio = !empty($_SESSION['financeiro_forcar']);
            unset($_SESSION['financeiro_forcar']);
            $cnpjForn = $revisao['fornecedor']['cnpj'] ?? '';
            $regra = financeiro_regra_buscar($cnpjForn);
            $regraAplicada = false;

            // 1) Classificação de gestão (conta/categoria/centro) — aprendida por fornecedor.
            //    Forma de pagamento NÃO entra aqui: vem sempre da nota (tPag).
            if ($regra) {
                foreach (['account', 'category', 'cost_center'] as $campo) {
                    if (!empty($regra[$campo])) {
                        $l[$campo] = $regra[$campo];
                        $regraAplicada = true;
                    }
                }
            }

            // 2) Fornecedor: reusa o cadastro certo em vez de duplicar a razão social.
            //    Prioridade: regra aprendida > casamento por CNPJ/semelhança > razão social.
            $fornMatch = 'nenhum';
            if ($regra && !empty($regra['supplier'])) {
                $l['supplier'] = $regra['supplier'];
                $fornMatch = 'regra';
            } elseif (!empty($fornecedoresFull)) {
                $casa = financeiro_casar_fornecedor($cnpjForn, $revisao['fornecedor']['nome'] ?? '', $fornecedoresFull);
                if ($casa['match'] !== 'nenhum' && $casa['name'] !== '') {
                    $l['supplier'] = $casa['name'];
                    $fornMatch = $casa['match'];
                }
            }

            // Documento do cadastro casado: procura o fornecedor final na lista e
            // lê o document que ele já tem no Cardápio Web. Serve para PUXAR o
            // CNPJ/CPF quando existe e para saber quando ele está em branco.
            $fornCasado = ($fornMatch !== 'nenhum');
            $fornDocExistente = '';
            if ($fornCasado && !empty($fornecedoresFull)) {
                $alvoNome = financeiro_normalizar_nome($l['supplier']);
                foreach ($fornecedoresFull as $f) {
                    if (financeiro_normalizar_nome((string) ($f['name'] ?? '')) === $alvoNome) {
                        $fornDocExistente = preg_replace('/\D/', '', (string) ($f['document'] ?? ''));
                        break;
                    }
                }
            }
            ?>
            <div class="card" style="max-width: 820px;">
                <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                    <span>Revisar lançamento<?= $revisao['numero'] !== '' ? ' — nº ' . htmlspecialchars($revisao['numero']) : ' — ' . htmlspecialchars($revisao['natureza_operacao']) ?></span>
                    <span class="badge bg-light text-dark">Fornecedor: <?= htmlspecialchars($revisao['fornecedor']['nome']) ?></span>
                </div>
                <div class="card-body">
                    <?php if ($erroListas): ?>
                        <div class="alert alert-warning">Não foi possível carregar seus cadastros (<span class="mono"><?= htmlspecialchars($erroListas) ?></span>). Você ainda pode digitar os campos manualmente.</div>
                    <?php endif; ?>
                    <?php foreach ($revisao['avisos'] as $aviso): ?>
                        <div class="alert alert-warning py-2"><?= htmlspecialchars($aviso) ?></div>
                    <?php endforeach; ?>
                    <?php if ($regraAplicada): ?>
                        <div class="alert alert-info py-2">✓ Classificação preenchida automaticamente com base em compras anteriores deste fornecedor. Confira e ajuste se necessário.</div>
                    <?php endif; ?>

                    <form method="post" action="controller_financeiro.php?acao=importar">
                        <input type="hidden" name="forcar" value="<?= $forcarEnvio ? '1' : '0' ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Conta <span class="text-danger">*</span></label>
                                <select name="account" class="form-select js-choice" data-placeholder="Selecione a conta" data-obrigatorio="1">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($listas['contas'] as $c): ?>
                                        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= ($l['account'] === $c ? 'selected' : '') ?>><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Obrigatório e precisa já existir no Cardápio Web.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Valor (R$)</label>
                                <div class="input-group">
                                    <span class="input-group-text">R$</span>
                                    <input type="text" name="value" class="form-control" value="<?= $revisao['valor_total'] !== '' ? htmlspecialchars(financeiro_valor_br($revisao['valor_total'])) : '' ?>" placeholder="0,00" required>
                                </div>
                                <div class="form-text">Despesa (conta a pagar). Use vírgula para os centavos.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Forma de pagamento</label>
                                <input type="text" name="payment_method" class="form-control" list="dl-formas" value="<?= htmlspecialchars($l['payment_method']) ?>">
                            </div>

                            <?php
                            // Categoria e centro de custo são SEMPRE escolhidos entre os já
                            // cadastrados — os cadastros do Cardápio Web já são abrangentes e
                            // criar nomes novos só gera duplicidade. Se a sugestão da IA/regra
                            // não bater com nenhum existente, ela é descartada e avisamos.
                            $catSugerida  = trim((string) $l['category']);
                            $ccSugerido   = trim((string) $l['cost_center']);
                            $catExiste    = $catSugerida !== '' && in_array($catSugerida, $listas['categorias'], true);
                            $ccExiste     = $ccSugerido !== '' && in_array($ccSugerido, $listas['centros'], true);
                            $temCadastros = !$erroListas && $listas['categorias'];
                            ?>
                            <div class="col-md-6">
                                <label class="form-label">Categoria</label>
                                <?php if ($temCadastros): ?>
                                    <select name="category" class="form-select js-choice" data-placeholder="Busque a categoria">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($categoriasGrupos as $grupo => $itens): ?>
                                            <optgroup label="<?= htmlspecialchars($grupo, ENT_QUOTES) ?>">
                                                <?php foreach ($itens as $c): ?>
                                                    <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= ($catExiste && $catSugerida === $c ? 'selected' : '') ?>><?= htmlspecialchars($c) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        <?php if ($catSugerida !== '' && !$catExiste): ?>
                                            <span class="text-danger">A sugestão “<?= htmlspecialchars($catSugerida) ?>” não existe no cadastro. Escolha a categoria equivalente.</span>
                                        <?php else: ?>
                                            Agrupadas por subcategoria. Use sempre uma já cadastrada.
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <input type="text" name="category" class="form-control" list="dl-categorias" value="<?= htmlspecialchars($catSugerida) ?>">
                                    <div class="form-text">Cadastros indisponíveis — digite manualmente.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Centro de custo</label>
                                <?php if (!$erroListas && $listas['centros']): ?>
                                    <select name="cost_center" class="form-select js-choice" data-placeholder="Busque o centro de custo">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($listas['centros'] as $c): ?>
                                            <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= ($ccExiste && $ccSugerido === $c ? 'selected' : '') ?>><?= htmlspecialchars($c) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        <?php if ($ccSugerido !== '' && !$ccExiste): ?>
                                            <span class="text-danger">A sugestão “<?= htmlspecialchars($ccSugerido) ?>” não existe no cadastro. Escolha o centro equivalente.</span>
                                        <?php else: ?>
                                            Use sempre um já cadastrado.
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <input type="text" name="cost_center" class="form-control" list="dl-centros" value="<?= htmlspecialchars($ccSugerido) ?>">
                                <?php endif; ?>
                            </div>

                            <div class="col-md-6">
                                <?php
                                // Fornecedor vira select (igual categoria/centro). Como PODE ser
                                // um fornecedor NOVO, o nome sugerido (da nota/IA) entra como uma
                                // opção própria no grupo "Novo" quando não existe no cadastro —
                                // assim não se perde a criação de fornecedor.
                                $fornAtual = (string) $l['supplier'];
                                $fornNovo  = $fornAtual !== '' && !in_array($fornAtual, $listas['fornecedores'], true);
                                ?>
                                <label class="form-label">Fornecedor</label>
                                <?php if (!$erroListas && $listas['fornecedores']): ?>
                                    <select name="supplier" class="form-select js-choice" data-placeholder="Busque o fornecedor">
                                        <option value=""></option>
                                        <?php if ($fornNovo): ?>
                                            <optgroup label="Novo (será cadastrado)">
                                                <option value="<?= htmlspecialchars($fornAtual, ENT_QUOTES) ?>" selected><?= htmlspecialchars($fornAtual) ?></option>
                                            </optgroup>
                                        <?php endif; ?>
                                        <optgroup label="Cadastrados">
                                            <?php foreach ($listas['fornecedores'] as $fn): ?>
                                                <option value="<?= htmlspecialchars($fn, ENT_QUOTES) ?>" <?= (!$fornNovo && $fn === $fornAtual ? 'selected' : '') ?>><?= htmlspecialchars($fn) ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="supplier" class="form-control" list="dl-fornecedores" value="<?= htmlspecialchars($fornAtual) ?>">
                                <?php endif; ?>
                                <div class="form-text">
                                    <?php if ($fornMatch === 'cnpj' || $fornMatch === 'regra'): ?>
                                        ✓ Já cadastrado (encontrado pelo documento).
                                    <?php elseif ($fornMatch === 'nome'): ?>
                                        ✓ Correspondência por semelhança com um fornecedor existente — confira se é o mesmo.
                                    <?php else: ?>
                                        Fornecedor novo (não encontrado no cadastro). Da nota: <span class="mono"><?= htmlspecialchars($revisao['fornecedor']['nome']) ?></span>.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <?php
                                // O campo só trava quando o fornecedor casado JÁ TEM documento
                                // (nada a fazer). Casado sem documento fica editável: se o
                                // usuário preencher, o doc é adicionado ao cadastro existente
                                // (PUT), não duplica. Fornecedor novo também é editável.
                                $docNota   = preg_replace('/\D/', '', (string) ($revisao['fornecedor']['cnpj'] ?? ''));
                                $casadoComDoc = $fornCasado && $fornDocExistente !== '';
                                $docCampo  = $casadoComDoc ? $fornDocExistente : $docNota;
                                $ehCpf     = strlen($docCampo) === 11;
                                ?>
                                <label class="form-label">CNPJ / CPF do fornecedor</label>
                                <input type="text" name="supplier_cnpj" id="cp-doc" inputmode="numeric"
                                       class="form-control<?= $casadoComDoc ? ' bg-light' : '' ?>"
                                       value="<?= htmlspecialchars($docCampo) ?>"
                                       placeholder="<?= $casadoComDoc ? '' : 'CNPJ ou CPF' ?>"
                                       <?= $casadoComDoc ? 'readonly' : '' ?>>
                                <div class="form-text">
                                    <?php if ($casadoComDoc): ?>
                                        ✓ Fornecedor já cadastrado com este documento (<?= $ehCpf ? 'CPF / pessoa' : 'CNPJ / empresa' ?>).
                                    <?php elseif ($fornCasado): ?>
                                        Fornecedor existente <strong>sem CNPJ cadastrado</strong>. Preencha para completar o cadastro (ou deixe em branco).
                                    <?php elseif ($ehCpf): ?>
                                        CPF (11 dígitos) — será cadastrado como <strong>pessoa</strong>.
                                    <?php else: ?>
                                        Fornecedor novo é cadastrado com este documento (casamento futuro exato).
                                        <strong>CNPJ</strong> para empresa, <strong>CPF</strong> para pessoa — ex.: vale de colaborador.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Descrição</label>
                                <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($l['description']) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Vencimento</label>
                                <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($l['due_date']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Competência</label>
                                <input type="date" name="competence_date" class="form-control" value="<?= htmlspecialchars($l['competence_date']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data de pagamento</label>
                                <input type="date" name="settlement_date" class="form-control" value="<?= htmlspecialchars($l['settlement_date']) ?>">
                                <div class="form-text">Deixe vazio se ainda não foi paga.</div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Observação</label>
                                <input type="text" name="observation" class="form-control" value="<?= htmlspecialchars($l['observation']) ?>">
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-success">Enviar ao Cardápio Web</button>
                            <a href="controller_financeiro.php?acao=cancelar" class="btn btn-outline-secondary">Cancelar</a>
                        </div>
                    </form>

                    <?= $datalist('dl-categorias', $listas['categorias']) ?>
                    <?= $datalist('dl-centros', $listas['centros']) ?>
                    <?= $datalist('dl-fornecedores', $listas['fornecedores']) ?>
                    <?= $datalist('dl-formas', $listas['formas']) ?>

                    <details class="mt-3">
                        <summary class="text-muted small">Ver dados da nota (itens, chave)</summary>
                        <div class="small text-muted mt-2">
                            Chave: <span class="mono"><?= htmlspecialchars($revisao['chave']) ?></span><br>
                            Emissão: <?= htmlspecialchars($revisao['emissao']) ?> · Natureza: <?= htmlspecialchars($revisao['natureza_operacao']) ?><br>
                            Itens:
                            <ul class="mb-0">
                                <?php foreach ($revisao['itens'] as $it): ?>
                                    <li><?= htmlspecialchars($it['descricao']) ?> — <?= htmlspecialchars($it['quantidade']) ?> × R$ <?= htmlspecialchars($it['valor']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </details>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Histórico: os últimos 100 envios (o mesmo log usado na checagem de
        // duplicidade). Mais recentes primeiro.
        $historico = array_reverse(financeiro_enviados_listar());
        ?>
        <div class="card mt-4" style="max-width: 820px;">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span>Histórico de lançamentos</span>
                <small class="text-muted fw-normal"><?= count($historico) ?> de <?= FINANCEIRO_ENVIADOS_MAX ?></small>
            </div>
            <div class="card-body">
                <?php if (!$historico): ?>
                    <p class="text-muted mb-0">Nenhum lançamento enviado ainda.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Enviado em</th>
                                    <th>Fornecedor</th>
                                    <th>Categoria</th>
                                    <th>Vencimento</th>
                                    <th class="text-end">Valor</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($historico as $h): ?>
                                <tr>
                                    <td class="text-nowrap"><?= htmlspecialchars(!empty($h['ts']) ? date('d/m/Y H:i', (int) $h['ts']) : '—') ?></td>
                                    <td>
                                        <?= htmlspecialchars($h['fornecedor'] ?? '—') ?>
                                        <?php if (!empty($h['descricao'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($h['descricao']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($h['categoria'] ?? '') ?: '<span class="text-muted">—</span>' ?>
                                        <?php if (!empty($h['centro'])): ?>
                                            <div class="text-muted small"><?= htmlspecialchars($h['centro']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?= htmlspecialchars(financeiro_data_br($h['data'] ?? '')) ?></td>
                                    <td class="text-end text-nowrap">R$ <?= htmlspecialchars(financeiro_valor_br($h['valor'] ?? '0')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted small mb-0 mt-3">
                        Registrado por esta integração. Lançamentos feitos direto no Cardápio Web não aparecem aqui.
                    </p>
                <?php endif; ?>
            </div>
        </div>

<script>
// Testa a conexão sozinho ao abrir a página e reflete o resultado na bolinha.
// Verde pulsando = token ok; vermelho = falhou (normalmente o refresh token
// venceu — é só abrir a engrenagem e colar o novo).
(function () {
    const dot = document.getElementById('status-dot');
    if (!dot) { return; }
    fetch('controller_financeiro.php?acao=status', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            dot.classList.remove('status-checando');
            if (d && d.ok) {
                dot.classList.add('status-ok');
                dot.title = 'Conectado ao Cardápio Web — ' + d.contas + ' contas, ' + d.categorias +
                            ' categorias, ' + d.fornecedores + ' fornecedores, ' + d.formas +
                            ' formas de pagamento, ' + d.centros + ' centros de custo.';
            } else {
                dot.classList.add('status-erro');
                dot.title = 'Falha na conexão: ' + ((d && d.erro) || 'erro desconhecido') +
                            ' — abra a engrenagem e atualize as credenciais.';
            }
        })
        .catch(function (e) {
            dot.classList.remove('status-checando');
            dot.classList.add('status-erro');
            dot.title = 'Não consegui verificar a conexão: ' + e;
        });
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/choices.js@10.2.0/public/assets/scripts/choices.min.js"></script>
<script>
// Selects bonitos (conta / categoria / centro de custo). Se o CDN cair, o
// <select> nativo continua funcionando normalmente — nada quebra.
// O Choices esconde o <select> original ([hidden]); um "required" nele faria o
// Chrome barrar o envio sem mostrar mensagem nenhuma (controle não focável).
// Por isso a obrigatoriedade da Conta é validada aqui, com aviso visível.
// Este listener é registrado ANTES do handler de loading do rodapé, então roda
// primeiro e consegue abortar o envio antes do spinner travar o botão.
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || !form.querySelector) { return; }
    let falhou = null;
    form.querySelectorAll('select[data-obrigatorio="1"]').forEach(function (sel) {
        const vazio = !sel.value;
        const alvo = sel.closest('.choices') || sel;
        alvo.classList.toggle('campo-invalido', vazio);
        if (vazio && !falhou) { falhou = alvo; }
    });
    if (falhou) {
        e.preventDefault();
        e.stopImmediatePropagation();   // não deixa o botão virar spinner à toa
        falhou.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
}, true);

if (window.Choices) {
    document.querySelectorAll('select.js-choice').forEach(function (el) {
        new Choices(el, {
            searchEnabled: true,
            searchPlaceholderValue: el.dataset.placeholder || 'Buscar...',
            shouldSort: false,          // preserva a ordem/agrupamento do Cardápio Web
            itemSelectText: '',         // sem o "Press to select"
            allowHTML: false,
            searchResultLimit: 50,
            noResultsText: 'Nada encontrado',
            noChoicesText: 'Nenhuma opção cadastrada',
        });
    });
}

// Máscara de CNPJ/CPF (só visual — o servidor guarda apenas os dígitos).
// Até 11 dígitos formata como CPF; acima, como CNPJ.
(function () {
    const campo = document.getElementById('cp-doc');
    if (!campo) { return; }
    function mascara(v) {
        const d = (v || '').replace(/\D/g, '').slice(0, 14);
        if (d.length <= 11) {
            let o = d.slice(0, 3);
            if (d.length > 3) { o += '.' + d.slice(3, 6); }
            if (d.length > 6) { o += '.' + d.slice(6, 9); }
            if (d.length > 9) { o += '-' + d.slice(9, 11); }
            return o;
        }
        let o = d.slice(0, 2) + '.' + d.slice(2, 5) + '.' + d.slice(5, 8) + '/' + d.slice(8, 12);
        if (d.length > 12) { o += '-' + d.slice(12, 14); }
        return o;
    }
    campo.value = mascara(campo.value);
    campo.addEventListener('input', function () {
        const pos = campo.selectionStart === campo.value.length;
        campo.value = mascara(campo.value);
        if (pos) { campo.setSelectionRange(campo.value.length, campo.value.length); }
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
