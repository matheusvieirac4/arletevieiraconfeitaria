<?php
// Sobrescreve as cores do AdminKit para o kit da marca (vinho #a51d32).
// Incluído no <head> pelo _header.php e pelo login.php.
?>
<style>
    :root {
        --bs-primary: #a51d32;
        --bs-primary-rgb: 165, 29, 50;
        --bs-link-color: #a51d32;
        --bs-link-color-rgb: 165, 29, 50;
        --bs-link-hover-color: #86121f;
    }
    .btn-primary {
        --bs-btn-bg: #a51d32;            --bs-btn-border-color: #a51d32;
        --bs-btn-hover-bg: #8d1a2b;      --bs-btn-hover-border-color: #86121f;
        --bs-btn-active-bg: #86121f;     --bs-btn-active-border-color: #7a101a;
        --bs-btn-disabled-bg: #a51d32;   --bs-btn-disabled-border-color: #a51d32;
    }
    .btn-outline-primary {
        --bs-btn-color: #a51d32;         --bs-btn-border-color: #a51d32;
        --bs-btn-hover-bg: #a51d32;      --bs-btn-hover-border-color: #a51d32;
        --bs-btn-active-bg: #a51d32;     --bs-btn-active-border-color: #a51d32;
    }
    .text-primary { color: #a51d32 !important; }
    .bg-primary   { background-color: #a51d32 !important; }
    .badge.bg-primary { background-color: #a51d32 !important; }
    main.content a:not(.btn):not(.dropdown-item) { color: #a51d32; }
    main.content a:not(.btn):not(.dropdown-item):hover { color: #86121f; }
    .form-control:focus, .form-select:focus {
        border-color: #cf8792;
        box-shadow: 0 0 0 .25rem rgba(165, 29, 50, .2);
    }
    /* Destaque do item ativo da sidebar na cor da marca */
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
