<?php
// ============================================================================
// Cliente do webservice NFeDistribuicaoDFe (Ambiente Nacional) — modo NSU.
// Distribui automaticamente as NF-e (modelo 55) emitidas contra um CNPJ,
// autenticando com certificado digital A1 (.pfx/.p12) via TLS mútuo.
//
// O "resumo" (resNFe) já traz emitente, valor total (vNF) e data — suficiente
// para lançar uma conta a pagar. Se vier o XML completo (procNFe), melhor ainda.
// ============================================================================

class SefazException extends \RuntimeException {}

class SefazDistribuicao
{
    private const ENDPOINT = 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx';

    private string $cnpj;
    private string $cUF;
    private int $tpAmb;
    private string $certPem;
    private string $keyPem;

    /**
     * @param string $pfxBinario conteúdo do .pfx/.p12
     * @param string $senha      senha do certificado
     * @param string $cnpj       CNPJ consultante (vazio = extrai do certificado)
     * @param string $cUF        UF do autor (vazio = usa a do certificado/SC=42)
     */
    public function __construct(string $pfxBinario, string $senha, string $cnpj = '', string $cUF = '42', int $tpAmb = 1)
    {
        if (!openssl_pkcs12_read($pfxBinario, $certs, $senha)) {
            $e = ''; while ($m = openssl_error_string()) { $e .= $m . ' '; }
            throw new SefazException('Não foi possível abrir o certificado (senha ou algoritmo). ' . $e);
        }
        if ($cnpj === '') {
            $info = openssl_x509_parse($certs['cert']);
            if (preg_match('/(\d{14})/', $info['subject']['CN'] ?? '', $mm)) { $cnpj = $mm[1]; }
        }
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14) {
            throw new SefazException('CNPJ do consultante não determinado.');
        }
        $this->cnpj = $cnpj;
        $this->cUF  = $cUF;
        $this->tpAmb = $tpAmb;

        $this->certPem = tempnam(sys_get_temp_dir(), 'sfz');
        $this->keyPem  = tempnam(sys_get_temp_dir(), 'sfz');
        file_put_contents($this->certPem, $certs['cert']);
        file_put_contents($this->keyPem, $certs['pkey']);
    }

    public function __destruct()
    {
        @unlink($this->certPem);
        @unlink($this->keyPem);
    }

    public function cnpj(): string { return $this->cnpj; }

    /**
     * Consulta a distribuição a partir de um NSU. Devolve:
     *   ['cStat'=>int,'xMotivo'=>str,'ultNSU'=>str,'maxNSU'=>str,'docs'=>[{nsu,schema,xml}]]
     */
    public function consultarPorNSU(string $ultNSU): array
    {
        $ultNSU = str_pad(preg_replace('/\D/', '', $ultNSU), 15, '0', STR_PAD_LEFT);
        $dist = "<distNSU><ultNSU>$ultNSU</ultNSU></distNSU>";
        $resp = $this->enviar($dist);

        $cStat  = self::tag($resp, 'cStat');
        $out = [
            'cStat'   => (int) $cStat,
            'xMotivo' => self::tag($resp, 'xMotivo'),
            'ultNSU'  => self::tag($resp, 'ultNSU'),
            'maxNSU'  => self::tag($resp, 'maxNSU'),
            'docs'    => [],
        ];
        if (preg_match_all('/<docZip\s+NSU="(\d+)"\s+schema="([^"]+)"\s*>(.*?)<\/docZip>/s', $resp, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $d) {
                $bin = base64_decode($d[3]);
                $xml = @gzdecode($bin);
                if ($xml === false) { $xml = @gzinflate($bin); }
                $out['docs'][] = ['nsu' => $d[1], 'schema' => $d[2], 'xml' => (string) $xml];
            }
        }
        return $out;
    }

    /**
     * Consulta a distribuição por uma chave de acesso específica (consChNFe).
     * Depois de manifestar a nota, o SEFAZ passa a devolver o procNFe completo.
     * Devolve o mesmo formato de consultarPorNSU (com 'docs').
     */
    public function consultarPorChave(string $chave): array
    {
        $chave = preg_replace('/\D/', '', $chave);
        if (strlen($chave) !== 44) {
            throw new SefazException('Chave de acesso inválida (esperado 44 dígitos).');
        }
        $dist = "<consChNFe><chNFe>$chave</chNFe></consChNFe>";
        $resp = $this->enviar($dist);

        $out = [
            'cStat'   => (int) self::tag($resp, 'cStat'),
            'xMotivo' => self::tag($resp, 'xMotivo'),
            'ultNSU'  => self::tag($resp, 'ultNSU'),
            'maxNSU'  => self::tag($resp, 'maxNSU'),
            'docs'    => [],
        ];
        if (preg_match_all('/<docZip\s+NSU="(\d+)"\s+schema="([^"]+)"\s*>(.*?)<\/docZip>/s', $resp, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $d) {
                $bin = base64_decode($d[3]);
                $xml = @gzdecode($bin);
                if ($xml === false) { $xml = @gzinflate($bin); }
                $out['docs'][] = ['nsu' => $d[1], 'schema' => $d[2], 'xml' => (string) $xml];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ internos ---

    private function enviar(string $conteudoDist): string
    {
        $soap = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'
            . '<nfeDistDFeInteresse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe"><nfeDadosMsg>'
            . '<distDFeInt versao="1.01" xmlns="http://www.portalfiscal.inf.br/nfe">'
            . "<tpAmb>{$this->tpAmb}</tpAmb><cUFAutor>{$this->cUF}</cUFAutor><CNPJ>{$this->cnpj}</CNPJ>"
            . $conteudoDist
            . '</distDFeInt></nfeDadosMsg></nfeDistDFeInteresse>'
            . '</soap12:Body></soap12:Envelope>';

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $soap,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/soap+xml; charset=utf-8'],
            CURLOPT_SSLCERT        => $this->certPem,
            CURLOPT_SSLKEY         => $this->keyPem,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $resp = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new SefazException("Erro de conexão com o SEFAZ: $err");
        }
        if ($http !== 200) {
            throw new SefazException("SEFAZ respondeu HTTP $http.");
        }
        return (string) $resp;
    }

    private static function tag(string $xml, string $t): string
    {
        return preg_match("/<$t>(.*?)<\/$t>/s", $xml, $m) ? trim($m[1]) : '';
    }
}
