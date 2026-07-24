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

    /* O app.css do AdminKit NÃO inclui o CSS de modal do Bootstrap.
       Estas regras restauram o modal (overlay/backdrop/botão fechar). */
    .fade { transition: opacity .15s linear; }
    .modal {
        position: fixed; inset: 0; z-index: 1055;
        display: none; width: 100%; height: 100%;
        overflow-x: hidden; overflow-y: auto; outline: 0;
    }
    .modal-dialog {
        position: relative; width: auto; margin: .5rem; pointer-events: none;
    }
    .modal.fade .modal-dialog { transition: transform .3s ease-out; transform: translate(0, -50px); }
    .modal.show .modal-dialog { transform: none; }
    .modal-dialog-centered { display: flex; align-items: center; min-height: calc(100% - 1rem); }
    .modal-content {
        position: relative; display: flex; flex-direction: column; width: 100%;
        pointer-events: auto; background-color: #fff; background-clip: padding-box;
        border: 1px solid rgba(0,0,0,.15); border-radius: .5rem; outline: 0;
    }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem; border-bottom: 1px solid #dee2e6; }
    .modal-title { margin: 0; line-height: 1.5; }
    .modal-body { position: relative; flex: 1 1 auto; padding: 1rem; }
    .modal-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .5rem; padding: .75rem; border-top: 1px solid #dee2e6; }
    .modal-backdrop { position: fixed; inset: 0; z-index: 1050; width: 100vw; height: 100vh; background-color: #000; }
    .modal-backdrop.fade { opacity: 0; }
    .modal-backdrop.show { opacity: .5; }
    body.modal-open { overflow: hidden; }
    @media (min-width: 576px) {
        .modal-dialog { max-width: 500px; margin: 1.75rem auto; }
        .modal-dialog-centered { min-height: calc(100% - 3.5rem); }
        .modal-lg { max-width: 800px; }
    }
    .btn-close {
        box-sizing: content-box; width: 1em; height: 1em; padding: .25em; color: #000;
        border: 0; border-radius: .375rem; opacity: .5; cursor: pointer;
        background: transparent url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath d='M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414z'/%3e%3c/svg%3e") center/1em auto no-repeat;
    }
    .btn-close:hover { opacity: .75; }
</style>
