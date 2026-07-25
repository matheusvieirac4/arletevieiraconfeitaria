<?php
require_once __DIR__ . '/_auth.php';
require_once 'model.php';

$id   = $_GET['id'] ?? null;
$post = $id ? blog_buscar_post($pdo, $id) : null;
$acao = $id ? 'editar' : 'inserir';

// HTML inicial do editor: do conteudo_html se houver; senão migra os 4
// parágrafos legados (texto puro) para <p> ao editar um post antigo.
$htmlInicial = '';
if ($post) {
    if (trim((string) ($post['conteudo_html'] ?? '')) !== '') {
        $htmlInicial = $post['conteudo_html'];
    } else {
        foreach (['conteudo','conteudo_dois','conteudo_tres','conteudo_quatro'] as $c) {
            $t = trim((string) ($post[$c] ?? ''));
            if ($t !== '') { $htmlInicial .= '<p>' . htmlspecialchars($t) . '</p>'; }
        }
    }
}

$page_title = ($id ? 'Editar' : 'Novo') . ' Post';
$active = 'blog';
$extra_head = '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">';
$extra_css = '
        #editor { min-height: 340px; background:#fff; }
        .ql-editor { font-size: 1rem; line-height: 1.6; }
        .ql-toolbar.ql-snow, .ql-container.ql-snow { border-color:#ced4da; }
        .ql-toolbar.ql-snow { border-radius:.2rem .2rem 0 0; }
        .ql-container.ql-snow { border-radius:0 0 .2rem .2rem; }
        .ia-chip { cursor:pointer; }';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0"><?= $id ? 'Editar' : 'Novo' ?> post</h1>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
        </div>

        <form method="post" action="controller.php?acao=<?= $acao ?><?= $id ? '&id=' . (int) $id : '' ?>"
              enctype="multipart/form-data" id="form-post">
            <input type="hidden" name="conteudo_html" id="conteudo_html">
            <?php if (!empty($post['imagem'])): ?>
                <input type="hidden" name="imagem_atual" value="<?= htmlspecialchars($post['imagem']) ?>">
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Título</label>
                                <input type="text" name="titulo" id="titulo" class="form-control form-control-lg"
                                       value="<?= htmlspecialchars($post['titulo'] ?? '') ?>" required>
                            </div>
                            <div class="mb-2 d-flex justify-content-between align-items-center">
                                <label class="form-label mb-0">Conteúdo</label>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btn-ia">
                                    <i data-feather="check-circle" class="align-middle me-1" style="width:15px;height:15px;"></i>
                                    Revisar com IA
                                </button>
                                <button type="button" id="ia-abre" class="d-none" data-bs-toggle="modal" data-bs-target="#modal-ia"></button>
                            </div>
                            <div id="editor"><?= $htmlInicial ?></div>
                            <div class="form-text">Use a barra para negrito, títulos, listas, links e imagens. As imagens são enviadas ao servidor automaticamente.</div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card mb-4">
                        <div class="card-header fw-semibold">Publicação</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Categoria</label>
                                <input type="text" name="categoria" class="form-control"
                                       value="<?= htmlspecialchars($post['categoria'] ?? '') ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Resumo <span class="text-muted small">(listagem e SEO)</span></label>
                                <textarea name="conteudo_resumido" id="conteudo_resumido" class="form-control" rows="3"
                                          placeholder="Deixe vazio para gerar automaticamente do conteúdo."><?= htmlspecialchars($post['conteudo_resumido'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Imagem de capa</label>
                                <input type="file" name="imagem" class="form-control" accept="image/*">
                                <?php if (!empty($post['imagem'])): ?>
                                    <img src="../img/imagens/blog/<?= htmlspecialchars($post['imagem']) ?>" alt="Capa atual"
                                         class="img-fluid rounded mt-2" style="max-height:130px;">
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">Salvar post</button>
                        <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </div>
            </div>
        </form>

        <!-- Painel de revisão da IA -->
        <div class="modal fade" id="modal-ia" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Revisão com IA</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body" id="ia-corpo">
                        <div class="text-center text-muted py-4" id="ia-carregando">
                            <span class="spinner-border spinner-border-sm me-2"></span>Analisando o texto…
                        </div>
                        <div id="ia-resultado" class="d-none"></div>
                        <div id="ia-erro" class="alert alert-danger d-none"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                        <button type="button" class="btn btn-success d-none" id="ia-aplicar-tudo" data-bs-dismiss="modal">Aplicar sugestões</button>
                    </div>
                </div>
            </div>
        </div>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function () {
    // ---- Editor Quill ----
    const quill = new Quill('#editor', {
        theme: 'snow',
        placeholder: 'Escreva o post aqui…',
        modules: {
            toolbar: {
                container: [
                    [{ header: [2, 3, 4, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['blockquote', 'link', 'image'],
                    ['clean'],
                ],
                handlers: { image: imageHandler },
            },
        },
    });

    // Upload de imagem do corpo -> servidor -> insere a URL retornada.
    function imageHandler() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.onchange = function () {
            const file = input.files && input.files[0];
            if (!file) { return; }
            const fd = new FormData();
            fd.append('imagem', file);
            const range = quill.getSelection(true);
            quill.insertText(range.index, 'enviando imagem…', { italic: true });
            fetch('blog_upload_imagem.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    quill.deleteText(range.index, 'enviando imagem…'.length);
                    if (d && d.url) {
                        quill.insertEmbed(range.index, 'image', d.url, 'user');
                        quill.setSelection(range.index + 1);
                    } else {
                        alert('Falha no upload: ' + ((d && d.error) || 'erro desconhecido'));
                    }
                })
                .catch(function (e) {
                    quill.deleteText(range.index, 'enviando imagem…'.length);
                    alert('Falha no upload: ' + e);
                });
        };
        input.click();
    }

    // Ao enviar: joga o HTML do editor no campo escondido; barra se vazio.
    const form = document.getElementById('form-post');
    form.addEventListener('submit', function (e) {
        const vazio = quill.getText().trim() === '';
        if (vazio) {
            e.preventDefault();
            alert('Escreva o conteúdo do post.');
            return;
        }
        document.getElementById('conteudo_html').value = quill.root.innerHTML;
    });

    // ---- Revisão com IA ----
    const btnIa = document.getElementById('btn-ia');
    const elCarregando = document.getElementById('ia-carregando');
    const elResultado = document.getElementById('ia-resultado');
    const elErro = document.getElementById('ia-erro');
    const btnAplicar = document.getElementById('ia-aplicar-tudo');
    let sugestao = null;

    btnIa.addEventListener('click', function () {
        if (quill.getText().trim() === '') { alert('Escreva o conteúdo antes de revisar.'); return; }
        elCarregando.classList.remove('d-none');
        elResultado.classList.add('d-none');
        elErro.classList.add('d-none');
        btnAplicar.classList.add('d-none');
        sugestao = null;
        document.getElementById('ia-abre').click();   // abre o modal (declarativo)

        const fd = new FormData();
        fd.append('titulo', document.getElementById('titulo').value);
        fd.append('conteudo_html', quill.root.innerHTML);
        fetch('blog_revisar_ia.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                elCarregando.classList.add('d-none');
                if (!d || d.error) { throw new Error((d && d.error) || 'erro'); }
                sugestao = d;
                renderSugestao(d);
                elResultado.classList.remove('d-none');
                btnAplicar.classList.remove('d-none');
            })
            .catch(function (e) {
                elCarregando.classList.add('d-none');
                elErro.textContent = 'Não consegui revisar: ' + e.message;
                elErro.classList.remove('d-none');
            });
    });

    function esc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function renderSugestao(d) {
        let h = '';
        if (d.titulo_sugerido && d.titulo_sugerido !== document.getElementById('titulo').value) {
            h += '<h6>Título sugerido</h6><p>' + esc(d.titulo_sugerido) + '</p>';
        }
        if (d.resumo_sugerido) {
            h += '<h6>Resumo (meta description)</h6><p>' + esc(d.resumo_sugerido) + '</p>';
        }
        if (Array.isArray(d.palavras_chave) && d.palavras_chave.length) {
            h += '<h6>Palavras-chave</h6><p>' + d.palavras_chave.map(esc).join(', ') + '</p>';
        }
        if (Array.isArray(d.sugestoes) && d.sugestoes.length) {
            h += '<h6>Observações</h6><ul>' + d.sugestoes.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>';
        }
        h += '<hr><h6>Texto revisado</h6><div class="border rounded p-2 bg-light" style="max-height:220px;overflow:auto;">'
             + (d.conteudo_revisado_html || '<em>sem alterações</em>') + '</div>';
        elResultado.innerHTML = h;
    }

    btnAplicar.addEventListener('click', function () {
        if (!sugestao) { return; }
        if (sugestao.conteudo_revisado_html) {
            quill.setContents([]);   // limpa
            quill.clipboard.dangerouslyPasteHTML(0, sugestao.conteudo_revisado_html);
        }
        if (sugestao.titulo_sugerido) { document.getElementById('titulo').value = sugestao.titulo_sugerido; }
        if (sugestao.resumo_sugerido) { document.getElementById('conteudo_resumido').value = sugestao.resumo_sugerido; }
        // o modal fecha via data-bs-dismiss no próprio botão
    });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
