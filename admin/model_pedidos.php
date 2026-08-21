<?php
// ============================================================================
// Alertas de despacho de entregas (Cardápio Web).
//
// O quiosque (admin/pedidos_kiosk.php) faz polling em admin/pedidos_api.php,
// que chama estas funções. A ideia: varrer os pedidos do Cardápio Web, ficar
// só com os de ENTREGA que ainda vão sair, e para cada um calcular quantos
// minutos faltam para o DESPACHO (sair da loja). O quiosque toca o alarme
// quando cruza os limiares configurados (padrão 30 e 5 min antes).
//
// Toda a dependência do formato do Cardápio Web está em UMA função:
// pedidos_normalizar_cw() — o resto trabalha sobre a forma normalizada.
// ============================================================================

require_once __DIR__ . '/model_financeiro.php';   // financeiro_api(), financeiro_config()

/**
 * Config dos alertas, com defaults. Chaves OPCIONAIS em config_financeiro.php:
 *   'alertas_avisos_min'   => [30, 5]  // limiares (min antes do despacho)
 *   'alertas_janela_min'   => 180      // só mostra entregas até X min à frente
 *   'alertas_deslocamento_min' => 0    // despacho = entrega - deslocamento
 *   'alertas_status_entrega'   => []   // status do CW que contam como "a despachar"
 */
function pedidos_alertas_config(): array
{
    $cfg = financeiro_config() ?: [];
    $avisos = $cfg['alertas_avisos_min'] ?? [30, 5];
    if (is_string($avisos)) {
        $avisos = array_map('intval', array_filter(array_map('trim', explode(',', $avisos)), 'strlen'));
    }
    $avisos = array_values(array_filter(array_map('intval', (array) $avisos), fn($v) => $v > 0));
    rsort($avisos);                       // maior primeiro (30 antes de 5)
    if (!$avisos) { $avisos = [30, 5]; }

    return [
        'avisos_min'       => $avisos,
        'janela_min'       => (int) ($cfg['alertas_janela_min'] ?? 180),
        // Deslocamento padrão quando o pedido não traz estimated_time. O horário
        // agendado é a hora de CHEGAR no cliente; despacho = chegada - deslocamento.
        'deslocamento_min' => (int) ($cfg['alertas_deslocamento_min'] ?? 15),
        'status_entrega'   => (array) ($cfg['alertas_status_entrega'] ?? []),
    ];
}

/**
 * Devolve as entregas a despachar já normalizadas e com o cálculo de tempo.
 * $agora = timestamp de referência (default: agora). $mock força dados falsos.
 * $janelaOverride = quando informado, sobrepõe alertas_janela_min (útil só p/
 * conferência: ver as entregas mais adiante do dia sem esperar o horário).
 *
 * @return array{agora:string, config:array, pedidos:array<int,array>}
 */
function pedidos_alertas(bool $mock = false, ?int $agora = null, ?int $janelaOverride = null): array
{
    $agora = $agora ?? time();
    $cfg   = pedidos_alertas_config();
    if ($janelaOverride !== null && $janelaOverride > 0) {
        $cfg['janela_min'] = $janelaOverride;
    }

    $brutos = $mock ? pedidos_mock($agora) : pedidos_buscar_entregas_cw($cfg);

    $pedidos = [];
    foreach ($brutos as $p) {
        // Despacho = horário de entrega menos o deslocamento (por pedido se houver,
        // senão o default da config).
        $desloc     = $p['deslocamento_min'] ?? null;
        if ($desloc === null) { $desloc = $cfg['deslocamento_min']; }
        $despachoTs = $p['entrega_ts'] - $desloc * 60;
        $restam     = (int) floor(($despachoTs - $agora) / 60);

        // Fora da janela (muito no futuro) ou já bem atrasado demais: ignora.
        if ($restam > $cfg['janela_min']) { continue; }
        if ($restam < -30)                { continue; }

        // Qual limiar já foi cruzado (o mais "urgente" que se aplica).
        // Ex.: avisos [30,5]; se restam 27 -> estágio 30; se restam 3 -> estágio 5.
        $estagio = null;
        foreach ($cfg['avisos_min'] as $lim) {   // já vem ordenado desc
            if ($restam <= $lim) { $estagio = $lim; }
        }

        $pedidos[] = [
            'id'          => (string) $p['id'],
            'cliente'     => $p['cliente'],
            'resumo'      => $p['resumo'],
            'endereco'    => $p['endereco'],
            'entrega_em'  => pedidos_iso($p['entrega_ts']),
            'despacho_em' => pedidos_iso($despachoTs),
            'restam_min'  => $restam,
            'estagio'     => $estagio,          // null = ainda longe do 1º aviso
            'atrasado'    => $restam < 0,
        ];
    }

    // Ordena por urgência (menos minutos restantes primeiro).
    usort($pedidos, fn($a, $b) => $a['restam_min'] <=> $b['restam_min']);

    return [
        'agora'   => pedidos_iso($agora),
        'config'  => ['avisos_min' => $cfg['avisos_min'], 'janela_min' => $cfg['janela_min']],
        'pedidos' => $pedidos,
    ];
}

/** ISO 8601 de um timestamp no fuso da confeitaria (offset -03:00). */
function pedidos_iso(int $ts): string
{
    return (new DateTime('@' . $ts))->setTimezone(pedidos_tz())->format('c');
}

/**
 * Busca os pedidos de ENTREGA no Cardápio Web e normaliza.
 * PONTO ÚNICO que depende do formato real da API — ajustar em
 * pedidos_normalizar_cw() após capturar 1 pedido real no DevTools.
 *
 * @return array<int,array{id:mixed,cliente:string,resumo:string,endereco:string,entrega_ts:int}>
 */
function pedidos_buscar_entregas_cw(array $cfg): array
{
    $api  = financeiro_api();                 // lança se não configurado
    $resp = $api->listarPedidos();            // ver CardapioWebApi::listarPedidos()

    $lista = $resp['data'] ?? $resp['orders'] ?? $resp;
    if (!is_array($lista)) { return []; }

    $out = [];
    foreach ($lista as $raw) {
        if (!is_array($raw)) { continue; }
        $n = pedidos_normalizar_cw($raw, $cfg);
        if ($n !== null) { $out[] = $n; }
    }
    return $out;
}

/** Fuso da confeitaria — o scheduled_date/period vêm em horário de Brasília. */
function pedidos_tz(): DateTimeZone { return new DateTimeZone('America/Sao_Paulo'); }

/**
 * Converte UM pedido cru do Cardápio Web para a forma normalizada.
 * Devolve null se NÃO for entrega a despachar (retirada, sem horário agendado,
 * cancelado, já em rota/entregue, etc.).
 *
 * Campos reais (GET dashboard.cardapioweb.com/api/v1/company/orders):
 *   order_type ("delivery"|"takeout"), status, order_timing ("scheduled"|
 *   "immediate"), scheduled_date ("YYYY-MM-DD"), scheduled_period ("HH:MM-HH:MM"),
 *   estimated_time (min, opcional), client_name, order_number, id.
 */
function pedidos_normalizar_cw(array $o, array $cfg): ?array
{
    // 1) Só ENTREGA. Retirada no balcão (takeout)/mesa ficam de fora.
    if (($o['order_type'] ?? '') !== 'delivery') { return null; }

    // 2) Status ainda "a despachar" — ignora terminais / já em rota.
    $status = strtolower((string) ($o['status'] ?? ''));
    if ($cfg['status_entrega']) {
        // Se o lojista definiu a whitelist, respeita só ela.
        if ($status !== '' && !in_array($status, array_map('strtolower', $cfg['status_entrega']), true)) {
            return null;
        }
    } else {
        $encerrados = ['delivered', 'canceled', 'cancelled', 'concluded', 'done', 'finished',
                       'finalizado', 'entregue', 'cancelado', 'rejected', 'refused',
                       'dispatched', 'on_the_way', 'in_delivery', 'out_for_delivery',
                       'shipped', 'em_rota', 'em_entrega'];
        if (in_array($status, $encerrados, true)) { return null; }
    }

    // 3) Horário AGENDADO: data + início da janela (ex.: "09:00-09:15" -> "09:00").
    // Pedidos imediatos (order_timing="immediate", sem scheduled_date) ficam de
    // fora por ora — a equipe já age neles na hora; não há horário-alvo p/ contar.
    $date   = (string) ($o['scheduled_date'] ?? '');
    $period = (string) ($o['scheduled_period'] ?? '');
    if ($date === '' || $period === '') { return null; }
    $ini = trim(explode('-', $period)[0]);
    if (!preg_match('/^\d{1,2}:\d{2}$/', $ini)) { return null; }
    $dt = DateTime::createFromFormat('Y-m-d H:i', "$date $ini", pedidos_tz());
    if (!$dt) { return null; }
    $ts = $dt->getTimestamp();

    // 4) Deslocamento por pedido: usa estimated_time (min) quando o CW informar.
    $desloc = null;
    if (isset($o['estimated_time']) && is_numeric($o['estimated_time']) && $o['estimated_time'] > 0) {
        $desloc = (int) $o['estimated_time'];
    }

    $num = $o['order_number'] ?? $o['id'] ?? '';
    return [
        'id'               => $o['id'] ?? $num ?: uniqid(),
        'cliente'          => (string) ($o['client_name'] ?? 'Cliente') ?: 'Cliente',
        'resumo'           => $num !== '' ? ('Pedido #' . $num) : '',
        'endereco'         => '',                 // não vem na lista; acompanha no CW
        'entrega_ts'       => $ts,
        'deslocamento_min' => $desloc,           // null = usa o default da config
    ];
}

/** Dados falsos p/ testar o quiosque/alarme sem depender da API. */
function pedidos_mock(int $agora): array
{
    // Uma entrega logo (dispara o aviso de 5 min), outra em ~28 min (aviso 30),
    // e uma daqui a 1h30 (ainda longe, só aparece no painel).
    return [
        ['id' => 'M1', 'cliente' => 'Ana Paula',   'resumo' => 'Pedido #1042',
         'endereco' => 'Rua das Flores, 120 - Centro', 'entrega_ts' => $agora + 4 * 60],
        ['id' => 'M2', 'cliente' => 'Carlos Souza', 'resumo' => 'Pedido #1043',
         'endereco' => 'Av. Brasil, 890 - Jardim',     'entrega_ts' => $agora + 28 * 60],
        ['id' => 'M3', 'cliente' => 'Mariana Lima', 'resumo' => 'Pedido #1044',
         'endereco' => 'Rua XV, 45 - Vila Nova',       'entrega_ts' => $agora + 90 * 60],
    ];
}
