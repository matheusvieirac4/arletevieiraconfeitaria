<?php
require_once __DIR__ . '/../includes/banco.php';
require_once __DIR__ . '/model_estoque.php';   // reutiliza estoque_colaboradores (pessoas) + PIN/token

// ============================================================================
// Controle de ponto: batidas (relógio) + jornada por pessoa + apuração mensal.
//
// Pessoas = a MESMA tabela estoque_colaboradores (nome + PIN). Aqui só guardamos
// a configuração de jornada (ponto_jornada) e as batidas (ponto_batidas).
//
// Modelo de intervalo (decisão do Matheus): 2 batidas por dia (entrada+saída) e
// desconto FIXO do almoço quando o dia passa de um limite — MAS só descontamos
// quando houve um único par no dia; se a pessoa bateu para o almoço (2 pares),
// o intervalo já saiu do somatório e não descontamos de novo.
//
// Jornada por pessoa: horas esperadas por dia da semana (h_dom..h_sab). Isso
// resolve sábado meio-período e folga (0h). Sócios podem não ter meta
// (tem_meta=0): registram horas mas sem cálculo de extra/falta.
// ============================================================================

/** As tabelas do ponto já existem? (o setup precisa ter rodado uma vez). */
function ponto_pronto(PDO $pdo): bool
{
    try { $pdo->query("SELECT 1 FROM ponto_batidas LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
}

/** Aviso pedindo para rodar o setup do ponto. */
function ponto_exigir_setup(): void
{
    echo '<div class="card" style="max-width:640px;"><div class="card-body">'
       . '<h5 class="mb-2">Módulo de ponto ainda não inicializado</h5>'
       . '<p class="text-muted">As tabelas do ponto precisam ser criadas uma vez.</p>'
       . '<a href="ponto_setup.php" class="btn btn-primary">Inicializar ponto agora</a>'
       . '</div></div>';
}

// ------------------- Jornada (configuração por pessoa) ----------------------

/** Defaults de jornada para uma pessoa sem configuração salva ainda. */
function ponto_jornada_default(): array
{
    return [
        'tipo'            => 'freelancer',
        'tem_meta'        => 1,
        'h_dom' => 0.0, 'h_seg' => 8.0, 'h_ter' => 8.0, 'h_qua' => 8.0,
        'h_qui' => 8.0, 'h_sex' => 8.0, 'h_sab' => 4.0,
        'intervalo_desconto_min' => 60,
        'intervalo_limite_h'     => 6.0,
        'tolerancia_min'         => 10,
    ];
}

/** Pessoas do ponto = colaboradores + jornada (LEFT JOIN; defaults se faltar). */
function ponto_pessoas(PDO $pdo, bool $soAtivos = true): array
{
    $sql = "SELECT c.id, c.nome, c.ativo,
                   j.tipo, j.tem_meta,
                   j.h_dom, j.h_seg, j.h_ter, j.h_qua, j.h_qui, j.h_sex, j.h_sab,
                   j.intervalo_desconto_min, j.intervalo_limite_h, j.tolerancia_min
            FROM estoque_colaboradores c
            LEFT JOIN ponto_jornada j ON j.colaborador_id = c.id";
    if ($soAtivos) { $sql .= " WHERE c.ativo = 1"; }
    $sql .= " ORDER BY c.nome";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $def = ponto_jornada_default();
    foreach ($rows as &$r) {
        foreach ($def as $k => $v) {
            if ($r[$k] === null) { $r[$k] = $v; }
        }
        $r['id']       = (int) $r['id'];
        $r['tem_meta'] = (int) $r['tem_meta'];
        foreach (['h_dom','h_seg','h_ter','h_qua','h_qui','h_sex','h_sab','intervalo_limite_h'] as $k) { $r[$k] = (float) $r[$k]; }
        foreach (['intervalo_desconto_min','tolerancia_min'] as $k) { $r[$k] = (int) $r[$k]; }
    }
    unset($r);
    return $rows;
}

/** Uma pessoa (com jornada); null se não existir/estiver inativa. */
function ponto_pessoa(PDO $pdo, int $id, bool $soAtivos = true): ?array
{
    foreach (ponto_pessoas($pdo, $soAtivos) as $p) {
        if ($p['id'] === $id) { return $p; }
    }
    return null;
}

/** Salva (upsert) a jornada de uma pessoa. */
function ponto_jornada_salvar(PDO $pdo, int $colaboradorId, array $d): void
{
    if ($colaboradorId <= 0) { throw new InvalidArgumentException('Pessoa inválida.'); }
    $tipo = ($d['tipo'] ?? 'freelancer') === 'socio' ? 'socio' : 'freelancer';
    $temMeta = !empty($d['tem_meta']) ? 1 : 0;
    $hora = function ($v) { $v = (float) str_replace(',', '.', (string) $v); return max(0, min(24, $v)); };
    $params = [
        ':id'   => $colaboradorId,
        ':tipo' => $tipo,
        ':meta' => $temMeta,
        ':dom'  => $hora($d['h_dom'] ?? 0), ':seg' => $hora($d['h_seg'] ?? 0),
        ':ter'  => $hora($d['h_ter'] ?? 0), ':qua' => $hora($d['h_qua'] ?? 0),
        ':qui'  => $hora($d['h_qui'] ?? 0), ':sex' => $hora($d['h_sex'] ?? 0),
        ':sab'  => $hora($d['h_sab'] ?? 0),
        ':desc' => max(0, (int) ($d['intervalo_desconto_min'] ?? 60)),
        ':lim'  => $hora($d['intervalo_limite_h'] ?? 6),
        ':tol'  => max(0, (int) ($d['tolerancia_min'] ?? 10)),
    ];
    $pdo->prepare("
        INSERT INTO ponto_jornada
            (colaborador_id, tipo, tem_meta, h_dom, h_seg, h_ter, h_qua, h_qui, h_sex, h_sab,
             intervalo_desconto_min, intervalo_limite_h, tolerancia_min)
        VALUES (:id, :tipo, :meta, :dom, :seg, :ter, :qua, :qui, :sex, :sab, :desc, :lim, :tol)
        ON DUPLICATE KEY UPDATE
            tipo=:tipo, tem_meta=:meta, h_dom=:dom, h_seg=:seg, h_ter=:ter, h_qua=:qua,
            h_qui=:qui, h_sex=:sex, h_sab=:sab, intervalo_desconto_min=:desc,
            intervalo_limite_h=:lim, tolerancia_min=:tol
    ")->execute($params);
}

/** Horas esperadas (em minutos) para uma pessoa num dia da semana (0=dom..6=sáb). */
function ponto_esperado_min(array $jornada, int $w): int
{
    if (empty($jornada['tem_meta'])) { return 0; }
    $cols = ['h_dom','h_seg','h_ter','h_qua','h_qui','h_sex','h_sab'];
    $h = (float) ($jornada[$cols[$w]] ?? 0);
    return (int) round($h * 60);
}

// ------------------- Batidas (relógio de ponto) -----------------------------

/** Janela (segundos) em que um novo toque é tratado como duplicado acidental. */
const PONTO_DEDUP_SEG = 60;

/** Última batida da pessoa (qualquer dia). Null se nunca bateu. */
function ponto_ultima_batida(PDO $pdo, int $colaboradorId): ?array
{
    $st = $pdo->prepare("SELECT id, momento, tipo FROM ponto_batidas
                         WHERE colaborador_id = :c
                         ORDER BY momento DESC, id DESC LIMIT 1");
    $st->execute([':c' => $colaboradorId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/**
 * Decide o próximo tipo (entrada/saída) a partir da ÚLTIMA batida real:
 *  - sem batida, ou última = saída            -> entrada
 *  - última = entrada no MESMO dia            -> saída
 *  - última = entrada de um dia ANTERIOR      -> entrada (esqueceu a saída;
 *                                                sinaliza 'esqueceu')
 * Também sinaliza 'duplicado' quando a última batida foi há poucos segundos.
 */
function ponto_proximo_tipo(PDO $pdo, int $colaboradorId, ?string $agora = null): array
{
    $agora = $agora ?: date('Y-m-d H:i:s');
    $ult = ponto_ultima_batida($pdo, $colaboradorId);
    $duplicado = $ult && (strtotime($agora) - strtotime($ult['momento'])) < PONTO_DEDUP_SEG
                       && (strtotime($agora) - strtotime($ult['momento'])) >= 0;

    if (!$ult || $ult['tipo'] === 'saida') {
        return ['tipo' => 'entrada', 'esqueceu' => false, 'duplicado' => $duplicado, 'ultima' => $ult];
    }
    // Última é entrada em aberto.
    $mesmoDia = substr($ult['momento'], 0, 10) === substr($agora, 0, 10);
    if ($mesmoDia) {
        return ['tipo' => 'saida', 'esqueceu' => false, 'duplicado' => $duplicado, 'ultima' => $ult];
    }
    // Entrada aberta de um dia anterior: a pessoa esqueceu de bater a saída.
    // Começa uma nova entrada hoje e sinaliza para o admin corrigir o dia velho.
    return ['tipo' => 'entrada', 'esqueceu' => true, 'duplicado' => false,
            'aberta_em' => $ult['momento'], 'ultima' => $ult];
}

/**
 * Registra uma batida. Se $tipo for null, o servidor decide (entrada/saída) pela
 * última batida real — ver ponto_proximo_tipo. Uma trava anti-duplicidade
 * ignora toques repetidos em menos de PONTO_DEDUP_SEG. Devolve
 * ['tipo','momento','esqueceu','duplicado'].
 */
function ponto_registrar(PDO $pdo, int $colaboradorId, string $origem = 'kiosk', ?string $momento = null, ?string $tipo = null, string $criadoPor = ''): array
{
    if ($colaboradorId <= 0) { throw new InvalidArgumentException('Pessoa inválida.'); }
    $ts = $momento ? date('Y-m-d H:i:s', strtotime($momento)) : date('Y-m-d H:i:s');
    $data = substr($ts, 0, 10);

    $decisao = ponto_proximo_tipo($pdo, $colaboradorId, $ts);
    // Toque repetido em poucos segundos (só no fluxo automático do quiosque):
    // não cria batida nova — devolve a última como já registrada.
    if ($tipo === null && !empty($decisao['duplicado']) && $decisao['ultima']) {
        return ['tipo' => $decisao['ultima']['tipo'], 'momento' => $decisao['ultima']['momento'],
                'esqueceu' => false, 'duplicado' => true];
    }
    if ($tipo === null) { $tipo = $decisao['tipo']; }
    $tipo = $tipo === 'saida' ? 'saida' : 'entrada';
    $origem = $origem === 'admin' ? 'admin' : 'kiosk';

    $pdo->prepare("
        INSERT INTO ponto_batidas (colaborador_id, data, momento, tipo, origem, criado_por)
        VALUES (:c, :d, :m, :t, :o, :p)
    ")->execute([
        ':c' => $colaboradorId, ':d' => $data, ':m' => $ts,
        ':t' => $tipo, ':o' => $origem, ':p' => ($criadoPor !== '' ? $criadoPor : null),
    ]);
    return ['tipo' => $tipo, 'momento' => $ts,
            'esqueceu' => !empty($decisao['esqueceu']), 'duplicado' => false];
}

/** A pessoa está "dentro" agora? (última batida real é uma entrada de hoje). */
function ponto_dentro(PDO $pdo, int $colaboradorId, ?string $data = null): bool
{
    $hoje = $data ?: date('Y-m-d');
    $ult = ponto_ultima_batida($pdo, $colaboradorId);
    return $ult && $ult['tipo'] === 'entrada' && substr($ult['momento'], 0, 10) === $hoje;
}

/** Estado atual para a tela do quiosque. */
function ponto_status(PDO $pdo, int $colaboradorId): array
{
    $hoje = date('Y-m-d');
    $batidas = ponto_batidas_dia($pdo, $colaboradorId, $hoje);
    $calc = ponto_calc_dia($batidas, ponto_pessoa($pdo, $colaboradorId) ?? ponto_jornada_default(), (int) date('w'));
    $prox = ponto_proximo_tipo($pdo, $colaboradorId);
    $dentro = ($prox['tipo'] === 'saida');
    $desde = null;
    if ($dentro) {
        for ($i = count($batidas) - 1; $i >= 0; $i--) {
            if ($batidas[$i]['tipo'] === 'entrada') { $desde = substr($batidas[$i]['momento'], 11, 5); break; }
        }
    }
    return [
        'dentro'         => $dentro,
        'desde'          => $desde,
        'batidas'        => array_map(function ($b) { return ['tipo' => $b['tipo'], 'hora' => substr($b['momento'], 11, 5)]; }, $batidas),
        'trabalhado_min' => $calc['trabalhado_min'],
        'proximo'        => $prox['tipo'],
        'esqueceu'       => !empty($prox['esqueceu']),
    ];
}

/** Batidas de uma pessoa num dia (ordenadas). */
function ponto_batidas_dia(PDO $pdo, int $colaboradorId, string $data): array
{
    $st = $pdo->prepare("SELECT id, colaborador_id, data, momento, tipo, origem, observacao, criado_por
                         FROM ponto_batidas WHERE colaborador_id = :c AND data = :d
                         ORDER BY momento ASC, id ASC");
    $st->execute([':c' => $colaboradorId, ':d' => $data]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Batidas de uma pessoa num mês (para o espelho), agrupadas por data. */
function ponto_batidas_mes(PDO $pdo, int $colaboradorId, int $ano, int $mes): array
{
    $ini = sprintf('%04d-%02d-01', $ano, $mes);
    $fim = date('Y-m-d', strtotime($ini . ' +1 month'));
    $st = $pdo->prepare("SELECT id, data, momento, tipo, origem, observacao, criado_por
                         FROM ponto_batidas
                         WHERE colaborador_id = :c AND data >= :i AND data < :f
                         ORDER BY momento ASC, id ASC");
    $st->execute([':c' => $colaboradorId, ':i' => $ini, ':f' => $fim]);
    $por = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) { $por[$b['data']][] = $b; }
    return $por;
}

/** Cria/edita uma batida manualmente (admin). Retorna o id. */
function ponto_batida_salvar(PDO $pdo, array $d, string $criadoPor = ''): int
{
    $id    = (int) ($d['id'] ?? 0);
    $colab = (int) ($d['colaborador_id'] ?? 0);
    $tipo  = ($d['tipo'] ?? 'entrada') === 'saida' ? 'saida' : 'entrada';
    $obs   = trim((string) ($d['observacao'] ?? '')) ?: null;
    $data  = (string) ($d['data'] ?? '');
    $hora  = (string) ($d['hora'] ?? '');
    if ($colab <= 0)                       { throw new InvalidArgumentException('Pessoa inválida.'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { throw new InvalidArgumentException('Data inválida.'); }
    if (!preg_match('/^\d{2}:\d{2}$/', $hora))       { throw new InvalidArgumentException('Hora inválida.'); }
    $momento = $data . ' ' . $hora . ':00';
    if ($id > 0) {
        $pdo->prepare("UPDATE ponto_batidas SET colaborador_id=:c, data=:d, momento=:m, tipo=:t, observacao=:o, criado_por=:p WHERE id=:id")
            ->execute([':c' => $colab, ':d' => $data, ':m' => $momento, ':t' => $tipo, ':o' => $obs, ':p' => ($criadoPor ?: null), ':id' => $id]);
        return $id;
    }
    $pdo->prepare("INSERT INTO ponto_batidas (colaborador_id, data, momento, tipo, origem, observacao, criado_por)
                   VALUES (:c, :d, :m, :t, 'admin', :o, :p)")
        ->execute([':c' => $colab, ':d' => $data, ':m' => $momento, ':t' => $tipo, ':o' => $obs, ':p' => ($criadoPor ?: null)]);
    return (int) $pdo->lastInsertId();
}

function ponto_batida_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM ponto_batidas WHERE id = :id")->execute([':id' => $id]);
}

// ------------------- Cálculo (dia / mês / dashboard) ------------------------

/**
 * Apura um dia a partir das batidas: pareia entrada→saída em sequência, soma o
 * trabalhado, aplica o desconto fixo do almoço (só se houve UM par e o dia
 * passou do limite) e compara com o esperado (com tolerância).
 * Retorna trabalhado_min, esperado_min, extra_min, falta_min, saldo_min, pares,
 * aberto (esqueceu de bater saída), intervalo_min (descontado).
 */
function ponto_calc_dia(array $batidas, array $jornada, int $w): array
{
    // Ordena por segurança.
    usort($batidas, function ($a, $b) { return strcmp($a['momento'], $b['momento']); });
    $bruto = 0; $pares = 0; $aberto = false; $inconsistente = false; $abertaDesde = null;
    foreach ($batidas as $b) {
        if ($b['tipo'] === 'entrada') {
            if ($abertaDesde !== null) { $inconsistente = true; /* entrada sobre entrada */ }
            $abertaDesde = strtotime($b['momento']);
        } else { // saída
            if ($abertaDesde !== null) {
                $dur = strtotime($b['momento']) - $abertaDesde;
                if ($dur > 0) { $bruto += $dur; $pares++; }
                $abertaDesde = null;
            } else {
                $inconsistente = true; // saída sem entrada (esqueceu a entrada)
            }
        }
    }
    if ($abertaDesde !== null) { $aberto = true; }
    $trabMin = (int) round($bruto / 60);

    // Desconto fixo do almoço: só quando houve exatamente 1 par e passou do limite.
    $intervalo = 0;
    $limMin = (int) round(((float) ($jornada['intervalo_limite_h'] ?? 6)) * 60);
    $descMin = (int) ($jornada['intervalo_desconto_min'] ?? 60);
    if ($pares === 1 && $descMin > 0 && $trabMin > $limMin) {
        $intervalo = min($descMin, $trabMin);
        $trabMin -= $intervalo;
    }

    $espMin = ponto_esperado_min($jornada, $w);
    $tol    = (int) ($jornada['tolerancia_min'] ?? 10);
    $saldo  = $trabMin - $espMin;
    $extra  = 0; $falta = 0;
    if ($espMin > 0) {
        if ($saldo > $tol)       { $extra = $saldo; }
        elseif ($saldo < -$tol)  { $falta = -$saldo; }
    }
    return [
        'trabalhado_min' => $trabMin,
        'esperado_min'   => $espMin,
        'extra_min'      => $extra,
        'falta_min'      => $falta,
        'saldo_min'      => $saldo,
        'pares'          => $pares,
        'aberto'         => $aberto,
        'inconsistente'  => $inconsistente,
        'intervalo_min'  => $intervalo,
        'batidas'        => $batidas,
    ];
}

/**
 * Apuração do mês de uma pessoa. Devolve os totais + o detalhe dia a dia
 * (espelho de ponto). "falta_dias" = dias com jornada esperada e zero batidas.
 */
function ponto_resumo_mes(PDO $pdo, array $pessoa, int $ano, int $mes): array
{
    $porDia = ponto_batidas_mes($pdo, (int) $pessoa['id'], $ano, $mes);
    $diasNoMes = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));
    $hoje = date('Y-m-d');

    $tot = [
        'trabalhado_min' => 0, 'esperado_min' => 0, 'extra_min' => 0,
        'falta_min' => 0, 'saldo_min' => 0, 'intervalo_min' => 0,
        'dias_trabalhados' => 0, 'falta_dias' => 0, 'abertos' => 0,
    ];
    $dias = [];
    for ($d = 1; $d <= $diasNoMes; $d++) {
        $data = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
        $w = (int) date('w', strtotime($data));
        $batidas = $porDia[$data] ?? [];
        $c = ponto_calc_dia($batidas, $pessoa, $w);
        $temBatida = count($batidas) > 0;
        $futuro = $data > $hoje;

        if ($temBatida) { $tot['dias_trabalhados']++; }
        $tot['trabalhado_min'] += $c['trabalhado_min'];
        $tot['esperado_min']   += $c['esperado_min'];
        $tot['extra_min']      += $c['extra_min'];
        $tot['intervalo_min']  += $c['intervalo_min'];
        if ($c['aberto'] || !empty($c['inconsistente'])) { $tot['abertos']++; }

        // Falta do dia: tinha jornada esperada, não é futuro e ninguém bateu.
        $faltouDia = ($c['esperado_min'] > 0 && !$temBatida && !$futuro);
        if ($faltouDia) { $tot['falta_dias']++; $tot['falta_min'] += $c['esperado_min']; }
        else            { $tot['falta_min'] += $c['falta_min']; }

        $dias[] = [
            'data' => $data, 'w' => $w, 'futuro' => $futuro,
            'falta_dia' => $faltouDia,
        ] + $c;
    }
    $tot['saldo_min'] = $tot['trabalhado_min'] - $tot['esperado_min'];
    return ['totais' => $tot, 'dias' => $dias, 'pessoa' => $pessoa];
}

/** Resumo de todas as pessoas no mês (para o dashboard/listagem). */
function ponto_dashboard(PDO $pdo, int $ano, int $mes): array
{
    $out = [];
    foreach (ponto_pessoas($pdo, true) as $p) {
        $r = ponto_resumo_mes($pdo, $p, $ano, $mes);
        $out[] = ['pessoa' => $p, 'totais' => $r['totais']];
    }
    return $out;
}

// ------------------- Formatação --------------------------------------------

/** Minutos -> "8h30" (ou "8h" / "0h"). */
function ponto_hm(int $min): string
{
    $sinal = $min < 0 ? '-' : '';
    $min = abs($min);
    $h = intdiv($min, 60); $m = $min % 60;
    return $sinal . $h . 'h' . ($m > 0 ? sprintf('%02d', $m) : '');
}

/** Minutos -> "8:30" (para exportação/contabilidade). */
function ponto_hhmm(int $min): string
{
    $sinal = $min < 0 ? '-' : '';
    $min = abs($min);
    return $sinal . intdiv($min, 60) . ':' . sprintf('%02d', $min % 60);
}

/** Nome curto do dia da semana. */
function ponto_dia_semana(int $w): string
{
    return ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][$w] ?? '';
}
