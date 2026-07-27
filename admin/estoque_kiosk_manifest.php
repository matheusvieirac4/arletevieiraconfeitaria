<?php
// Web App Manifest do quiosque de estoque. Servido como PHP para garantir o
// Content-Type correto no shared hosting. Ao "Adicionar à tela inicial" e abrir
// pelo ícone, o Chrome/Android abre em tela cheia (sem barra de endereço).
header('Content-Type: application/manifest+json; charset=utf-8');
?>
{
  "name": "Baixa de Estoque — Arlete Vieira",
  "short_name": "Estoque",
  "start_url": "estoque_kiosk.php",
  "scope": ".",
  "display": "fullscreen",
  "orientation": "portrait",
  "background_color": "#14171c",
  "theme_color": "#14171c",
  "icons": [
    { "src": "../img/apple-touch-icon.png", "sizes": "180x180", "type": "image/png", "purpose": "any" },
    { "src": "../img/logo.png", "sizes": "512x512", "type": "image/png", "purpose": "any" }
  ]
}
