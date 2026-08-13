<?php
// ============================================================================
// Manifestação do Destinatário — "Ciência da Operação" (tpEvento 210210).
// Enviada ao webservice de eventos do Ambiente Nacional (NFeRecepcaoEvento4),
// assinada digitalmente (XML-DSig) com o mesmo certificado A1 do puxador.
//
// Por que existe: a distribuição por NSU só entrega o RESUMO (resNFe). O XML
// completo (procNFe, com itens) só é liberado pelo SEFAZ DEPOIS de manifestar.
// "Ciência da Operação" apenas reconhece que a operação existe — não confirma
// nem recusa a compra — e é o suficiente para liberar o download do XML.
//
// Depende de: openssl (assinatura) e DOM/C14N (canonicalização).
// ============================================================================

require_once __DIR__ . '/sefaz_distribuicao.php';   // reusa SefazException

class SefazManifestacao
{
    // Ambiente Nacional (AN) — recepção de eventos de manifestação do destinatário.
    private const ENDPOINT_PROD = 'https://www1.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx';
    private const ENDPOINT_HOM  = 'https://hom1.nfe.fazenda.gov.br/NFeRecepcaoEvento4/NFeRecepcaoEvento4.asmx';

    private const NS_NFE = 'http://www.portalfiscal.inf.br/nfe';
    private const NS_SIG = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N   = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private string $cnpj;
    private int $tpAmb;
    private string $certPem;
    private string $keyPem;
    /** @var \OpenSSLAsymmetricKey|resource */
    private $pkey;
    private string $certDerB64;

    public function __construct(string $pfxBinario, string $senha, string $cnpj = '', int $tpAmb = 1)
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
        $this->cnpj  = $cnpj;
        $this->tpAmb = $tpAmb;

        $this->pkey = openssl_pkey_get_private($certs['pkey']);
        if ($this->pkey === false) {
            throw new SefazException('Não foi possível ler a chave privada do certificado.');
        }
        // Certificado sem cabeçalhos PEM, em uma linha (X509Certificate da assinatura).
        $this->certDerB64 = preg_replace('/-----[^-]+-----|\s+/', '', $certs['cert']);

        $this->certPem = tempnam(sys_get_temp_dir(), 'sfe');
        $this->keyPem  = tempnam(sys_get_temp_dir(), 'sfe');
        file_put_contents($this->certPem, $certs['cert']);
        file_put_contents($this->keyPem, $certs['pkey']);
    }

    public function __destruct()
    {
        @unlink($this->certPem);
        @unlink($this->keyPem);
    }

    /**
     * Manifesta "Ciência da Operação" (210210) para uma chave de acesso.
     * @return array{cStat:int,xMotivo:string,protocolo:string,xml:string}
     */
    public function cienciaOperacao(string $chave, int $nSeq = 1): array
    {
        $chave = preg_replace('/\D/', '', $chave);
        if (strlen($chave) !== 44) {
            throw new SefazException('Chave de acesso inválida (esperado 44 dígitos).');
        }

        $tpEvento = '210210';
        $nSeqStr  = str_pad((string) max(1, $nSeq), 2, '0', STR_PAD_LEFT);
        $id       = 'ID' . $tpEvento . $chave . $nSeqStr;
        $dh       = date('Y-m-d\TH:i:sP');

        $infEvento =
              '<infEvento Id="' . $id . '">'
            . '<cOrgao>91</cOrgao>'                       // 91 = Ambiente Nacional
            . '<tpAmb>' . $this->tpAmb . '</tpAmb>'
            . '<CNPJ>' . $this->cnpj . '</CNPJ>'
            . '<chNFe>' . $chave . '</chNFe>'
            . '<dhEvento>' . $dh . '</dhEvento>'
            . '<tpEvento>' . $tpEvento . '</tpEvento>'
            . '<nSeqEvento>' . (int) $nSeqStr . '</nSeqEvento>'
            . '<verEvento>1.00</verEvento>'
            . '<detEvento versao="1.00"><descEvento>Ciencia da Operacao</descEvento></detEvento>'
            . '</infEvento>';

        $eventoSemAssinatura =
              '<evento versao="1.00" xmlns="' . self::NS_NFE . '">'
            . $infEvento
            . '</evento>';

        $eventoAssinado = $this->assinar($eventoSemAssinatura, $id);

        $envEvento =
              '<envEvento versao="1.00" xmlns="' . self::NS_NFE . '">'
            . '<idLote>1</idLote>'
            . $eventoAssinado
            . '</envEvento>';

        $resp = $this->enviar($envEvento);

        // cStat do evento (dentro de retEvento). 135/136 = registrado; 573 = já manifestado.
        $cStat = 0; $xMotivo = ''; $prot = '';
        if (preg_match('/<retEvento.*?<cStat>(\d+)<\/cStat>.*?<xMotivo>(.*?)<\/xMotivo>/s', $resp, $m)) {
            $cStat = (int) $m[1]; $xMotivo = trim($m[2]);
        } elseif (preg_match('/<cStat>(\d+)<\/cStat>.*?<xMotivo>(.*?)<\/xMotivo>/s', $resp, $m)) {
            // cStat do lote (rejeição antes de processar o evento).
            $cStat = (int) $m[1]; $xMotivo = trim($m[2]);
        }
        if (preg_match('/<nProt>(\d+)<\/nProt>/', $resp, $mp)) { $prot = $mp[1]; }

        return ['cStat' => $cStat, 'xMotivo' => $xMotivo, 'protocolo' => $prot, 'xml' => $resp];
    }

    // ------------------------------------------------------------ assinatura ---

    /** Assina o <evento> (referência ao Id do infEvento) e devolve o XML com <Signature>. */
    private function assinar(string $eventoXml, string $refId): string
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        if (!$doc->loadXML($eventoXml)) {
            throw new SefazException('Falha ao montar o XML do evento para assinatura.');
        }

        $infEvento = $doc->getElementsByTagName('infEvento')->item(0);
        if (!$infEvento) {
            throw new SefazException('infEvento não encontrado para assinatura.');
        }

        // Digest do infEvento (transform enveloped exclui a Signature, que ainda
        // nem existe aqui; canonicalizar só o infEvento já a exclui naturalmente).
        $c14nInf = $infEvento->C14N(false, false);
        $digest  = base64_encode(sha1($c14nInf, true));

        // Monta a <Signature> com <SignedInfo>; canonicaliza o SignedInfo e assina.
        $sig = $doc->createElementNS(self::NS_SIG, 'Signature');
        $doc->documentElement->appendChild($sig);   // dentro de <evento>

        $signedInfo = $doc->createElement('SignedInfo');
        $sig->appendChild($signedInfo);

        $cm = $doc->createElement('CanonicalizationMethod');
        $cm->setAttribute('Algorithm', self::C14N);
        $signedInfo->appendChild($cm);

        $sm = $doc->createElement('SignatureMethod');
        $sm->setAttribute('Algorithm', self::NS_SIG . 'rsa-sha1');
        $signedInfo->appendChild($sm);

        $ref = $doc->createElement('Reference');
        $ref->setAttribute('URI', '#' . $refId);
        $signedInfo->appendChild($ref);

        $transforms = $doc->createElement('Transforms');
        $ref->appendChild($transforms);
        $t1 = $doc->createElement('Transform');
        $t1->setAttribute('Algorithm', self::NS_SIG . 'enveloped-signature');
        $transforms->appendChild($t1);
        $t2 = $doc->createElement('Transform');
        $t2->setAttribute('Algorithm', self::C14N);
        $transforms->appendChild($t2);

        $dm = $doc->createElement('DigestMethod');
        $dm->setAttribute('Algorithm', self::NS_SIG . 'sha1');
        $ref->appendChild($dm);

        $ref->appendChild($doc->createElement('DigestValue', $digest));

        // Canonicaliza o SignedInfo (já com o namespace dsig herdado da Signature) e assina.
        $c14nSignedInfo = $signedInfo->C14N(false, false);
        $assinatura = '';
        if (!openssl_sign($c14nSignedInfo, $assinatura, $this->pkey, OPENSSL_ALGO_SHA1)) {
            throw new SefazException('Falha ao assinar o evento (openssl_sign).');
        }

        $sig->appendChild($doc->createElement('SignatureValue', base64_encode($assinatura)));

        $keyInfo  = $doc->createElement('KeyInfo');
        $x509Data = $doc->createElement('X509Data');
        $x509Data->appendChild($doc->createElement('X509Certificate', $this->certDerB64));
        $keyInfo->appendChild($x509Data);
        $sig->appendChild($keyInfo);

        return $doc->saveXML($doc->documentElement);
    }

    // -------------------------------------------------------------- transporte ---

    private function endpoint(): string
    {
        return $this->tpAmb === 2 ? self::ENDPOINT_HOM : self::ENDPOINT_PROD;
    }

    private function enviar(string $envEvento): string
    {
        $soap = '<?xml version="1.0" encoding="utf-8"?>'
            . '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope"><soap12:Body>'
            . '<nfeDadosMsg xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4">'
            . $envEvento
            . '</nfeDadosMsg>'
            . '</soap12:Body></soap12:Envelope>';

        $ch = curl_init($this->endpoint());
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
            throw new SefazException("Erro de conexão com o SEFAZ (eventos): $err");
        }
        if ($http !== 200) {
            throw new SefazException("SEFAZ (eventos) respondeu HTTP $http.");
        }
        return (string) $resp;
    }
}
