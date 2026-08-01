<?php
require_once __DIR__ . '/_auth.php';
require_once 'model_curriculos.php';

$flash = $_SESSION['curriculos_flash'] ?? null;
unset($_SESSION['curriculos_flash']);

$statusMap = curriculos_status_map();
$idDetalhe = (int) ($_GET['id'] ?? 0);
$detalhe   = $idDetalhe ? curriculos_buscar($pdo, $idDetalhe) : null;

$filtro    = $_GET['status'] ?? 'todos';
$contagem  = curriculos_contagem($pdo);
$lista     = $detalhe ? [] : curriculos_listar($pdo, $filtro);

$page_title = 'Currículos';
$active = 'curriculos';

// Rótulos amigáveis dos campos do candidato para a tela de detalhe.
$rotulos = [
    'email'           => 'E-mail',
    'contato'         => 'Contato (WhatsApp)',
    'data_nascimento' => 'Data de nascimento',
    'bairro_cidade'   => 'Bairro / Cidade',
    'vaga_interesse'  => 'Vaga de interesse',
    'cursos'          => 'Cursos / Especialização',
    'experiencia'     => 'Experiência na área',
    'observacoes'     => 'Observações',
];

function curr_badge(array $statusMap, string $status): string {
    [$rot, $cor] = $statusMap[$status] ?? ['—', 'secondary'];
    return '<span class="badge bg-' . $cor . '">' . htmlspecialchars($rot) . '</span>';
}

require __DIR__ . '/_header.php';
?>
<?php if ($detalhe): // ---------------------------------------- DETALHE ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="mb-0">Currículo #<?= (int) $detalhe['id'] ?></h1>
            <a href="curriculos.php" class="btn btn-outline-secondary btn-sm">&larr; Voltar à lista</a>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3 class="card-title mb-0"><?= htmlspecialchars($detalhe['nome'] ?: 'Sem nome informado') ?></h3>
                        <?= curr_badge($statusMap, $detalhe['status']) ?>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <tbody>
                            <?php foreach ($rotulos as $campo => $rot):
                                $val = $detalhe[$campo] ?? '';
                                if ($campo === 'data_nascimento' && $val) { $val = date('d/m/Y', strtotime($val)); }
                            ?>
                                <tr>
                                    <th style="width: 200px;" class="text-muted"><?= $rot ?></th>
                                    <td><?= $val !== '' && $val !== null ? nl2br(htmlspecialchars($val)) : '<span class="text-muted">—</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                                <tr>
                                    <th class="text-muted">Recebido em</th>
                                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($detalhe['criado_em']))) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h4 class="card-title mb-0">Ações</h4></div>
                    <div class="card-body">
                        <?php if (!empty($detalhe['curriculo_pdf'])): ?>
                            <a href="curriculo_download.php?id=<?= (int) $detalhe['id'] ?>" class="btn btn-success w-100 mb-3">
                                <i class="align-middle" data-feather="download"></i> Baixar PDF do currículo
                            </a>
                        <?php else: ?>
                            <p class="text-muted small">Nenhum PDF anexado.</p>
                        <?php endif; ?>

                        <form method="post" action="controller_curriculos.php?acao=status">
                            <input type="hidden" name="id" value="<?= (int) $detalhe['id'] ?>">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select mb-2">
                                <?php foreach ($statusMap as $chave => $info): ?>
                                    <option value="<?= $chave ?>" <?= $detalhe['status'] === $chave ? 'selected' : '' ?>><?= htmlspecialchars($info[0]) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary w-100">Salvar status</button>
                        </form>

                        <hr>
                        <?php $waNum = preg_replace('/\D+/', '', (string) $detalhe['contato']);
                              if ($waNum && strlen($waNum) >= 10):
                                  if (strlen($waNum) <= 11) { $waNum = '55' . $waNum; } ?>
                            <a href="https://wa.me/<?= $waNum ?>" target="_blank" class="btn btn-outline-success w-100 mb-2">
                                <i class="align-middle" data-feather="message-circle"></i> Chamar no WhatsApp
                            </a>
                        <?php endif; ?>
                        <a href="mailto:<?= htmlspecialchars($detalhe['email']) ?>" class="btn btn-outline-primary w-100 mb-2">
                            <i class="align-middle" data-feather="mail"></i> Enviar e-mail
                        </a>
                        <a href="controller_curriculos.php?acao=deletar&id=<?= (int) $detalhe['id'] ?>"
                           class="btn btn-outline-danger w-100 js-confirm" data-msg="Excluir este currículo? O PDF também será apagado.">
                            <i class="align-middle" data-feather="trash-2"></i> Excluir
                        </a>
                    </div>
                </div>
            </div>
        </div>

<?php else: // ---------------------------------------------------- LISTA ?>
        <h1 class="mb-4">Currículos recebidos</h1>

        <div class="mb-3">
            <a href="curriculos.php?status=todos" class="btn btn-sm <?= $filtro === 'todos' ? 'btn-dark' : 'btn-outline-dark' ?>">
                Todos <span class="badge bg-light text-dark"><?= (int) $contagem['todos'] ?></span>
            </a>
            <?php foreach ($statusMap as $chave => $info): ?>
                <a href="curriculos.php?status=<?= $chave ?>" class="btn btn-sm <?= $filtro === $chave ? 'btn-' . $info[1] : 'btn-outline-' . $info[1] ?>">
                    <?= htmlspecialchars($info[0]) ?> <span class="badge bg-light text-dark"><?= (int) ($contagem[$chave] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <table class="table table-hover table-striped bg-white mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome</th>
                        <th>Vaga</th>
                        <th>Contato</th>
                        <th>Cidade</th>
                        <th>PDF</th>
                        <th>Status</th>
                        <th>Recebido</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$lista): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Nenhum currículo por aqui ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($lista as $c): ?>
                    <tr>
                        <td><?= (int) $c['id'] ?></td>
                        <td><?= htmlspecialchars($c['nome'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($c['vaga_interesse'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($c['contato'] ?: '—') ?></td>
                        <td><?= htmlspecialchars($c['bairro_cidade'] ?: '—') ?></td>
                        <td class="text-center"><?= !empty($c['curriculo_pdf']) ? '<i data-feather="paperclip" class="text-success"></i>' : '<span class="text-muted">—</span>' ?></td>
                        <td><?= curr_badge($statusMap, $c['status']) ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($c['criado_em']))) ?></td>
                        <td class="text-end">
                            <a href="curriculos.php?id=<?= (int) $c['id'] ?>" class="btn btn-primary btn-sm">Ver</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
