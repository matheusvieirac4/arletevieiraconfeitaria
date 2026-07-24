<?php
// ============================================================================
// Cliente do Gemini (Google) para extrair lançamentos de TEXTO livre ou de
// FOTO de cupom (sem QR). PHP + curl (sem SDK/composer — roda no shared hosting).
//
// Usa saída estruturada (responseSchema) para forçar exatamente o formato de
// lançamento do Cardápio Web, e recebe os cadastros do lojista no prompt para
// mapear "sicredi"/"pix"/"embalagens" para os nomes já cadastrados.
// ============================================================================

class GeminiException extends \RuntimeException {}

class GeminiClient
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gemini-flash-latest')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public static function fromConfig(array $cfg): self
    {
        if (empty($cfg['gemini_api_key'])) {
            throw new GeminiException('IA não configurada: falta gemini_api_key.');
        }
        $model = !empty($cfg['gemini_model']) ? $cfg['gemini_model'] : 'gemini-flash-latest';
        return new self($cfg['gemini_api_key'], $model);
    }

    /** Extrai um lançamento a partir de texto livre. */
    public function extrairTexto(string $texto, array $ctx): array
    {
        return $this->extrair([
            ['text' => $this->instrucoes($ctx)],
            ['text' => "Frase do usuário:\n" . $texto],
        ]);
    }

    /** Extrai um lançamento a partir da imagem de um cupom (base64). */
    public function extrairImagem(string $base64, string $mime, array $ctx, string $textoExtra = ''): array
    {
        $parts = [['text' => $this->instrucoes($ctx)]];
        if ($textoExtra !== '') {
            // A observação é uma INSTRUÇÃO, não só contexto: o cupom diz onde foi
            // comprado, o usuário diz o que aquilo significa (ex.: cupom da
            // padaria que na verdade é vale de um funcionário). Por isso ela
            // manda na classificação, mas não inventa fornecedor/valor/data.
            $parts[] = ['text' => implode("\n", [
                'INSTRUÇÃO DO USUÁRIO sobre este documento (tem PRIORIDADE sobre o que está impresso):',
                $textoExtra,
                '',
                'Como aplicar:',
                '- A instrução VENCE o documento em qualquer campo que ela mencione, inclusive',
                '  o fornecedor. O documento só preenche o que a instrução deixou em aberto.',
                '- Se a instrução disser que a despesa é DE ou PARA uma pessoa (vale, vale-',
                '  refeição, adiantamento, reembolso, acerto...), o supplier é o NOME DESSA',
                '  PESSOA — nunca o estabelecimento impresso no cupom. O estabelecimento vira',
                '  apenas contexto na description (ex.: "Vale-refeição na Padaria X").',
                '  Motivo: o relatório de vales é filtrado pelo nome da pessoa; se ficar o',
                '  nome da loja, o vale some do relatório dela.',
                '- supplier_cnpj: use o documento da ENTIDADE que virou supplier. Pessoa =',
                '  CPF (11 dígitos), empresa = CNPJ (14 dígitos). Se a instrução trouxer o',
                '  CPF da pessoa, use-o; se não trouxer, deixe supplier_cnpj VAZIO — não',
                '  aproveite o CNPJ do cupom, que é de outra entidade.',
                '- Valor e datas continuam vindo do documento, salvo instrução em contrário.',
                '- Descrição, categoria e centro de custo seguem a instrução: ela explica a',
                '  finalidade real da despesa, que o cupom não mostra.',
                '- Copie a instrução, na íntegra, no campo observation.',
            ])];
        }
        $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => $base64]];
        $parts[] = ['text' => 'Leia este cupom/nota fiscal e extraia o lançamento de despesa.'];
        return $this->extrair($parts);
    }

    // ------------------------------------------------------------ internos ---

    private function instrucoes(array $ctx): string
    {
        $lista = function (string $rot, array $nomes): string {
            $nomes = array_slice(array_values(array_filter($nomes)), 0, 200);
            return $rot . ': ' . ($nomes ? implode('; ', $nomes) : '(nenhum cadastrado)');
        };
        return implode("\n", [
            'Você extrai UM lançamento de conta a pagar (despesa) para um sistema financeiro.',
            'Responda SOMENTE com o JSON do schema. Regras:',
            '- value: número como string, PONTO decimal, NEGATIVO para despesa (ex.: "-84.90").',
            '- Datas no formato YYYY-MM-DD. Se não souber, deixe vazio.',
            '- Para account, category, cost_center, payment_method e supplier: use EXATAMENTE',
            '  um dos nomes já cadastrados abaixo quando houver correspondência; se não houver,',
            '  use o nome mais natural. NÃO invente contas que não existem.',
            '- category e cost_center: NUNCA invente. Escolha OBRIGATORIAMENTE um dos nomes',
            '  listados abaixo, copiado caractere por caractere. Se nenhum servir, deixe vazio.',
            '- Se houver um CNPJ (14 dígitos) ou CPF junto ao fornecedor, coloque só os dígitos',
            '  em supplier_cnpj e deixe supplier com o NOME LIMPO, sem o número.',
            '- Vale/adiantamento/reembolso a uma pessoa: o supplier é o nome da PESSOA (o',
            '  documento dela é CPF, 11 dígitos), não o estabelecimento onde se gastou.',
            '- settlement_date (data de pagamento): se a forma NÃO for boleto (pix, dinheiro,',
            '  cartão...), a compra é paga na hora — use a data da compra. Se for BOLETO, deixe vazio.',
            '',
            $lista('Contas', $ctx['contas'] ?? []),
            $lista('Categorias', $ctx['categorias'] ?? []),
            $lista('Centros de custo', $ctx['centros'] ?? []),
            $lista('Formas de pagamento', $ctx['formas'] ?? []),
            $lista('Fornecedores', $ctx['fornecedores'] ?? []),
        ]);
    }

    private function schema(): array
    {
        $str = ['type' => 'string'];
        return [
            'type' => 'object',
            'properties' => [
                'description'     => $str,
                'supplier'        => $str,
                'supplier_cnpj'   => $str,
                'category'        => $str,
                'cost_center'     => $str,
                'account'         => $str,
                'payment_method'  => $str,
                'value'           => $str,
                'due_date'        => $str,
                'competence_date' => $str,
                'settlement_date' => $str,
                'observation'     => $str,
            ],
            'required' => ['description', 'value', 'account', 'payment_method', 'supplier', 'category'],
        ];
    }

    private function extrair(array $parts): array
    {
        $body = json_encode([
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema'   => $this->schema(),
            ],
        ], JSON_UNESCAPED_UNICODE);

        $url = self::BASE . '/models/' . rawurlencode($this->model) . ':generateContent';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch); curl_close($ch);
            throw new GeminiException("Erro de conexão com o Gemini: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($resp, true);
        if ($status < 200 || $status >= 300) {
            $msg = $json['error']['message'] ?? substr($resp, 0, 300);
            throw new GeminiException("Gemini falhou (HTTP $status): $msg");
        }

        $texto = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $dados = json_decode($texto, true);
        if (!is_array($dados)) {
            throw new GeminiException('O Gemini não devolveu um JSON válido.');
        }
        return $this->normalizar($dados);
    }

    /** Garante todas as chaves do lançamento e datas padrão. */
    private function normalizar(array $d): array
    {
        $hoje = date('Y-m-d');
        $g = fn(string $k) => isset($d[$k]) ? trim((string) $d[$k]) : '';
        $venc = $g('due_date') !== '' ? $g('due_date') : $hoje;
        $comp = $g('competence_date') !== '' ? $g('competence_date') : $venc;

        // Data de pagamento: boleto fica em aberto; o resto é pago na hora (data da compra).
        $forma = $g('payment_method');
        $liquid = $g('settlement_date');
        $ehBoleto = stripos($forma, 'boleto') !== false;
        if ($liquid === '' && !$ehBoleto) {
            $liquid = $comp;
        }

        return [
            'description'     => $g('description'),
            'supplier'        => $g('supplier'),
            'supplier_cnpj'   => preg_replace('/\D/', '', $g('supplier_cnpj')),
            'category'        => $g('category'),
            'cost_center'     => $g('cost_center'),
            'account'         => $g('account'),
            'payment_method'  => $forma,
            'value'           => $g('value'),
            'due_date'        => $venc,
            'competence_date' => $comp,
            'settlement_date' => $liquid,
            'observation'     => $g('observation'),
        ];
    }
}
