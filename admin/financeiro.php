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
$fornecedoresFull = [];
$erroListas = '';
if ($revisao && $configurado) {
    try {
        $api = financeiro_api();
        $fornecedoresFull       = financeiro_extrair_lista($api->listarFornecedores());
        $listas['contas']       = financeiro_nomes($api->listarContas());
        $listas['categorias']   = financeiro_nomes($api->listarCategorias());
        $listas['fornecedores'] = financeiro_nomes($api->listarFornecedores());
        $listas['formas']       = financeiro_nomes($api->listarFormasPagamento());
        $listas['centros']      = financeiro_nomes($api->listarCentrosCusto());
    } catch (\Throwable $e) {
        $erroListas = $e->getMessage();
    }
}

$page_title = 'Financeiro';
$active = 'financeiro';
$extra_css = '
        .status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
        .mono { font-family: monospace; font-size: 0.85rem; }';
require __DIR__ . '/_header.php';

// helper local para <datalist>
$datalist = function (string $id, array $opts): string {
    $h = "<datalist id=\"$id\">";
    foreach ($opts as $o) { $h .= '<option value="' . htmlspecialchars($o, ENT_QUOTES) . '"></option>'; }
    return $h . '</datalist>';
};
?>
        <h1 class="mb-2">Financeiro — Contas a pagar</h1>
        <p class="text-muted mb-4">
            Importe notas fiscais (XML) e lance como contas a pagar no Cardápio Web,
            sem digitar uma por uma. As contas a receber continuam sendo geradas pelo sistema de pedidos.
        </p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= htmlspecialchars($flash['tipo']) ?>"><?= htmlspecialchars($flash['texto']) ?></div>
        <?php endif; ?>

        <!-- Status da integração -->
        <div class="card mb-4" style="max-width: 820px;">
            <div class="card-header fw-semibold">Status da integração</div>
            <div class="card-body">
                <?php if ($configurado): ?>
                    <p class="mb-3"><span class="status-dot bg-success"></span> Configurado.</p>
                    <form method="post" action="controller_financeiro.php?acao=testar" class="d-inline">
                        <button type="submit" class="btn btn-outline-primary btn-sm">Testar conexão com o Cardápio Web</button>
                    </form>
                    <?php if ($teste): ?>
                        <div class="mt-3">
                            <?php if ($teste['ok']): ?>
                                <div class="alert alert-success mb-0">
                                    Conexão OK! Cadastros lidos: contas <strong><?= (int) $teste['contas'] ?></strong>,
                                    categorias <strong><?= (int) $teste['categorias'] ?></strong>,
                                    fornecedores <strong><?= (int) $teste['fornecedores'] ?></strong>,
                                    formas de pagamento <strong><?= (int) $teste['formas'] ?></strong>,
                                    centros de custo <strong><?= (int) $teste['centros'] ?></strong>.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger mb-0">Falha: <span class="mono"><?= htmlspecialchars($teste['erro']) ?></span></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="mb-2"><span class="status-dot bg-warning"></span> Ainda não configurado.</p>
                    <p class="mb-1">Crie <span class="mono">admin/config_financeiro.php</span> a partir do modelo
                       <span class="mono">.exemplo.php</span> e preencha:</p>
                    <ul class="mb-0">
                        <li><span class="mono">company_id</span> — id da empresa (header <span class="mono">companyid</span>)</li>
                        <li><span class="mono">refresh_token</span> — da resposta do login em <span class="mono">/auth/token</span>; <strong>segredo</strong></li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Credenciais editáveis pelo painel (sem precisar do Gerenciador de Arquivos) -->
        <div class="card mb-4" style="max-width: 820px;">
            <div class="card-header fw-semibold">
                <details<?= $configurado ? '' : ' open' ?>>
                    <summary style="cursor:pointer;">Credenciais da integração</summary>
                    <div class="pt-3">
                        <p class="text-muted small">
                            O <strong>refresh token</strong> do Cardápio Web expira a cada 5 dias — quando a conexão falhar,
                            cole o novo aqui. Campos em branco <strong>mantêm</strong> o valor atual.
                        </p>
                        <form method="post" action="controller_financeiro.php?acao=salvar_config">
                            <div class="row g-3">
                                <?php foreach (financeiro_config_campos() as $k => $meta): $def = !empty($cfgAtual[$k]); ?>
                                    <div class="col-md-6">
                                        <label class="form-label fw-normal"><?= htmlspecialchars($meta['rotulo']) ?></label>
                                        <?php if ($meta['secreto']): ?>
                                            <input type="password" name="<?= $k ?>" class="form-control" autocomplete="new-password"
                                                   placeholder="<?= $def ? '•••••••• (definido)' : 'não definido' ?>">
                                        <?php else: ?>
                                            <input type="text" name="<?= $k ?>" class="form-control"
                                                   value="<?= htmlspecialchars((string) ($cfgAtual[$k] ?? '')) ?>" placeholder="não definido">
                                        <?php endif; ?>
                                        <?php if ($meta['secreto']): ?>
                                            <div class="form-text"><?= $def ? '✓ já definido' : 'ainda não definido' ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-primary mt-3">Salvar credenciais</button>
                        </form>
                    </div>
                </details>
            </div>
        </div>

        <?php if (!$configurado): ?>
            <div class="card" style="max-width: 820px;">
                <div class="card-body text-muted">Configure a integração acima para liberar a importação.</div>
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
                    <div id="cp-chips" class="d-flex flex-wrap gap-2 mb-2"></div>
                    <textarea id="cp-text" class="form-control mb-2" rows="2"
                        placeholder="Descreva a compra (ex.: 'paguei 84,90 no pix, conta Sicredi, embalagens da SOS')&#10;ou anexe um XML / foto do QR pelo botão +"></textarea>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false">+ Anexar</button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" id="cp-add-xml">📄 Anexar XML da nota</a></li>
                                <li><a class="dropdown-item" href="#" id="cp-add-foto">🖼️ Anexar foto (QR ou cupom)</a></li>
                                <li><a class="dropdown-item" href="#" id="cp-add-cam" data-bs-toggle="modal" data-bs-target="#modalCamera">📷 Escanear QR com a câmera</a></li>
                            </ul>
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

                btnEnviar.onclick = async () => {
                    if (anexoXml) { travar(); enviarXml(anexoXml); return; }
                    if (anexoFoto) {
                        travar('Lendo…');
                        const qr = await lerQrDaImagem(anexoFoto);
                        if (qr) { enviarChave(qr); return; }          // QR → chave (grátis)
                        if (IA_ON) { travar('Lendo cupom…'); enviarCupom(anexoFoto, txt.value.trim()); return; }
                        destravar();
                        showMsg('Não encontrei QR nessa foto e a IA não está configurada.', 'warning');
                        return;
                    }
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
                                <select name="account" class="form-select" required>
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

                            <div class="col-md-6">
                                <label class="form-label">Categoria</label>
                                <input type="text" name="category" class="form-control" list="dl-categorias" value="<?= htmlspecialchars($l['category']) ?>">
                                <div class="form-text">Se não existir, o Cardápio Web cria automaticamente.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Centro de custo</label>
                                <input type="text" name="cost_center" class="form-control" list="dl-centros" value="<?= htmlspecialchars($l['cost_center']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Fornecedor</label>
                                <input type="text" name="supplier" class="form-control" list="dl-fornecedores" value="<?= htmlspecialchars($l['supplier']) ?>">
                                <div class="form-text">
                                    <?php if ($fornMatch === 'cnpj' || $fornMatch === 'regra'): ?>
                                        ✓ Fornecedor já cadastrado (encontrado pelo CNPJ).
                                    <?php elseif ($fornMatch === 'nome'): ?>
                                        ✓ Correspondência por semelhança com um fornecedor existente — confira se é o mesmo.
                                    <?php else: ?>
                                        Fornecedor novo (não encontrado no cadastro). Da nota: <span class="mono"><?= htmlspecialchars($revisao['fornecedor']['nome']) ?></span>.
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
<?php require __DIR__ . '/_footer.php'; ?>
