<?php
require_once __DIR__ . '/../includes/links_bio.php';
$linksBio = links_bio_ativos_ordenados();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Links Oficiais | Arlete Vieira Confeitaria</title>
    <meta name="description" content="Acesse os links oficiais da Arlete Vieira Confeitaria: WhatsApp, cardapio, iFood, presentes corporativos, site e localizacao.">
    <meta name="keywords" content="Arlete Vieira Confeitaria, link da bio, WhatsApp, iFood, cardapio, confeitaria Sao Jose SC">
    <meta name="author" content="Arlete Vieira Confeitaria">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="https://links.arletevieiraconfeitaria.com.br/">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Arlete Vieira Confeitaria">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:title" content="Links Oficiais | Arlete Vieira Confeitaria">
    <meta property="og:description" content="Todos os links oficiais da Arlete Vieira Confeitaria em um so lugar.">
    <meta property="og:url" content="https://links.arletevieiraconfeitaria.com.br/">
    <meta property="og:image" content="https://arletevieiraconfeitaria.com.br/img/imagens/metatag-img.jpg">
    <meta property="og:image:secure_url" content="https://arletevieiraconfeitaria.com.br/img/imagens/metatag-img.jpg">
    <meta property="og:image:alt" content="Arlete Vieira Confeitaria - links oficiais">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Links Oficiais | Arlete Vieira Confeitaria">
    <meta name="twitter:description" content="WhatsApp, cardapio, iFood, presentes corporativos e mais.">
    <meta name="twitter:image" content="https://arletevieiraconfeitaria.com.br/img/imagens/metatag-img.jpg">
    <meta name="theme-color" content="#a51d32">
    <link rel="icon" href="https://arletevieiraconfeitaria.com.br/img/favicon.png">
    <link rel="apple-touch-icon" href="https://arletevieiraconfeitaria.com.br/img/apple-touch-icon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "WebPage",
            "name": "Links Oficiais | Arlete Vieira Confeitaria",
            "url": "https://links.arletevieiraconfeitaria.com.br/",
            "description": "Pagina oficial com os principais links da Arlete Vieira Confeitaria.",
            "inLanguage": "pt-BR",
            "publisher": {
                "@type": "Organization",
                "name": "Arlete Vieira Confeitaria",
                "url": "https://arletevieiraconfeitaria.com.br/"
            }
        }
    </script>
    <style>
        :root {
            --primary: #a51d32;
            --primary-dark: #7d1626;
            --accent: #fdbfe4;
            --text: #231f20;
            --bg: #fff8fb;
            --card: #ffffff;
            --muted: #736a6d;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Poppins", Arial, sans-serif;
            color: var(--text);
            background: radial-gradient(circle at 20% 0%, #ffe5f3 0%, var(--bg) 45%, #fff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        .card {
            width: 100%;
            max-width: 520px;
            background: var(--card);
            border-radius: 24px;
            padding: 28px 20px 20px;
            box-shadow: 0 14px 50px rgba(165, 29, 50, 0.16);
            border: 1px solid #ffe3ef;
        }

        .brand {
            text-align: center;
            margin-bottom: 20px;
        }

        .brand img {
            width: 170px;
            max-width: 70%;
            height: auto;
            margin-bottom: 12px;
        }

        h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--primary);
            line-height: 1.2;
        }

        .subtitle {
            margin: 8px auto 0;
            font-size: 0.95rem;
            color: var(--muted);
            max-width: 340px;
        }

        .links {
            margin-top: 22px;
            display: grid;
            gap: 12px;
        }

        .link-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px 16px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.97rem;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
            border: 1px solid transparent;
            text-align: center;
        }

        .link-btn.primary {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 8px 24px rgba(165, 29, 50, 0.32);
        }

        .link-btn.primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .link-btn.secondary {
            background: #fff;
            color: var(--text);
            border-color: #f2d4de;
        }

        .link-btn.secondary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(35, 31, 32, 0.1);
            border-color: #e9b9cb;
        }

        .legacy {
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px dashed #efcfdb;
            font-size: 0.84rem;
            color: var(--muted);
            text-align: center;
        }

        .legacy a {
            color: var(--primary);
            text-decoration: none;
            word-break: break-word;
        }

        .legacy a:hover {
            text-decoration: underline;
        }

        .footer {
            margin-top: 16px;
            font-size: 0.78rem;
            color: #8a8184;
            text-align: center;
        }
    </style>
</head>
<body>
    <main class="card">
        <section class="brand">
            <img src="https://arletevieiraconfeitaria.com.br/img/logo.png" alt="Logo Arlete Vieira Confeitaria">
            <h1>Arlete Vieira Confeitaria</h1>
            <p class="subtitle">A arte de fazer doces com excelencia. Escolha o melhor caminho para falar com a gente.</p>
        </section>

        <section class="links" aria-label="Links principais">
            <?php foreach ($linksBio as $link):
                $slug = isset($link['slug']) ? $link['slug'] : '';
                $estilo = (isset($link['estilo']) && $link['estilo'] === 'primary') ? 'primary' : 'secondary';
            ?>
            <a class="link-btn <?= htmlspecialchars($estilo) ?>"
               href="click.php?l=<?= rawurlencode($slug) ?>&s=bio"
               target="_blank"
               rel="noopener noreferrer">
                <?= htmlspecialchars(isset($link['rotulo']) ? $link['rotulo'] : '') ?>
            </a>
            <?php endforeach; ?>
        </section>


        <p class="footer">Atendimento em São José/SC</p>
    </main>
</body>
</html>
