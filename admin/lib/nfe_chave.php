<?php
// ============================================================================
// Parser da CHAVE DE ACESSO da NF-e/NFC-e (44 dígitos), tipicamente lida do
// QR Code de um cupom fiscal (NFC-e, modelo 65).
//
// A chave por si só NÃO contém o valor nem os itens — mas contém CNPJ do
// emitente, número, série e ano/mês de emissão. Com isso montamos um lançamento
// parcial (fornecedor casa por CNPJ na revisão) e o usuário completa o valor.
//
// Layout dos 44 dígitos:
//   cUF(2) AAMM(4) CNPJ(14) mod(2) serie(3) nNF(9) tpEmis(1) cNF(8) cDV(1)
// ============================================================================

class NFeChaveException extends \RuntimeException {}

class NFeChave
{
    /** Extrai a chave (44 dígitos) de um texto de QR Code / URL de consulta. */
    public static function extrairDeTexto(string $texto): ?string
    {
        if (preg_match('/\d{44}/', $texto, $m)) {
            return $m[0];
        }
        return null;
    }

    /** Interpreta a chave e devolve uma nota parcial no formato da revisão. */
    public static function parse(string $chave): array
    {
        $chave = preg_replace('/\D/', '', $chave);
        if (strlen($chave) !== 44) {
            throw new NFeChaveException('Chave de acesso inválida (precisa ter 44 dígitos).');
        }

        $aa    = substr($chave, 2, 2);
        $mm    = substr($chave, 4, 2);
        $cnpj  = substr($chave, 6, 14);
        $mod   = substr($chave, 20, 2);
        $serie = ltrim(substr($chave, 22, 3), '0');
        $nNF   = ltrim(substr($chave, 25, 9), '0');

        $emissao = '20' . $aa . '-' . $mm . '-01'; // só ano/mês na chave
        $tipo    = $mod === '65' ? 'NFC-e' : ($mod === '55' ? 'NF-e' : 'Documento fiscal');

        $descricao = trim($tipo . ' ' . $nNF);

        $lancamento = [
            'description'     => $descricao,
            'supplier'        => '',            // resolvido por CNPJ na revisão
            'value'           => '',            // NÃO vem na chave — preencher
            'due_date'        => $emissao,
            'competence_date' => $emissao,
            'settlement_date' => '',
            'observation'     => '',
            'payment_method'  => '',
            'account'         => '',
            'category'        => '',
            'cost_center'     => '',
        ];

        return [
            'chave'             => $chave,
            'numero'            => $nNF,
            'serie'             => $serie,
            'emissao'           => $emissao,
            'natureza_operacao' => $tipo . ' (lida do QR Code)',
            'fornecedor'        => ['nome' => '', 'cnpj' => $cnpj],
            'valor_total'       => '',
            'parcelas'          => [],
            'itens'             => [],
            'avisos'            => [
                'Dados vindos do QR Code: fornecedor (por CNPJ), número e data. '
                . 'O valor não está no QR — informe o total manualmente.',
            ],
            'lancamento'        => $lancamento,
        ];
    }
}
