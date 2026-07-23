<?php
// ================= CONFIG DA INTEGRAÇÃO FINANCEIRA (MODELO) =================
// Copie este arquivo para "config_financeiro.php" e preencha com os valores reais.
// "config_financeiro.php" está no .gitignore e NUNCA deve ser versionado
// (contém o refresh_token, que é uma credencial de vida longa).
//
// Onde obter cada valor (capturados via DevTools do portal.cardapioweb.com):
//   - firebase_api_key: chave pública do Firebase (aparece na URL do securetoken,
//     ex.: ...?key=AIzaSy... ). É pública, mas fica aqui por ser por-tenant.
//   - company_id: header "companyid" das requisições da API (ex.: 24945).
//   - refresh_token: body do POST em securetoken.googleapis.com (grant_type=refresh_token).
//     >>> SEGREDO. Trate como senha. <<<

return [
    'firebase_api_key' => 'COLOQUE_A_FIREBASE_WEB_API_KEY',
    'company_id'       => 'COLOQUE_O_COMPANY_ID',
    'refresh_token'    => 'COLOQUE_O_REFRESH_TOKEN',
];
