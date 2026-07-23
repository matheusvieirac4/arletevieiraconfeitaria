<?php
// ============================================================================
// Cliente da API do Cardápio Web (financeiro / contas a pagar)
//
// Endpoints NÃO documentados publicamente, mapeados via DevTools do portal.
// Autenticação: Firebase Auth (Google) — um refresh_token de vida longa é
// trocado por um id_token (JWT ~8h) que vai no header "authorization".
//
// Projetado para ser genérico/multi-tenant: nada fixo no código, tudo vem do
// array de config ($apiKey, $companyId, $refreshToken). Assim a mesma classe
// serve para vários donos de delivery que usam o Cardápio Web.
// ============================================================================

class CardapioWebApiException extends \RuntimeException {}

class CardapioWebApi
{
    private const API_BASE      = 'https://api.cardapioweb.com/api/v2';
    private const TOKEN_URL     = 'https://securetoken.googleapis.com/v1/token';
    private const PORTAL_ORIGIN = 'https://portal.cardapioweb.com';

    private string $apiKey;
    private string $companyId;
    private string $refreshToken;

    private ?string $idToken = null;
    private int $idTokenExpiresAt = 0; // timestamp unix

    public function __construct(string $apiKey, string $companyId, string $refreshToken)
    {
        $this->apiKey       = $apiKey;
        $this->companyId    = $companyId;
        $this->refreshToken = $refreshToken;
    }

    /** Cria a partir do array de config (config_financeiro.php). */
    public static function fromConfig(array $cfg): self
    {
        foreach (['firebase_api_key', 'company_id', 'refresh_token'] as $k) {
            if (empty($cfg[$k])) {
                throw new CardapioWebApiException("Config incompleta: falta '$k'.");
            }
        }
        return new self($cfg['firebase_api_key'], (string) $cfg['company_id'], $cfg['refresh_token']);
    }

    // ---------------------------------------------------------------- Auth ---

    /**
     * Devolve um id_token válido, renovando via refresh_token quando necessário.
     * Guarda em memória e reaproveita até faltar 5 min para expirar.
     */
    public function getIdToken(): string
    {
        if ($this->idToken !== null && time() < $this->idTokenExpiresAt - 300) {
            return $this->idToken;
        }

        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ]);

        [$status, $resp] = $this->curl(
            'POST',
            self::TOKEN_URL . '?key=' . urlencode($this->apiKey),
            $body,
            ['content-type: application/x-www-form-urlencoded', 'accept: */*']
        );

        $json = json_decode($resp, true);
        if ($status !== 200 || !is_array($json) || empty($json['id_token'])) {
            $msg = is_array($json) && isset($json['error']['message'])
                ? $json['error']['message'] : 'resposta inesperada';
            throw new CardapioWebApiException("Falha ao renovar token (HTTP $status): $msg");
        }

        $this->idToken = $json['id_token'];
        $expiresIn = isset($json['expires_in']) ? (int) $json['expires_in'] : 3600;
        $this->idTokenExpiresAt = time() + $expiresIn;

        return $this->idToken;
    }

    // ------------------------------------------------------------ Requests ---

    /** GET autenticado na API do Cardápio Web; devolve o JSON decodificado. */
    public function get(string $path): array
    {
        [$status, $resp] = $this->apiRequest('GET', $path);
        return $this->decode($status, $resp, "GET $path");
    }

    /** POST autenticado com corpo JSON; devolve o JSON decodificado. */
    public function post(string $path, array $payload): array
    {
        [$status, $resp] = $this->apiRequest('POST', $path, json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $this->decode($status, $resp, "POST $path");
    }

    // ----------------------------------------------------------- Recursos ---

    public function listarContas(): array           { return $this->get('/financial/accounts'); }
    public function listarCategorias(): array        { return $this->get('/financial/categories'); }
    public function listarFormasPagamento(): array   { return $this->get('/financial/payment_methods'); }
    public function listarCentrosCusto(): array      { return $this->get('/financial/cost_centers'); }
    public function listarFornecedores(): array      { return $this->get('/financial/suppliers?per_page=1000'); }

    /**
     * Importa lançamentos. $lancamentos é um array de objetos no formato do
     * Cardápio Web (chaves: account, category, value, due_date, etc.).
     * A API espera o envelope {"data":[ ... ]}.
     */
    public function importarLancamentos(array $lancamentos): array
    {
        return $this->post('/financial/transactions/import', ['data' => array_values($lancamentos)]);
    }

    // ------------------------------------------------------------ Internos ---

    private function apiRequest(string $method, string $path, ?string $jsonBody = null): array
    {
        $headers = [
            'authorization: ' . $this->getIdToken(), // JWT cru, SEM prefixo "Bearer"
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

    /** @return array{0:int,1:string} [httpStatus, responseBody] */
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
}
