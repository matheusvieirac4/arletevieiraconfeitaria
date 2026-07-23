<?php
// ============================================================================
// Parser de NF-e (modelo 55) — extrai de um XML de nota o necessário para
// lançar UMA conta a pagar no Cardápio Web (um lançamento por nota, valor total).
//
// Pura lógica, sem rede. Não decide conta/categoria/centro de custo — esses
// campos ficam vazios e são resolvidos na tela de revisão (cruzando com os
// cadastros do lojista).
// ============================================================================

class NFeParserException extends \RuntimeException {}

class NFeParser
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * @param string $xmlString conteúdo do XML (procNFe ou NFe).
     * @return array estrutura normalizada da nota + 'lancamento' pronto p/ revisão.
     */
    public static function parse(string $xmlString): array
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new NFeParserException('XML inválido ou ilegível.');
        }
        $xml->registerXPathNamespace('n', self::NS);

        $ide   = self::first($xml, '//n:infNFe/n:ide');
        $emit  = self::first($xml, '//n:infNFe/n:emit');
        $total = self::first($xml, '//n:infNFe/n:total/n:ICMSTot');
        if (!$ide || !$emit || !$total) {
            throw new NFeParserException('Não parece uma NF-e válida (faltam ide/emit/total).');
        }

        // Chave de acesso (para deduplicação): do Id do infNFe ou do protNFe.
        $chave = '';
        $infNFe = self::first($xml, '//n:infNFe');
        if ($infNFe && isset($infNFe['Id'])) {
            $chave = preg_replace('/\D/', '', (string) $infNFe['Id']); // remove "NFe"
        }
        if ($chave === '') {
            $chave = self::text($xml, '//n:protNFe/n:infProt/n:chNFe');
        }

        $nNF     = self::val($ide, 'nNF');
        $serie   = self::val($ide, 'serie');
        $natOp   = self::val($ide, 'natOp');
        $dhEmi   = self::val($ide, 'dhEmi');
        $emissao = $dhEmi !== '' ? substr($dhEmi, 0, 10) : '';

        $fornecedorNome = self::val($emit, 'xNome');
        $fornecedorCnpj = self::val($emit, 'CNPJ');

        $valorTotal = self::val($total, 'vNF'); // string, ex "18.45"

        // Parcelas (duplicatas), se houver <cobr><dup>.
        $parcelas = [];
        foreach ($xml->xpath('//n:infNFe/n:cobr/n:dup') ?: [] as $dup) {
            $parcelas[] = [
                'numero'     => self::val($dup, 'nDup'),
                'vencimento' => self::val($dup, 'dVenc'),
                'valor'      => self::val($dup, 'vDup'),
            ];
        }

        // Itens (para referência/detalhe na revisão).
        $itens = [];
        foreach ($xml->xpath('//n:infNFe/n:det/n:prod') ?: [] as $prod) {
            $itens[] = [
                'descricao'  => self::val($prod, 'xProd'),
                'quantidade' => self::val($prod, 'qCom'),
                'valor'      => self::val($prod, 'vProd'),
            ];
        }

        // Data de vencimento: 1ª parcela, senão a emissão.
        $vencimento = $parcelas[0]['vencimento'] ?? $emissao;

        // Avisos que a revisão deve destacar.
        $avisos = [];
        if (preg_match('/bonific|brinde|amostra|remessa|comodato|devolu/i', $natOp)) {
            $avisos[] = "Natureza da operação é \"$natOp\" — confira se deve virar conta a pagar.";
        }
        if (count($parcelas) > 1) {
            $avisos[] = 'A nota tem ' . count($parcelas) . ' parcelas; será lançada como um único valor total (' . $valorTotal . ').';
        }

        $descricao = trim('NF-e ' . $nNF . ($fornecedorNome !== '' ? ' - ' . $fornecedorNome : ''));

        // Lançamento no formato do Cardápio Web (campos que dá pra preencher da nota).
        // Despesa => valor negativo, decimal com ponto.
        $lancamento = [
            'description'     => $descricao,
            'supplier'        => $fornecedorNome,
            'value'           => $valorTotal !== '' ? '-' . $valorTotal : '',
            'due_date'        => $vencimento,
            'competence_date' => $emissao,
            'settlement_date' => '',
            'observation'     => '',
            // Preenchidos na tela de revisão (cruzando com os cadastros do lojista):
            'account'         => '',
            'category'        => '',
            'cost_center'     => '',
            'payment_method'  => '',
        ];

        return [
            'chave'             => $chave,
            'numero'            => $nNF,
            'serie'             => $serie,
            'emissao'           => $emissao,
            'natureza_operacao' => $natOp,
            'fornecedor'        => ['nome' => $fornecedorNome, 'cnpj' => $fornecedorCnpj],
            'valor_total'       => $valorTotal,
            'parcelas'          => $parcelas,
            'itens'             => $itens,
            'avisos'            => $avisos,
            'lancamento'        => $lancamento,
        ];
    }

    // ------------------------------------------------------------- helpers ---

    private static function first(\SimpleXMLElement $xml, string $xpath): ?\SimpleXMLElement
    {
        $r = $xml->xpath($xpath);
        return $r && isset($r[0]) ? $r[0] : null;
    }

    private static function text(\SimpleXMLElement $xml, string $xpath): string
    {
        $n = self::first($xml, $xpath);
        return $n !== null ? trim((string) $n) : '';
    }

    /** Valor de um filho direto (respeitando o namespace da NF-e). */
    private static function val(\SimpleXMLElement $node, string $child): string
    {
        $c = $node->children(self::NS)->{$child};
        return ($c !== null && count($c)) ? trim((string) $c[0]) : '';
    }
}
