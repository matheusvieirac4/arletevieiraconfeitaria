<?php
// Exporta o fechamento do mês em CSV (para a contabilidade). Uma linha por
// pessoa com os totais do mês. Separador ';' e BOM UTF-8 (abre certo no Excel).
require_once __DIR__ . '/_auth.php';
require_once 'model_ponto.php';

if (!ponto_pronto($pdo)) { header('Location: ponto.php'); exit; }

$mesRef = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) { $mesRef = date('Y-m'); }
[$ano, $mes] = array_map('intval', explode('-', $mesRef));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ponto_' . $mesRef . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF");   // BOM p/ Excel

$cab = ['Pessoa', 'Tipo', 'Dias trabalhados', 'Horas trabalhadas', 'Horas esperadas',
        'Horas extras', 'Faltas (horas)', 'Faltas (dias)', 'Saldo (banco de horas)', 'Pendencias'];
fputcsv($out, $cab, ';');

foreach (ponto_dashboard($pdo, $ano, $mes) as $l) {
    $p = $l['pessoa']; $t = $l['totais'];
    $meta = !empty($p['tem_meta']);
    fputcsv($out, [
        $p['nome'],
        ponto_tipo_info($p['tipo'])[0],
        (int) $t['dias_trabalhados'],
        ponto_hhmm($t['trabalhado_min']),
        $meta ? ponto_hhmm($t['esperado_min']) : '',
        $meta ? ponto_hhmm($t['extra_min']) : '',
        $meta ? ponto_hhmm($t['falta_min']) : '',
        $meta ? (int) $t['falta_dias'] : '',
        $meta ? ponto_hhmm($t['saldo_min']) : '',
        (int) $t['abertos'],
    ], ';');
}
fclose($out);
