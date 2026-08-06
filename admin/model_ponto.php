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
        'jornada_fixa'           => 0,
        'tolerancia_marcacao_min' => 5,
        'e_dom' => null, 's_dom' => null, 'e_seg' => null, 's_seg' => null,
        'e_ter' => null, 's_ter' => null, 'e_qua' => null, 's_qua' => null,
        'e_qui' => null, 's_qui' => null, 'e_sex' => null, 's_sex' => null,
        'e_sab' => null, 's_sab' => null,
    ];
}

/** Sufixo de coluna por dia da semana (0=dom..6=sáb). */
const PONTO_DIAS_SUF = ['dom','seg','ter','qua','qui','sex','sab'];

/** Pessoas do ponto = colaboradores + jornada (LEFT JOIN; defaults se faltar). */
function ponto_pessoas(PDO $pdo, bool $soAtivos = true): array
{
    $sql = "SELECT c.id, c.nome, c.ativo,
                   j.tipo, j.tem_meta,
                   j.h_dom, j.h_seg, j.h_ter, j.h_qua, j.h_qui, j.h_sex, j.h_sab,
                   j.intervalo_desconto_min, j.intervalo_limite_h, j.tolerancia_min,
                   j.jornada_fixa, j.tolerancia_marcacao_min,
                   j.e_dom, j.s_dom, j.e_seg, j.s_seg, j.e_ter, j.s_ter, j.e_qua, j.s_qua,
                   j.e_qui, j.s_qui, j.e_sex, j.s_sex, j.e_sab, j.s_sab
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
        foreach (['intervalo_desconto_min','tolerancia_min','jornada_fixa','tolerancia_marcacao_min'] as $k) { $r[$k] = (int) $r[$k]; }
        // Horários previstos: normaliza TIME 'HH:MM:SS' -> 'HH:MM' (ou null).
        foreach (PONTO_DIAS_SUF as $suf) {
            foreach (['e_', 's_'] as $p) {
                $r[$p . $suf] = !empty($r[$p . $suf]) ? substr((string) $r[$p . $suf], 0, 5) : null;
            }
        }
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
    $tipo = in_array($d['tipo'] ?? '', ['socio', 'freelancer', 'colaborador'], true) ? $d['tipo'] : 'freelancer';
    $temMeta = !empty($d['tem_meta']) ? 1 : 0;
    $fixa    = !empty($d['jornada_fixa']) ? 1 : 0;
    $descMin = max(0, (int) ($d['intervalo_desconto_min'] ?? 60));
    $hora = function ($v) { $v = (float) str_replace(',', '.', (string) $v); return max(0, min(24, $v)); };
    $limH  = $hora($d['intervalo_limite_h'] ?? 6);

    // Normaliza os horários previstos (HH:MM). Vazio/ inválido -> NULL.
    $time = function ($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{1,2}:\d{2}$/', $v) ? sprintf('%02d:%02d:00', ...array_map('intval', explode(':', $v))) : null;
    };
    $horarios = [];
    foreach (PONTO_DIAS_SUF as $suf) {
        $horarios['e_' . $suf] = $time($d['e_' . $suf] ?? '');
        $horarios['s_' . $suf] = $time($d['s_' . $suf] ?? '');
    }

    // Horas esperadas por dia: no modo fixo, derivam dos horários previstos
    // (saída − entrada − intervalo, quando passa do limite); senão, do input manual.
    $horas = [];
    foreach (PONTO_DIAS_SUF as $suf) {
        if ($fixa) {
            $e = $horarios['e_' . $suf]; $s = $horarios['s_' . $suf];
            if ($e && $s) {
                $mins = (strtotime($s) - strtotime($e)) / 60;
                if ($mins < 0) { $mins += 24 * 60; } // saída no dia seguinte
                if ($descMin > 0 && $mins > $limH * 60) { $mins -= $descMin; }
                $horas[$suf] = max(0.0, round($mins / 60, 2));
            } else {
                $horas[$suf] = 0.0;
            }
        } else {
            $horas[$suf] = $hora($d['h_' . $suf] ?? 0);
        }
    }

    $params = [
        ':id'   => $colaboradorId,
        ':tipo' => $tipo,
        ':meta' => $temMeta,
        ':fixa' => $fixa,
        ':tolm' => max(0, (int) ($d['tolerancia_marcacao_min'] ?? 5)),
        ':dom'  => $horas['dom'], ':seg' => $horas['seg'], ':ter' => $horas['ter'],
        ':qua'  => $horas['qua'], ':qui' => $horas['qui'], ':sex' => $horas['sex'],
        ':sab'  => $horas['sab'],
        ':desc' => $descMin,
        ':lim'  => $limH,
        ':tol'  => max(0, (int) ($d['tolerancia_min'] ?? 10)),
    ] + $horarios;
    $pdo->prepare("
        INSERT INTO ponto_jornada
            (colaborador_id, tipo, tem_meta, h_dom, h_seg, h_ter, h_qua, h_qui, h_sex, h_sab,
             intervalo_desconto_min, intervalo_limite_h, tolerancia_min,
             jornada_fixa, tolerancia_marcacao_min,
             e_dom, s_dom, e_seg, s_seg, e_ter, s_ter, e_qua, s_qua,
             e_qui, s_qui, e_sex, s_sex, e_sab, s_sab)
        VALUES (:id, :tipo, :meta, :dom, :seg, :ter, :qua, :qui, :sex, :sab, :desc, :lim, :tol,
             :fixa, :tolm,
             :e_dom, :s_dom, :e_seg, :s_seg, :e_ter, :s_ter, :e_qua, :s_qua,
             :e_qui, :s_qui, :e_sex, :s_sex, :e_sab, :s_sab)
        ON DUPLICATE KEY UPDATE
            tipo=:tipo, tem_meta=:meta, h_dom=:dom, h_seg=:seg, h_ter=:ter, h_qua=:qua,
            h_qui=:qui, h_sex=:sex, h_sab=:sab, intervalo_desconto_min=:desc,
            intervalo_limite_h=:lim, tolerancia_min=:tol,
            jornada_fixa=:fixa, tolerancia_marcacao_min=:tolm,
            e_dom=:e_dom, s_dom=:s_dom, e_seg=:e_seg, s_seg=:s_seg, e_ter=:e_ter, s_ter=:s_ter,
            e_qua=:e_qua, s_qua=:s_qua, e_qui=:e_qui, s_qui=:s_qui, e_sex=:e_sex, s_sex=:s_sex,
            e_sab=:e_sab, s_sab=:s_sab
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
    $especial = ponto_especial_do_dia($pdo, $colaboradorId, $hoje);
    $calc = ponto_calc_dia($batidas, ponto_pessoa($pdo, $colaboradorId) ?? ponto_jornada_default(), (int) date('w'), $especial);
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
function ponto_calc_dia(array $batidas, array $jornada, int $w, ?array $especial = null): array
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

    // Modo "jornada fixa": tolerância POR MARCAÇÃO (Art. 58 §1º CLT). Compara a
    // 1ª entrada e a última saída com os horários previstos; se a diferença está
    // dentro da tolerância, "encaixa" no previsto (zera a migalha). Fora dela,
    // mantém o horário real — conta o período inteiro (Súmula 366 TST).
    $fixaAplicada = false;
    if (!empty($jornada['jornada_fixa']) && $especial === null && !$aberto && $pares >= 1) {
        $suf     = PONTO_DIAS_SUF[$w] ?? 'seg';
        $entPrev = $jornada['e_' . $suf] ?? null;
        $saiPrev = $jornada['s_' . $suf] ?? null;
        if ($entPrev && $saiPrev) {
            $tolSeg  = max(0, (int) ($jornada['tolerancia_marcacao_min'] ?? 5)) * 60;
            $dia0    = substr($batidas[0]['momento'], 0, 10);
            $prevEnt = strtotime($dia0 . ' ' . $entPrev);
            $prevSai = strtotime($dia0 . ' ' . $saiPrev);
            $realEnt = null; $realSai = null;
            foreach ($batidas as $b) {
                if ($b['tipo'] === 'entrada' && $realEnt === null) { $realEnt = strtotime($b['momento']); }
                if ($b['tipo'] === 'saida') { $realSai = strtotime($b['momento']); }
            }
            // Entrada dentro da tolerância -> encaixa no previsto (remove a migalha).
            if ($realEnt !== null && abs($realEnt - $prevEnt) <= $tolSeg) {
                $trabMin -= (int) round(($prevEnt - $realEnt) / 60);
            }
            // Saída dentro da tolerância -> encaixa no previsto.
            if ($realSai !== null && abs($realSai - $prevSai) <= $tolSeg) {
                $trabMin -= (int) round(($realSai - $prevSai) / 60);
            }
            $trabMin = max(0, $trabMin);
            $fixaAplicada = true;
        }
    }

    if ($especial !== null) {
        // Feriado / folga: esperado 0. Se trabalhou, tudo vira banco de horas
        // (extra); se não trabalhou, dia neutro (sem falta).
        $espMin = 0; $extra = $trabMin; $falta = 0; $saldo = $trabMin;
    } else {
        $espMin = ponto_esperado_min($jornada, $w);
        // No modo fixo a tolerância já foi aplicada por marcação: qualquer
        // diferença restante conta. Senão, tolerância sobre o saldo líquido do dia.
        $tol    = $fixaAplicada ? 0 : (int) ($jornada['tolerancia_min'] ?? 10);
        $saldo  = $trabMin - $espMin;
        $extra  = 0; $falta = 0;
        if ($espMin > 0) {
            if ($saldo > $tol)       { $extra = $saldo; }
            elseif ($saldo < -$tol)  { $falta = -$saldo; }
        }
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
        'especial'       => $especial,   // ['tipo','descricao'] ou null
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
    $especiais = ponto_especiais_mes($pdo, (int) $pessoa['id'], $ano, $mes);
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
        $c = ponto_calc_dia($batidas, $pessoa, $w, $especiais[$data] ?? null);
        $temBatida = count($batidas) > 0;
        $futuro   = $data > $hoje;
        $ehHoje   = $data === $hoje;
        // Só dias FECHADOS (anteriores a hoje) entram no fechamento do mês.
        // Hoje ainda está correndo e o futuro não chegou — não geram esperado
        // nem falta (senão o mês inteiro viraria "falta" logo no começo).
        $fechado  = (!$futuro && !$ehHoje);

        $faltouDia = false;
        if ($fechado) {
            if ($temBatida) { $tot['dias_trabalhados']++; }
            $tot['trabalhado_min'] += $c['trabalhado_min'];
            $tot['esperado_min']   += $c['esperado_min'];
            $tot['extra_min']      += $c['extra_min'];
            $tot['intervalo_min']  += $c['intervalo_min'];
            if ($c['aberto'] || !empty($c['inconsistente'])) { $tot['abertos']++; }

            $faltouDia = ($c['esperado_min'] > 0 && !$temBatida);
            if ($faltouDia) { $tot['falta_dias']++; $tot['falta_min'] += $c['esperado_min']; }
            else            { $tot['falta_min'] += $c['falta_min']; }
        } elseif ($ehHoje) {
            // Hoje ainda está correndo: não entra no fechamento (trabalhado/
            // esperado/falta) para o saldo continuar consistente. Uma entrada
            // aberta HOJE é normal (a pessoa está trabalhando) — não é
            // pendência; só marca se as batidas estiverem inconsistentes.
            if (!empty($c['inconsistente'])) { $tot['abertos']++; }
        }

        $dias[] = [
            'data' => $data, 'w' => $w, 'futuro' => $futuro,
            'ehHoje' => $ehHoje, 'fechado' => $fechado, 'falta_dia' => $faltouDia,
        ] + $c;
    }
    // Banco de horas = só o que passou pela tolerância dia a dia (extra − falta).
    // NÃO usar trabalhado−esperado cru aqui: isso reintroduziria as "migalhas"
    // de poucos minutos que a tolerância (Art. 58 §1º CLT) já zerou por dia.
    $tot['saldo_min'] = $tot['extra_min'] - $tot['falta_min'];
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

// ------------------- Dias especiais (feriados / folgas) ---------------------
// Um dia especial zera a jornada esperada da pessoa nesse dia (não vira falta).
// Escopo: colaborador_id NULL = vale para TODOS; senão, só para aquela pessoa.
// Trabalhar num dia especial cai no banco de horas (extra) — ver ponto_calc_dia.

/** A tabela de dias especiais já existe? (setup cria; degrada sem ela). */
function ponto_tem_especiais(PDO $pdo): bool
{
    static $tem = null;
    if ($tem === null) {
        try { $pdo->query("SELECT 1 FROM ponto_dias_especiais LIMIT 1"); $tem = true; }
        catch (\Throwable $e) { $tem = false; }
    }
    return $tem;
}

/** Mapa data => ['tipo','descricao'] no mês (globais + da pessoa). Pessoa vence. */
function ponto_especiais_mes(PDO $pdo, int $colaboradorId, int $ano, int $mes): array
{
    if (!ponto_tem_especiais($pdo)) { return []; }
    $ini = sprintf('%04d-%02d-01', $ano, $mes);
    $fim = date('Y-m-d', strtotime($ini . ' +1 month'));
    $st = $pdo->prepare("SELECT data, tipo, descricao, colaborador_id
                         FROM ponto_dias_especiais
                         WHERE data >= :i AND data < :f
                           AND (colaborador_id IS NULL OR colaborador_id = :c)
                         ORDER BY (colaborador_id IS NULL) DESC");  // globais 1º, pessoa sobrescreve
    $st->execute([':i' => $ini, ':f' => $fim, ':c' => $colaboradorId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['data']] = ['tipo' => $r['tipo'], 'descricao' => $r['descricao'],
                            'pessoal' => $r['colaborador_id'] !== null];
    }
    return $out;
}

/** Dia especial de UMA pessoa numa data (ou null). Pessoa vence global. */
function ponto_especial_do_dia(PDO $pdo, int $colaboradorId, string $data): ?array
{
    if (!ponto_tem_especiais($pdo)) { return null; }
    $st = $pdo->prepare("SELECT tipo, descricao, colaborador_id
                         FROM ponto_dias_especiais
                         WHERE data = :d AND (colaborador_id IS NULL OR colaborador_id = :c)
                         ORDER BY (colaborador_id IS NULL) ASC LIMIT 1");  // pessoal 1º
    $st->execute([':d' => $data, ':c' => $colaboradorId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['tipo' => $r['tipo'], 'descricao' => $r['descricao'], 'pessoal' => $r['colaborador_id'] !== null] : null;
}

/** Lista dias especiais de um ano (para a tela de gestão), com nome da pessoa. */
function ponto_especiais_listar(PDO $pdo, int $ano): array
{
    if (!ponto_tem_especiais($pdo)) { return []; }
    $st = $pdo->prepare("SELECT e.*, c.nome AS pessoa
                         FROM ponto_dias_especiais e
                         LEFT JOIN estoque_colaboradores c ON c.id = e.colaborador_id
                         WHERE e.data >= :i AND e.data < :f
                         ORDER BY e.data, pessoa");
    $st->execute([':i' => "$ano-01-01", ':f' => ($ano + 1) . "-01-01"]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Cria um dia especial. $colaboradorId null = todos. */
function ponto_especial_salvar(PDO $pdo, string $data, ?int $colaboradorId, string $tipo, string $descricao): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) { throw new InvalidArgumentException('Data inválida.'); }
    $tipos = ['feriado', 'folga', 'abono'];
    if (!in_array($tipo, $tipos, true)) { $tipo = 'feriado'; }
    $pdo->prepare("INSERT INTO ponto_dias_especiais (data, colaborador_id, tipo, descricao)
                   VALUES (:d, :c, :t, :desc)")
        ->execute([':d' => $data, ':c' => $colaboradorId, ':t' => $tipo, ':desc' => trim($descricao) ?: null]);
}

function ponto_especial_excluir(PDO $pdo, int $id): void
{
    $pdo->prepare("DELETE FROM ponto_dias_especiais WHERE id = :id")->execute([':id' => $id]);
}

/** Domingo de Páscoa (algoritmo de Meeus/Gauss) — base dos feriados móveis. */
function ponto_pascoa(int $ano): string
{
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100;
    $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

/** Feriados nacionais do ano (fixos + móveis pela Páscoa). data => nome. */
function ponto_feriados_nacionais(int $ano): array
{
    $pascoa = strtotime(ponto_pascoa($ano));
    $mov = function ($dias) use ($pascoa) { return date('Y-m-d', strtotime("$dias day", $pascoa)); };
    return [
        "$ano-01-01" => 'Confraternização Universal',
        $mov(-48)    => 'Carnaval (segunda) — facultativo',
        $mov(-47)    => 'Carnaval (terça) — facultativo',
        $mov(-46)    => 'Quarta-feira de Cinzas — facultativo',
        $mov(-2)     => 'Sexta-feira Santa',
        "$ano-04-21" => 'Tiradentes',
        "$ano-05-01" => 'Dia do Trabalho',
        $mov(60)     => 'Corpus Christi — facultativo',
        "$ano-09-07" => 'Independência do Brasil',
        "$ano-10-12" => 'Nossa Senhora Aparecida',
        "$ano-11-02" => 'Finados',
        "$ano-11-15" => 'Proclamação da República',
        "$ano-11-20" => 'Consciência Negra',
        "$ano-12-25" => 'Natal',
    ];
}

/** Importa os feriados nacionais do ano como globais (idempotente). Retorna nº novos. */
function ponto_feriados_importar(PDO $pdo, int $ano): int
{
    if (!ponto_tem_especiais($pdo)) { throw new RuntimeException('Rode o ponto_setup.php primeiro.'); }
    $existe = $pdo->prepare("SELECT 1 FROM ponto_dias_especiais WHERE data = :d AND colaborador_id IS NULL LIMIT 1");
    $ins = $pdo->prepare("INSERT INTO ponto_dias_especiais (data, colaborador_id, tipo, descricao)
                          VALUES (:d, NULL, 'feriado', :desc)");
    $n = 0;
    foreach (ponto_feriados_nacionais($ano) as $data => $nome) {
        $existe->execute([':d' => $data]);
        if ($existe->fetchColumn()) { continue; }
        $ins->execute([':d' => $data, ':desc' => $nome]);
        $n++;
    }
    return $n;
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

/** Rótulo + cor do badge por tipo de pessoa: [texto, cor-bootstrap]. */
function ponto_tipo_info(string $tipo): array
{
    switch ($tipo) {
        case 'socio':       return ['Sócio', 'info'];
        case 'colaborador': return ['Colaborador', 'primary'];
        default:            return ['Freelancer', 'secondary'];
    }
}
