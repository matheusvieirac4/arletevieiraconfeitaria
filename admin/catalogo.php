<?php
// Catálogo — tela única mestre-detalhe: categorias à esquerda, produtos da
// categoria selecionada à direita (agrupados por subcategoria).
require_once __DIR__ . '/_auth.php';
require_once 'model_catalogo.php';

if (!catalogo_pronto($pdo)) {
    $page_title = 'Catálogo'; $active = 'catalogo';
    require __DIR__ . '/_header.php';
    echo '<h1 class="mb-4">Catálogo</h1>';
    catalogo_exigir_setup();
    require __DIR__ . '/_footer.php';
    exit;
}

$categorias = catalogo_categorias_listar($pdo, true);

// Contagem de produtos por categoria.
$contagem = [];
foreach ($pdo->query(
    "SELECT s.categoria_id AS cid, COUNT(p.id) AS n
       FROM catalogo_subcategorias s
       LEFT JOIN catalogo_produtos p ON p.subcategoria_id = s.id
      GROUP BY s.categoria_id"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $contagem[(int) $r['cid']] = (int) $r['n'];
}

// Categoria selecionada (?cat=id); default = primeira.
$catId = (int) ($_GET['cat'] ?? 0);
$catSel = $catId > 0 ? catalogo_categoria_por_id($pdo, $catId) : null;
if (!$catSel && $categorias) { $catSel = $categorias[0]; $catId = (int) $catSel['id']; }

$subs = $catSel ? catalogo_subcategorias_listar($pdo, $catId) : [];
$produtos = $catSel ? catalogo_produtos_listar($pdo, ['categoria_id' => $catId, 'incluir_inativos' => true]) : [];
$porSub = [];
foreach ($produtos as $p) { $porSub[(int) $p['subcategoria_id']][] = $p; }

$flash = $_SESSION['catalogo_flash'] ?? null;
unset($_SESSION['catalogo_flash']);

$page_title = 'Catálogo';
$active = 'catalogo';
$extra_css = '
    .cat-pane { border:1px solid #e6e8ec; border-radius:.5rem; background:#fff; }
    .cat-pane-head { display:flex; align-items:center; justify-content:space-between; padding:.85rem 1rem; border-bottom:1px solid #eef0f3; }
    .cat-pane-head .ttl { font-size:.8rem; font-weight:700; letter-spacing:.06em; color:#6c757d; }
    .cat-list { list-style:none; margin:0; padding:.25rem; max-height:70vh; overflow:auto; }
    .cat-list li { display:flex; align-items:center; border-radius:.4rem; }
    .cat-list li.active { background:#eaf5ef; }
    .cat-list a.cat-link { flex:1; padding:.6rem .75rem; color:#28303f; text-decoration:none; font-weight:500; font-size:.95rem; }
    .cat-list li.active a.cat-link { color:#1c7c54; font-weight:700; }
    .cat-list .cat-count { color:#adb5bd; font-weight:400; font-size:.85rem; }
    .cat-list .oculta { font-size:.7rem; color:#adb5bd; }
    .sub-block { margin-bottom:1.4rem; }
    .sub-head { display:flex; align-items:center; justify-content:space-between; margin:0 0 .6rem; }
    .sub-head .nm { font-weight:700; }
    .prod-row { display:flex; align-items:center; gap:.5rem; border:1px solid #edeff2; border-radius:.5rem; padding:.5rem .6rem; height:100%; transition:border-color .15s, box-shadow .15s; }
    .prod-row:hover { border-color:#2ec07a; box-shadow:0 2px 10px rgba(46,192,122,.15); }
    .prod-row.inativo { opacity:.5; }
    .prod-main { display:flex; align-items:center; gap:.7rem; flex:1; min-width:0; text-decoration:none; color:inherit; cursor:pointer; }
    .prod-main:hover { color:inherit; }
    .prod-thumb { width:52px; height:52px; border-radius:.4rem; object-fit:cover; background:#f2f3f5; flex:0 0 auto; display:flex; align-items:center; justify-content:center; color:#c3c7cd; }
    .prod-info { flex:1; min-width:0; }
    .prod-info .nm { font-weight:600; font-size:.92rem; line-height:1.2; }
    .prod-info .pr { color:#6c757d; font-size:.82rem; }
    .prod-info .pr .sem { color:#c9820b; }
    .badge-ativo { border:1px solid #2ec07a; color:#1c7c54; border-radius:1rem; padding:.05rem .5rem; font-size:.68rem; font-weight:600; }
    .badge-off { border:1px solid #ced4da; color:#868e96; border-radius:1rem; padding:.05rem .5rem; font-size:.68rem; font-weight:600; }';
require __DIR__ . '/_header.php';
?>
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="mb-0">Catálogo</h1>
        </div>

        <div class="row g-3">
            <!-- Coluna esquerda: categorias -->
            <div class="col-lg-3">
                <div class="cat-pane">
                    <div class="cat-pane-head">
                        <span class="ttl">CATEGORIAS</span>
                        <button class="btn btn-sm btn-outline-success py-0 px-2" title="Nova categoria"
                                data-bs-toggle="modal" data-bs-target="#modal-categoria" onclick="catFormNovo()">+</button>
                    </div>
                    <?php if (!$categorias): ?>
                        <div class="p-3 text-muted small">Nenhuma categoria. Clique no “+”.</div>
                    <?php else: ?>
                    <ul class="cat-list">
                        <?php foreach ($categorias as $c): $ativa = (int) $c['id'] === $catId; ?>
                            <li class="<?= $ativa ? 'active' : '' ?>">
                                <a class="cat-link" href="?cat=<?= (int) $c['id'] ?>">
                                    <?= htmlspecialchars($c['nome']) ?>
                                    <span class="cat-count">(<?= $contagem[(int) $c['id']] ?? 0 ?>)</span>
                                    <?php if (!$c['ativo']): ?><span class="oculta"> · oculta</span><?php endif; ?>
                                </a>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-link text-muted px-2" data-bs-toggle="dropdown" aria-expanded="false">⋮</button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><a class="dropdown-item" href="#" onclick='catFormEditar(<?= json_encode($c, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);return false;' data-bs-toggle="modal" data-bs-target="#modal-categoria">Editar</a></li>
                                        <li><a class="dropdown-item text-danger js-confirm" href="controller_catalogo.php?acao=categoria_excluir&id=<?= (int) $c['id'] ?>" data-msg="Excluir “<?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>” e TODOS os produtos dela?">Excluir</a></li>
                                    </ul>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Coluna direita: produtos da categoria -->
            <div class="col-lg-9">
                <div class="cat-pane">
                    <div class="cat-pane-head">
                        <span class="ttl"><?= $catSel ? htmlspecialchars(mb_strtoupper($catSel['nome'])) : 'PRODUTOS' ?></span>
                        <?php if ($catSel): ?>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modal-sub" onclick="subFormNovo()">+ Subcategoria</button>
                            <button class="btn btn-sm btn-outline-primary" onclick='catFormEditar(<?= json_encode($catSel, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' data-bs-toggle="modal" data-bs-target="#modal-categoria">Editar categoria</button>
                            <a class="btn btn-sm btn-success<?= $subs ? '' : ' disabled' ?>" href="catalogo_produto.php?categoria_id=<?= $catId ?>">+ Produto</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <?php if (!$catSel): ?>
                            <p class="text-muted mb-0">Crie uma categoria para começar.</p>
                        <?php elseif (!$subs): ?>
                            <div class="alert alert-warning bg-white mb-0">Crie uma <strong>subcategoria</strong> (ex.: Tradicionais) antes de cadastrar produtos.</div>
                        <?php else: ?>
                            <?php foreach ($subs as $s): $lista = $porSub[(int) $s['id']] ?? []; ?>
                                <div class="sub-block">
                                    <div class="sub-head">
                                        <span class="nm"><?= htmlspecialchars($s['nome']) ?> <span class="text-muted fw-normal">(<?= count($lista) ?>)</span><?= $s['ativo'] ? '' : ' <span class="badge-off">oculta</span>' ?></span>
                                        <div class="d-flex gap-1">
                                            <a class="btn btn-sm btn-outline-success py-0 px-2" href="catalogo_produto.php?categoria_id=<?= $catId ?>&subcategoria_id=<?= (int) $s['id'] ?>" title="Novo produto">+ Produto</a>
                                            <button class="btn btn-sm btn-link text-muted py-0 px-1" title="Editar subcategoria"
                                                    data-bs-toggle="modal" data-bs-target="#modal-sub"
                                                    onclick='subFormEditar(<?= json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i data-feather="edit-2" style="width:15px;height:15px;"></i></button>
                                            <a class="btn btn-sm btn-link text-danger py-0 px-1 js-confirm" title="Excluir subcategoria"
                                               href="controller_catalogo.php?acao=sub_excluir&id=<?= (int) $s['id'] ?>&categoria_id=<?= $catId ?>"
                                               data-msg="Excluir a subcategoria “<?= htmlspecialchars($s['nome'], ENT_QUOTES) ?>”<?= count($lista) ? ' e seus ' . count($lista) . ' produto(s)' : '' ?>?"><i data-feather="trash-2" style="width:15px;height:15px;"></i></a>
                                        </div>
                                    </div>
                                    <?php if (!$lista): ?>
                                        <p class="text-muted small mb-0">Nenhum produto aqui.</p>
                                    <?php else: ?>
                                        <div class="row g-2">
                                        <?php foreach ($lista as $p): $ap = catalogo_preco_a_partir($p); ?>
                                            <div class="col-xl-4 col-md-6">
                                                <div class="prod-row<?= $p['ativo'] ? '' : ' inativo' ?>">
                                                    <a class="prod-main" href="catalogo_produto.php?id=<?= (int) $p['id'] ?>" title="Editar <?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>">
                                                        <?php if (!empty($p['imagem'])): ?>
                                                            <img class="prod-thumb" src="../<?= htmlspecialchars(ltrim($p['imagem'], '/')) ?>" alt="">
                                                        <?php else: ?>
                                                            <span class="prod-thumb"><i data-feather="image" style="width:20px;height:20px;"></i></span>
                                                        <?php endif; ?>
                                                        <span class="prod-info">
                                                            <span class="nm d-block text-truncate"><?= htmlspecialchars($p['nome']) ?></span>
                                                            <span class="pr d-block">
                                                                <?= $ap !== null ? 'a partir de <strong>' . catalogo_moeda($ap) . '</strong>' : '<span class="sem">sem preço</span>' ?>
                                                            </span>
                                                        </span>
                                                    </a>
                                                    <span class="<?= $p['ativo'] ? 'badge-ativo' : 'badge-off' ?>"><?= $p['ativo'] ? 'ATIVO' : 'OCULTO' ?></span>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-link text-muted px-1" data-bs-toggle="dropdown">⋮</button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li><a class="dropdown-item" href="catalogo_produto.php?id=<?= (int) $p['id'] ?>">Editar</a></li>
                                                            <li><a class="dropdown-item text-danger js-confirm" href="controller_catalogo.php?acao=produto_excluir&id=<?= (int) $p['id'] ?>&categoria_id=<?= $catId ?>" data-msg="Excluir “<?= htmlspecialchars($p['nome'], ENT_QUOTES) ?>”?">Excluir</a></li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

<!-- Modal categoria -->
<div class="modal fade" id="modal-categoria" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="controller_catalogo.php?acao=categoria_salvar" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title" id="cat-modal-titulo">Nova categoria</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="cat-id">
        <div class="mb-3">
          <label class="form-label">Nome</label>
          <input type="text" name="nome" id="cat-nome" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Slug (URL) <span class="text-muted small">— em branco gera do nome</span></label>
          <input type="text" name="slug" id="cat-slug" class="form-control" placeholder="docinhos">
        </div>
        <div class="mb-3">
          <label class="form-label">Descrição (opcional)</label>
          <textarea name="descricao" id="cat-descricao" class="form-control" rows="2"></textarea>
        </div>
        <div class="row g-2">
          <div class="col-8">
            <label class="form-label">Imagem de capa (opcional)</label>
            <input type="file" name="imagem_capa_arquivo" class="form-control" accept="image/*">
            <div class="form-text" id="cat-capa-atual"></div>
          </div>
          <div class="col-4">
            <label class="form-label">Ordem</label>
            <input type="number" name="ordem" id="cat-ordem" class="form-control" value="0">
          </div>
        </div>
        <div class="form-check mt-3">
          <input type="checkbox" name="ativo" id="cat-ativo" class="form-check-input" checked>
          <label class="form-check-label" for="cat-ativo">Visível no site</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal subcategoria -->
<div class="modal fade" id="modal-sub" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="controller_catalogo.php?acao=sub_salvar">
      <div class="modal-header">
        <h5 class="modal-title" id="sub-modal-titulo">Nova subcategoria</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="sub-id">
        <input type="hidden" name="categoria_id" value="<?= $catId ?>">
        <div class="mb-3">
          <label class="form-label">Nome</label>
          <input type="text" name="nome" id="sub-nome" class="form-control" required>
        </div>
        <div class="row g-2">
          <div class="col-8">
            <label class="form-label">Slug <span class="text-muted small">— em branco gera do nome</span></label>
            <input type="text" name="slug" id="sub-slug" class="form-control" placeholder="tradicionais">
          </div>
          <div class="col-4">
            <label class="form-label">Ordem</label>
            <input type="number" name="ordem" id="sub-ordem" class="form-control" value="0">
          </div>
        </div>
        <div class="form-check mt-3">
          <input type="checkbox" name="ativo" id="sub-ativo" class="form-check-input" checked>
          <label class="form-check-label" for="sub-ativo">Visível no site</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
function catFormNovo() {
    document.getElementById('cat-modal-titulo').textContent = 'Nova categoria';
    document.getElementById('cat-id').value = '';
    document.getElementById('cat-nome').value = '';
    document.getElementById('cat-slug').value = '';
    document.getElementById('cat-descricao').value = '';
    document.getElementById('cat-ordem').value = '0';
    document.getElementById('cat-ativo').checked = true;
    document.getElementById('cat-capa-atual').textContent = '';
}
function catFormEditar(c) {
    document.getElementById('cat-modal-titulo').textContent = 'Editar categoria';
    document.getElementById('cat-id').value = c.id;
    document.getElementById('cat-nome').value = c.nome || '';
    document.getElementById('cat-slug').value = c.slug || '';
    document.getElementById('cat-descricao').value = c.descricao || '';
    document.getElementById('cat-ordem').value = c.ordem || 0;
    document.getElementById('cat-ativo').checked = String(c.ativo) === '1';
    document.getElementById('cat-capa-atual').textContent = c.imagem_capa ? 'Atual: ' + c.imagem_capa + ' (envie outra para trocar)' : '';
}
function subFormNovo() {
    document.getElementById('sub-modal-titulo').textContent = 'Nova subcategoria';
    document.getElementById('sub-id').value = '';
    document.getElementById('sub-nome').value = '';
    document.getElementById('sub-slug').value = '';
    document.getElementById('sub-ordem').value = '0';
    document.getElementById('sub-ativo').checked = true;
}
function subFormEditar(s) {
    document.getElementById('sub-modal-titulo').textContent = 'Editar subcategoria';
    document.getElementById('sub-id').value = s.id;
    document.getElementById('sub-nome').value = s.nome || '';
    document.getElementById('sub-slug').value = s.slug || '';
    document.getElementById('sub-ordem').value = s.ordem || 0;
    document.getElementById('sub-ativo').checked = String(s.ativo) === '1';
}
</script>
<?php require __DIR__ . '/_footer.php'; ?>
