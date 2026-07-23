<?php
// ================= CONFIG DA INTEGRAÇÃO FINANCEIRA (MODELO) =================
// Copie este arquivo para "config_financeiro.php" e preencha com os valores reais.
// "config_financeiro.php" está no .gitignore e NUNCA deve ser versionado
// (contém o refresh_token, uma credencial).
//
// Como obter (via DevTools no portal.cardapioweb.com, aba Network):
//   - company_id: header "companyid" das requisições da API (ex.: 24945).
//   - refresh_token: faça login e abra a RESPOSTA do POST em
//     dashboard.cardapioweb.com/api/v2/auth/token — copie o campo "refresh_token".
//     (É um JWT longo; NÃO é o token do Firebase que começa com "AMf-".)
//     Vale por 5 dias e rotaciona a cada renovação — a ferramenta cuida disso
//     sozinha; você só precisa capturar UM refresh_token válido aqui.

return [
    'company_id'    => 'COLOQUE_O_COMPANY_ID',
    'refresh_token' => 'COLOQUE_O_REFRESH_TOKEN_DO_CW',

    // OPCIONAL — IA (Gemini) para ler texto livre e foto de cupom sem QR.
    // Chave grátis em https://aistudio.google.com/apikey . Deixe vazio para desativar.
    'gemini_api_key' => '',
    'gemini_model'   => 'gemini-flash-latest',
];
