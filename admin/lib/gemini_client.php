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

    /**
     * Prepara uma imagem para envio: reduz para no máx. 1600px e recomprime em
     * JPEG, cortando o tamanho do upload (a foto do iPhone tem 3-4 MB e faz o
     * Gemini estourar o timeout). Se o GD não estiver disponível, envia como veio.
     * @return array{0:string,1:string} [mime, base64]
     */
    public static function imagemParaBase64(string $caminho, string $mimeOriginal = 'image/jpeg'): array
    {
        if (function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($caminho);
            $img = $raw !== false ? @imagecreatefromstring($raw) : false;
            if ($img !== false) {
                $w = imagesx($img); $h = imagesy($img);
                $max = 1600;
                if (max($w, $h) > $max) {
                    $s = $max / max($w, $h);
                    $nw = (int) round($w * $s); $nh = (int) round($h * $s);
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                    imagedestroy($img); $img = $dst;
                }
                ob_start(); imagejpeg($img, null, 82); $jpg = ob_get_clean();
                imagedestroy($img);
                if (is_string($jpg) && $jpg !== '') { return ['image/jpeg', base64_encode($jpg)]; }
            }
        }
        return [$mimeOriginal, base64_encode((string) @file_get_contents($caminho))];
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
                '- O usuário escreve informalmente, só o primeiro nome ou um apelido ("pra',
                '  matheus"). Procure essa pessoa na lista de Fornecedores cadastrados e',
                '  devolva o nome COMPLETO como está lá ("Matheus Vieira Cardoso"). Só use o',
                '  nome solto se ninguém no cadastro corresponder.',
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
        $hoje = date('Y-m-d');
        $ano  = date('Y');
        return implode("\n", [
            'Você extrai UM lançamento de conta a pagar (despesa) para um sistema financeiro.',
            "HOJE é {$hoje}. Use isto para resolver datas.",
            'Responda SOMENTE com o JSON do schema. Regras:',
            '- value: número como string, PONTO decimal, NEGATIVO para despesa (ex.: "-84.90").',
            '  FORMATO BR: vírgula é DECIMAL, ponto é MILHAR. "1.235,36" -> "-1235.36";',
            '  "84,90" -> "-84.90". NUNCA trate a vírgula como milhar nem multiplique por 100.',
            '- Datas no formato YYYY-MM-DD. Se não souber, deixe vazio.',
            "- Data SEM ano (ex.: \"20/07\") usa o ano atual ({$ano}). \"hoje\" = {$hoje}.",
            "- Ano de 2 dígitos (ex.: \"29/07/26\") é 20YY do século atual, próximo de HOJE",
            "  (\"26\" = {$ano}). NUNCA devolva um ano vários anos no passado a menos que o",
            '  documento mostre o ano COMPLETO (4 dígitos). Na dúvida, use o ano atual.',
            '  NUNCA invente um ano passado; só use outro ano se o documento disser explicitamente.',
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

    /**
     * Revisa um post de blog: ortografia/gramática, SEO e legibilidade.
     * Devolve texto reescrito + sugestões, sem alterar nada por conta própria.
     */
    public function revisarBlog(string $titulo, string $html): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'conteudo_revisado_html' => ['type' => 'string'],
                'titulo_sugerido'        => ['type' => 'string'],
                'resumo_sugerido'        => ['type' => 'string'],
                'palavras_chave'         => ['type' => 'array', 'items' => ['type' => 'string']],
                'sugestoes'              => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['conteudo_revisado_html', 'resumo_sugerido'],
        ];
        $instr = implode("\n", [
            'Você é editor de um blog de uma confeitaria brasileira. Revise o post abaixo.',
            'Responda SOMENTE com o JSON do schema. Regras:',
            '- conteudo_revisado_html: o MESMO texto corrigido (ortografia, gramática, pontuação,',
            '  clareza e divisão de parágrafos). Mantenha o SENTIDO e o tom; não invente fatos.',
            '  Devolva HTML simples: <p>, <h2>, <h3>, <strong>, <em>, <ul>/<li>, <blockquote>, <a>.',
            '  NÃO use <script>, style inline, classes ou tags fora dessa lista.',
            '- titulo_sugerido: um título melhor para SEO (deixe vazio se o atual já é bom).',
            '- resumo_sugerido: meta description de até 160 caracteres, atraente e com a palavra-chave.',
            '- palavras_chave: 3 a 6 termos de SEO relevantes.',
            '- sugestoes: bullets curtos de melhorias (SEO, legibilidade, estrutura) que o autor pode aplicar.',
            '',
            'TÍTULO ATUAL: ' . $titulo,
            'CONTEÚDO (HTML):',
            $html,
        ]);
        return $this->chamar([['text' => $instr]], $schema);
    }

    /**
     * Gera uma descrição curta e apetitosa para um produto do cardápio.
     * Devolve ['descricao' => string]. Contexto opcional (grupo, observação do lojista).
     */
    public function descricaoProduto(string $nome, string $categoria = '', string $sub = '', string $extra = ''): array
    {
        $schema = [
            'type' => 'object',
            'properties' => ['descricao' => ['type' => 'string']],
            'required' => ['descricao'],
        ];
        $instr = implode("\n", array_filter([
            'Você escreve descrições para o cardápio de uma confeitaria artesanal brasileira',
            '(Arlete Vieira Confeitaria & Doceria, São José/SC, 27 anos de história).',
            'Escreva a descrição de UM produto, para aparecer no cardápio do site.',
            'Responda SOMENTE com o JSON do schema. Regras:',
            '- 1 a 2 frases curtas (no máximo ~240 caracteres). Português do Brasil.',
            '- Tom acolhedor e caseiro; destaque sabor e textura, sem exagero publicitário',
            '  ("o melhor", "imperdível") e sem emojis.',
            '- NÃO invente preço, quantidade, ingredientes exóticos nem informações que você',
            '  não tem certeza. Se não souber os ingredientes, descreva de forma genérica e verdadeira.',
            '- Escreva natural; não comece repetindo o nome de forma robótica.',
            '',
            'Produto: ' . $nome,
            $categoria !== '' ? 'Categoria: ' . $categoria : '',
            $sub !== '' ? 'Grupo/sabor: ' . $sub : '',
            $extra !== '' ? 'Observações do lojista (use como base): ' . $extra : '',
        ]));
        return $this->chamar([['text' => $instr]], $schema);
    }

    /**
     * Extrai a LISTA DE ITENS de um cupom/nota (foto), para dar entrada no
     * estoque. Cada item: descrição, quantidade e unidade. Sem rede além do Gemini.
     * @return array{itens: array<int,array{descricao:string,quantidade:string,unidade:string}>}
     */
    public function extrairItensCupom(string $base64, string $mime): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'itens' => [
                    'type'  => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'descricao'  => ['type' => 'string'],
                            'quantidade' => ['type' => 'string'],
                            'unidade'    => ['type' => 'string'],
                            'valor_unit' => ['type' => 'string'],
                        ],
                        'required' => ['descricao', 'quantidade'],
                    ],
                ],
            ],
            'required' => ['itens'],
        ];
        $instr = implode("\n", [
            'Você lê um CUPOM FISCAL (NFC-e) ou nota de compra e lista os ITENS comprados,',
            'para dar entrada em estoque. Responda SOMENTE com o JSON do schema. Regras:',
            '- Um objeto por item comprado (linha do cupom).',
            '- descricao: o nome do produto como está no cupom, limpo (sem o código).',
            '- quantidade: número como string, PONTO decimal (ex.: "2", "1.5"). Se o item é',
            '  vendido por peso, use o peso em kg. Se não achar, use "1".',
            '- unidade: "UN", "KG", "CX"... se aparecer; senão vazio.',
            '- valor_unit: o preço UNITÁRIO do item (número string, PONTO decimal). Se o cupom',
            '  só mostra o total da linha, divida pela quantidade. Se não achar, "0".',
            '- FORMATO BRASILEIRO (MUITO IMPORTANTE): no cupom a VÍRGULA é a casa DECIMAL e o',
            '  PONTO é separador de MILHAR. "4,19" são quatro reais e dezenove centavos ->',
            '  devolva "4.19" (NUNCA "419"). "1.234,56" -> "1234.56". "12,00" -> "12.00".',
            '  Vale para valor_unit E quantidade. Jamais multiplique por 100 nem trate a',
            '  vírgula como milhar.',
            '- NÃO invente itens e ignore descontos, subtotal, total e formas de pagamento.',
        ]);
        return $this->chamar([
            ['text' => $instr],
            ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
            ['text' => 'Liste os itens comprados neste cupom.'],
        ], $schema);
    }

    private function extrair(array $parts): array
    {
        return $this->normalizar($this->chamar($parts, $this->schema()));
    }

    /** Faz a chamada ao Gemini com um schema e devolve o JSON decodificado. */
    private function chamar(array $parts, array $schema): array
    {
        $body = json_encode([
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema'   => $schema,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $url = self::BASE . '/models/' . rawurlencode($this->model) . ':generateContent';

        // Timeout de conexão às vezes estola ("0 bytes"): tenta até 3 vezes.
        $tentativas = 3;
        $ultimoErro = '';
        for ($t = 1; $t <= $tentativas; $t++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_TIMEOUT        => 75,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($resp !== false && $status > 0) { break; }   // resposta chegou
            $ultimoErro = $err ?: "sem resposta (HTTP $status)";
            if ($t < $tentativas) { sleep(2); }              // pequena pausa e tenta de novo
        }
        if ($resp === false || $status === 0) {
            throw new GeminiException("Erro de conexão com o Gemini após {$tentativas} tentativas: {$ultimoErro}");
        }

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
        return $dados;
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
