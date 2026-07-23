<?php
// Marca (vinho #a51d32) aplicada APENAS como acento — a sidebar ativa e o topo
// com a logo. Botões e demais componentes seguem o padrão do Bootstrap/AdminKit
// (primary azul, success verde, secondary cinza, danger vermelho).
?>
<style>
    /* Item ativo/hover da sidebar na cor da marca */
    .sidebar-link:hover,
    .sidebar-item.active > .sidebar-link {
        background: linear-gradient(90deg, rgba(165, 29, 50, .15), rgba(165, 29, 50, .10) 50%, transparent);
        border-left-color: #a51d32;
    }
    /* Logo em destaque no topo da sidebar */
    .sidebar-brand {
        padding: 1.25rem 1.25rem 1rem;
        background: rgba(255, 255, 255, .04);
    }
    .sidebar-brand img { max-height: 54px; width: auto; }
</style>
