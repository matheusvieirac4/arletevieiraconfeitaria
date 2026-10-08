<?php
// Script ÚNICO: corrige o ponto da Larissa em setembro/2026 (celular que batia o ponto falhou).
// Acesse logado em /admin/ponto_corrigir_larissa_set.php -> mostra a PRÉVIA; só grava ao confirmar.
// Depois de aplicar, APAGUE este arquivo do repositório.
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_ponto.php';

const MES_INI = '2026-09-01';
const MES_FIM = '2026-09-30';
const PADRAO  = ['08:00', '16:30'];
const OBS     = 'correção manual set/2026 (falha no app)';
// Dias com hora extra anotada pela funcionária
const EXTRAS = [
    '2026-09-04' => ['07:37', '18:30'],
    '2026-09-05' => ['07:40', '20:00'],
    '2026-09-11' => ['07:30', '18:20'],
    '2026-09-18' => ['07:40', '18:00'],
    '2026-09-25' => ['07:30', '18:00'],
];

header('Content-Type: text/html; charset=utf-8');

$st = $pdo->query("SELECT id, nome, ativo FROM estoque_colaboradores WHERE nome LIKE '%larissa%'");
$achados = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($achados) !== 1) {
    echo '<pre>Esperava exatamente 1 colaboradora "Larissa", achei ' . count($achados) . ":\n";
    print_r($achados);
    echo '</pre>';
    exit;
}
$colab = (int) $achados[0]['id'];
$nome  = $achados[0]['nome'];

// ---- monta o plano: dia => [entrada, saída, motivo] ----
$plano = [];
$ignorados = [];
for ($d = strtotime(MES_INI); $d <= strtotime(MES_FIM); $d += 86400) {
    $data = date('Y-m-d', $d);
    $w = (int) date('w', $d);
    if (isset(EXTRAS[$data])) { $plano[$data] = [EXTRAS[$data][0], EXTRAS[$data][1], 'hora extra']; continue; }
    if ($w === 0 || $w === 6) { $ignorados[$data] = 'fim de semana'; continue; }
    $esp = ponto_especial_do_dia($pdo, $colab, $data);
    if ($esp) { $ignorados[$data] = 'dia especial: ' . $esp['tipo'] . ' ' . ($esp['descricao'] ?? ''); continue; }
    $plano[$data] = [PADRAO[0], PADRAO[1], 'padrão'];
}

// ---- batidas atuais ----
$st = $pdo->prepare("SELECT id, data, momento, tipo, origem, observacao FROM ponto_batidas
                     WHERE colaborador_id = :c AND data BETWEEN :i AND :f ORDER BY momento, id");
$st->execute([':c' => $colab, ':i' => MES_INI, ':f' => MES_FIM]);
$atuais = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) { $atuais[$b['data']][] = $b; }
$foraDoPlano = array_diff_key($atuais, $plano);

$aplicar = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmar'] ?? '') === 'SIM');

if ($aplicar) {
    // backup do que existia (arquivo + tela)
    $backup = ['colaborador_id' => $colab, 'nome' => $nome, 'em' => date('c'), 'batidas' => $atuais];
    $arq = __DIR__ . '/data/backup_ponto_larissa_2026-09_' . date('Ymd_His') . '.json';
    $okBackup = @file_put_contents($arq, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($okBackup === false) { echo '<p style="color:red">Não consegui gravar o backup em arquivo — abortado, nada foi alterado.</p>'; exit; }

    try {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM ponto_batidas WHERE colaborador_id = :c AND data = :d");
        $autor = (string) ($_SESSION['admin_nome'] ?? 'Admin');
        foreach ($plano as $data => [$e, $s, $motivo]) {
            $del->execute([':c' => $colab, ':d' => $data]);
            ponto_batida_salvar($pdo, ['colaborador_id' => $colab, 'data' => $data, 'hora' => $e, 'tipo' => 'entrada', 'observacao' => OBS], $autor);
            ponto_batida_salvar($pdo, ['colaborador_id' => $colab, 'data' => $data, 'hora' => $s, 'tipo' => 'saida',   'observacao' => OBS], $autor);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo '<p style="color:red">Falhou e foi revertido: ' . htmlspecialchars($e->getMessage()) . '</p>';
        exit;
    }
    echo '<h2>Pronto ✔ ' . count($plano) . ' dias corrigidos para ' . htmlspecialchars($nome) . '</h2>';
    echo '<p>Backup do que existia: <code>admin/data/' . htmlspecialchars(basename($arq)) . '</code></p>';
    echo '<p><a href="ponto_funcionario.php?id=' . $colab . '&mes=2026-09">Ver o espelho de setembro</a></p>';
    echo '<p><b>Agora apague este arquivo do repositório.</b></p>';
    exit;
}
?>
<!doctype html><meta charset="utf-8"><title>Corrigir ponto — Larissa set/2026</title>
<body style="font-family:sans-serif;max-width:760px;margin:24px auto;padding:0 12px">
<h2>Prévia — <?= htmlspecialchars($nome) ?> · setembro/2026</h2>
<p>Nada foi gravado ainda. Os dias abaixo terão as batidas existentes <b>substituídas</b> por 1 entrada + 1 saída (backup automático antes).</p>
<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;width:100%">
<tr><th>Data</th><th>Dia</th><th>Entrada</th><th>Saída</th><th>Tipo</th><th>Batidas atuais (serão removidas)</th></tr>
<?php foreach ($plano as $data => [$e, $s, $m]): $dow = ['dom','seg','ter','qua','qui','sex','sáb'][(int) date('w', strtotime($data))];
    $at = array_map(fn($b) => substr($b['momento'], 11, 5) . ' ' . $b['tipo'], $atuais[$data] ?? []); ?>
<tr style="<?= $m === 'hora extra' ? 'background:#fff3cd' : '' ?>">
<td><?= date('d/m', strtotime($data)) ?></td><td><?= $dow ?></td><td><?= $e ?></td><td><?= $s ?></td><td><?= $m ?></td>
<td><?= $at ? htmlspecialchars(implode(' · ', $at)) : '—' ?></td></tr>
<?php endforeach; ?>
</table>
<h4>Dias ignorados</h4>
<p><?php foreach ($ignorados as $dta => $mot) { echo date('d/m', strtotime($dta)) . ' (' . htmlspecialchars($mot) . '); '; } ?></p>
<?php if ($foraDoPlano): ?>
<p style="color:#b00"><b>Atenção:</b> há batidas em dias fora do plano (não serão tocadas):
<?= htmlspecialchars(implode(', ', array_map(fn($d) => date('d/m', strtotime($d)), array_keys($foraDoPlano)))) ?></p>
<?php endif; ?>
<form method="post" onsubmit="return confirm('Gravar no banco REAL?')">
<input type="hidden" name="confirmar" value="SIM">
<button style="padding:10px 20px;font-size:16px">Aplicar correção</button>
</form>
</body>
