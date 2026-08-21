<?php
// ============================================================================
// Cliente da API do Cardápio Web (financeiro / contas a pagar)
//
// Endpoints NÃO documentados publicamente, mapeados via DevTools do portal.
//
// Autenticação (descoberta por engenharia reversa, 2026-07-23):
//   - Login (1x, manual): POST dashboard.cardapioweb.com/api/v2/auth/token
//     com grant_type=user_credentials (email+senha+recaptcha) -> devolve
//     access_token (8h) + refresh_token (5 dias).
//   - Renovação (automática): mesmo endpoint, grant_type=refresh_token +
//     refresh_token -> novo access_token E NOVO refresh_token (ROTAÇÃO).
//     Por isso o refresh_token precisa ser PERSISTIDO a cada renovação.
//   - Chamadas da API: header "authorization" = access_token (JWT cru,
//     sem "Bearer") + header "companyid".
//
// Projetado para ser genérico/multi-tenant: nada fixo no código.
// ============================================================================

class CardapioWebApiException extends \RuntimeException {}

class CardapioWebApi
{
    private const API_BASE      = 'https://api.cardapioweb.com/api/v2';
    private const AUTH_URL      = 'https://dashboard.cardapioweb.com/api/v2/auth/token';
    private const ORDERS_URL    = 'https://dashboard.cardapioweb.com/api/v1/company/orders';
    private const PORTAL_ORIGIN = 'https://portal.cardapioweb.com';

    private string $companyId;
    private string $seedRefreshToken;
    private ?string $statePath;

    // cache em memória (dentro de uma mesma execução)
    private ?string $accessToken = null;
    private int $accessExpiresAt = 0;

    /**
     * @param string      $companyId        header companyid (ex.: "24945")
     * @param string      $refreshToken     refresh_token do CW (semente inicial)
     * @param string|null $statePath        arquivo JSON onde o refresh_token
     *                                       rotacionado é persistido. Se null,
     *                                       a rotação vive só em memória (some ao
     *                                       fim da execução — só p/ testes).
     */
    public function __construct(string $companyId, string $refreshToken, ?string $statePath = null)
    {
        $this->companyId        = $companyId;
        $this->seedRefreshToken = $refreshToken;
        $this->statePath        = $statePath;
    }

    public static function fromConfig(array $cfg, ?string $statePath = null): self
    {
        foreach (['company_id', 'refresh_token'] as $k) {
            if (empty($cfg[$k])) {
                throw new CardapioWebApiException("Config incompleta: falta '$k'.");
            }
        }
        return new self((string) $cfg['company_id'], $cfg['refresh_token'], $statePath);
    }

    // ---------------------------------------------------------------- Auth ---

    /** Devolve um access_token válido, renovando (e rotacionando) quando preciso. */
    public function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessExpiresAt - 300) {
            return $this->accessToken;
        }

        $state = $this->loadState();
        // Se a config mudou (usuário colou um refresh novo), reinicia do zero.
        $seguirState = $state && ($state['seed'] ?? null) === $this->seedRefreshToken;

        if ($seguirState
            && !empty($state['access_token'])
            && time() < (int) ($state['access_expires_at'] ?? 0) - 300) {
            $this->accessToken     = $state['access_token'];
            $this->accessExpiresAt = (int) $state['access_expires_at'];
            return $this->accessToken;
        }

        $currentRefresh = $seguirState ? ($state['refresh_token'] ?? $this->seedRefreshToken)
                                       : $this->seedRefreshToken;

        [$status, $resp] = $this->curl(
            'POST',
            self::AUTH_URL,
            json_encode(['grant_type' => 'refresh_token', 'refresh_token' => $currentRefresh]),
            ['content-type: application/json', 'accept: application/json', 'companyid: ' . $this->companyId,
             'origin: ' . self::PORTAL_ORIGIN, 'referer: ' . self::PORTAL_ORIGIN . '/']
        );

        $json = json_decode($resp, true);
        if ($status !== 200 || empty($json['access_token'])) {
            $msg = is_array($json) && isset($json['message']) ? $json['message'] : substr($resp, 0, 200);
            throw new CardapioWebApiException(
                "Falha ao renovar o token (HTTP $status): $msg. " .
                "Se o refresh_token expirou (5 dias), refaça o login no portal e atualize a config."
            );
        }

        $this->accessToken     = $json['access_token'];
        $this->accessExpiresAt = time() + (int) ($json['access_token_expires_in'] ?? 28800);

        // Rotação: guarda o NOVO refresh_token para a próxima renovação.
        $this->saveState([
            'seed'              => $this->seedRefreshToken,
            'refresh_token'     => $json['refresh_token'] ?? $currentRefresh,
            'access_token'      => $this->accessToken,
            'access_expires_at' => $this->accessExpiresAt,
            'atualizado_em'     => date('c'),
        ]);

        return $this->accessToken;
    }

    // ------------------------------------------------------------ Requests ---

    public function get(string $path): array
    {
        [$status, $resp] = $this->apiRequest('GET', $path);
        return $this->decode($status, $resp, "GET $path");
    }

    public function post(string $path, array $payload): array
    {
        [$status, $resp] = $this->apiRequest('POST', $path, json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $this->decode($status, $resp, "POST $path");
    }

    public function put(string $path, array $payload): array
    {
        [$status, $resp] = $this->apiRequest('PUT', $path, json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $this->decode($status, $resp, "PUT $path");
    }

    // ----------------------------------------------------------- Recursos ---

    public function listarContas(): array          { return $this->get('/financial/accounts'); }
    public function listarCategorias(): array       { return $this->get('/financial/categories'); }
    public function listarFormasPagamento(): array  { return $this->get('/financial/payment_methods'); }
    public function listarCentrosCusto(): array     { return $this->get('/financial/cost_centers'); }
    public function listarFornecedores(): array     { return $this->get('/financial/suppliers?per_page=1000'); }

    /**
     * Lista pedidos da loja (para os alertas de despacho de entregas).
     * Endpoint mapeado por DevTools (2026-08-21): fica em OUTRO host/versão
     * (dashboard.cardapioweb.com/api/v1) — por isso não usa apiRequest().
     * Resposta: array de pedidos com order_type, status, scheduled_date,
     * scheduled_period, client_name, estimated_time, etc.
     *
     * @param array $query filtros opcionais de querystring
     */
    public function listarPedidos(array $query = []): array
    {
        $url = self::ORDERS_URL . ($query ? ('?' . http_build_query($query)) : '');
        $headers = [
            'authorization: ' . $this->getAccessToken(),   // JWT cru, SEM "Bearer"
            'companyid: ' . $this->companyId,
            'accept: application/json, text/plain, */*',
            'origin: ' . self::PORTAL_ORIGIN,
            'referer: ' . self::PORTAL_ORIGIN . '/',
        ];
        [$status, $resp] = $this->curl('GET', $url, null, $headers);
        return $this->decode($status, $resp, 'GET company/orders');
    }

    /** Importa lançamentos no formato do CW. Envelope esperado: {"data":[...]}. */
    public function importarLancamentos(array $lancamentos): array
    {
        return $this->post('/financial/transactions/import', ['data' => array_values($lancamentos)]);
    }

    /**
     * Lista lançamentos (contas a pagar/receber). Usa filtros ransack: passe
     * ['q' => ['activity_type_eq' => 'out', 'due_date_gteq' => 'YYYY-MM-DD', ...]].
     */
    public function listarTransacoes(array $query): array
    {
        return $this->get('/financial/transactions?' . http_build_query($query));
    }

    /** Lê uma transação (com os IDs de conta/fornecedor/categoria) para edição. */
    public function obterTransacao(int $id): array
    {
        return $this->get('/financial/transactions/' . $id);
    }

    /**
     * Atualiza o VALOR-BASE (original_value) de uma transação existente,
     * preservando todo o resto. Reaproveita $atual (resposta de obterTransacao)
     * para reenviar os mesmos IDs e a MESMA linha de categoria (com seu id),
     * senão o CW criaria uma categoria nova. Só trata categoria única — split
     * de categorias não é mexido (lança), para nunca corromper o rateio.
     */
    public function atualizarValorTransacao(int $id, array $atual, float $novoValorPositivo): array
    {
        $val  = -abs($novoValorPositivo);   // despesa: negativo
        $cats = $atual['categories'] ?? [];
        if (count($cats) !== 1) {
            throw new CardapioWebApiException('A conta tem mais de uma categoria (rateio) — ajuste o valor direto no Cardápio Web.');
        }
        $c = $cats[0];
        $t = [
            'activity_type'         => $atual['activity_type'] ?? 'out',
            'due_date'              => $atual['due_date'] ?? null,
            'competence_date'       => $atual['competence_date'] ?? null,
            'original_value'        => $val,
            'description'           => $atual['description'] ?? '',
            'fin_account_id'        => $atual['fin_account_id'] ?? null,
            'fin_supplier_id'       => $atual['fin_supplier_id'] ?? null,
            'fin_payment_method_id' => $atual['fin_payment_method_id'] ?? null,
            'settlement_date'       => $atual['settlement_date'] ?? null,
            'fee'                   => null,
            'fine'                  => null,
            'interest'              => null,
            'discount'              => null,
            'status'                => $atual['status'] ?? 'pending',
            'notes'                 => $atual['notes'] ?? null,
            'recurrence_type'       => $atual['recurrence_type'] ?? 'single',
            'fin_transaction_categories_attributes' => [[
                'fin_category_id'    => $c['fin_category_id']    ?? ($c['fin_category']['id']    ?? null),
                'fin_cost_center_id' => $c['fin_cost_center_id'] ?? ($c['fin_cost_center']['id'] ?? null),
                'value'              => $val,
                'id'                 => $c['id'] ?? null,
            ]],
        ];
        return $this->put('/financial/transactions/' . $id, ['transaction' => $t]);
    }

    /**
     * Dá baixa (marca como paga) numa conta a pagar existente.
     * PUT /financial/transactions/{id}/pay — o valor da conta NÃO muda aqui;
     * só entram conta, forma, data e, se houver, juros/multa/desconto.
     */
    public function pagarTransacao(int $id, int $contaId, int $formaId, string $settlementDate, array $extra = []): array
    {
        return $this->put('/financial/transactions/' . $id . '/pay', [
            'transaction' => [
                'fin_account_id'        => $contaId,
                'fin_payment_method_id' => $formaId,
                'settlement_date'       => $settlementDate,
                'fee'                   => $extra['fee']      ?? null,
                'fine'                  => $extra['fine']     ?? null,
                'interest'              => $extra['interest'] ?? null,
                'discount'              => $extra['discount'] ?? null,
            ],
        ]);
    }

    /** Cria um fornecedor com CNPJ/CPF (para casamento futuro por documento). */
    public function criarFornecedor(string $nome, string $documento): array
    {
        $doc = preg_replace('/\D/', '', $documento);
        $tipo = strlen($doc) === 11 ? 'cpf' : 'cnpj';
        return $this->post('/financial/suppliers', [
            'supplier' => [
                'name'          => $nome,
                'kind'          => 'supplier',
                'active'        => true,
                'document_type' => $tipo,
                'document'      => $doc,
                'phone_number'  => null,
                'email'         => null,
                'company_name'  => null,
                'pix_key_type'  => null,
                'pix_key'       => null,
                'notes'         => null,
            ],
        ]);
    }

    /**
     * Atualiza um fornecedor existente (PUT /financial/suppliers/{id}).
     * Preserva os campos que já vieram no cadastro ($existente) e só sobrescreve
     * o documento — assim não zera email/pix/telefone que a API reenvia no corpo.
     */
    public function atualizarFornecedor(int $id, array $existente, string $documento): array
    {
        $doc  = preg_replace('/\D/', '', $documento);
        $tipo = strlen($doc) === 11 ? 'cpf' : 'cnpj';
        $campos = ['name', 'kind', 'active', 'phone_number', 'email', 'company_name', 'pix_key_type', 'pix_key', 'notes'];
        $supplier = [];
        foreach ($campos as $c) {
            $supplier[$c] = $existente[$c] ?? null;
        }
        if (empty($supplier['kind']))   { $supplier['kind'] = 'supplier'; }
        if (!isset($existente['active'])) { $supplier['active'] = true; }
        $supplier['document_type'] = $tipo;
        $supplier['document']      = $doc;
        return $this->put('/financial/suppliers/' . $id, ['supplier' => $supplier]);
    }

    // ------------------------------------------------------------ Internos ---

    private function apiRequest(string $method, string $path, ?string $jsonBody = null): array
    {
        $headers = [
            'authorization: ' . $this->getAccessToken(), // JWT cru, SEM "Bearer"
            'companyid: ' . $this->companyId,
            'accept: application/json, text/plain, */*',
            'origin: ' . self::PORTAL_ORIGIN,
            'referer: ' . self::PORTAL_ORIGIN . '/',
        ];
        if ($jsonBody !== null) {
            $headers[] = 'content-type: application/json';
        }
        return $this->curl($method, self::API_BASE . $path, $jsonBody, $headers);
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new CardapioWebApiException("Erro de conexão: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string) $resp];
    }

    private function decode(int $status, string $resp, string $ctx): array
    {
        $json = json_decode($resp, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($json) && isset($json['message']) ? $json['message'] : substr($resp, 0, 300);
            throw new CardapioWebApiException("$ctx falhou (HTTP $status): $detail");
        }
        return is_array($json) ? $json : [];
    }

    // ---- Estado (persistência do refresh_token rotacionado) ----

    private function loadState(): ?array
    {
        if (!$this->statePath || !is_file($this->statePath)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->statePath), true);
        return is_array($data) ? $data : null;
    }

    private function saveState(array $state): void
    {
        if (!$this->statePath) {
            return;
        }
        $dir = dirname($this->statePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
