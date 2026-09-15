# Funil Doceria — extensão do WhatsApp Web

Um painel que desliza da direita dentro do **WhatsApp Web** mostrando quantos
clientes estão em cada etapa do funil do bot. Clique no número de uma etapa pra
ver a lista; clique num cliente pra **abrir a conversa dele** (pesquisa o número
na barra do WhatsApp e abre; se não achar, usa o link oficial).

As etapas vêm do próprio bot (endpoint `/funil`): No menu · Preenchendo pedido ·
**Aguardando você** · Em atendimento · Encerrados.

## Pré-requisito
O bot precisa estar rodando (Docker) e expondo a porta 3000 — já configurado no
`docker-compose.yml` (`127.0.0.1:3000`). Teste no navegador: abrir
`http://localhost:3000/funil` deve retornar um JSON.

## Instalar (Chrome ou Edge)
1. Abra `chrome://extensions` (ou `edge://extensions`).
2. Ative o **Modo do desenvolvedor** (canto superior direito).
3. Clique em **Carregar sem compactação** e selecione a pasta
   `wa-funil-extension`.
4. Abra/atualize o **web.whatsapp.com** — aparece o botão **"Funil"** no canto
   inferior direito. Clique pra abrir o painel.

## Como usar
- O botão abre/fecha o painel (desliza da direita).
- Cada etapa mostra a **contagem**; clique pra expandir a **lista** de clientes.
- Clique num cliente → abre a conversa dele.
- Atualiza sozinho a cada 15s; há botão "Atualizar".

## Observações
- O painel só lê dados (não envia nada) — só chama `localhost:3000/funil`.
- A abertura por "pesquisa na barra" depende do HTML do WhatsApp Web, que muda de
  tempos em tempos. Se um dia parar de achar o resultado, ele cai no link oficial
  `send?phone=` (que também abre a conversa). Se precisar, os seletores ficam em
  `content.js` (`acharBarraBusca`).
- Nomes aparecem quando o cliente já passou pelo bot; senão mostra o número.
