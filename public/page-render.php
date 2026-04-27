<?php
/**
 * Dynamic page renderer — renders pages stored in yy_page.
 * Uses the standard site template: header, nav, heading, content, footer.
 */
function renderDynamicPage(array $page): void {
    $title = htmlspecialchars($page['page_title'] ?? $page['page_heading'] ?? $page['page_code'] ?? 'Page');
    $heading = htmlspecialchars($page['page_heading'] ?? '');
    $subheading = htmlspecialchars($page['page_subheading'] ?? '');
    $description = htmlspecialchars($page['page_description'] ?? '');
    $body = $page['page_body'] ?? '';
    $headingColor = $page['page_heading_color'] ?? '';
    $headingSize = $page['page_heading_size'] ?? '';
    $subheadingColor = $page['page_subheading_color'] ?? '';
    $subheadingSize = $page['page_subheading_size'] ?? '';
    $descColor = $page['page_description_color'] ?? '';
    $descSize = $page['page_description_size'] ?? '';
    $bgColor = $page['page_background_color'] ?? '';

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Julius+Sans+One&family=Maven+Pro:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/page-heading.css?v=2">
    <style>
        @font-face { font-family: 'YadaTowrah-Times'; src: url('/fonts/YadaTowrah-Times.woff2') format('woff2'), url('/fonts/YadaTowrah-Times.ttf') format('truetype'); }
        @font-face { font-family: 'Semitic Early'; src: url('/fonts/SemiticEarly.ttf') format('truetype'); }
        * { box-sizing: border-box; }
        html, body { overflow-x: hidden; }
        html { height: 100%; }
        body { font-family: 'Maven Pro', Arial, sans-serif; margin: 0; padding: 0; background: <?= $bgColor ?: '#f5f5f5' ?>; color: #333; min-height: 100vh; display: flex; flex-direction: column; }

        header { display: flex; flex-direction: column; align-items: center; padding: 0; background: url('/images/yy-bg-bluewave.jpg') center/cover no-repeat; position: relative; overflow: hidden; }
        header .header-video { position: absolute; top: 50%; left: 50%; min-width: 100%; min-height: 100%; transform: translate(-50%, -50%); object-fit: cover; z-index: 0; }
        header .header-inner { display: flex; flex-direction: column; align-items: center; width: 100%; max-width: 1140px; margin: 0 auto; position: relative; z-index: 1; }
        header .header-logos { display: flex; flex-direction: column; align-items: center; gap: 5px; }
        header .header-logos img { width: auto; height: auto; }
        header nav { display: flex; flex-direction: column; align-items: center; gap: 0; padding: 0; }
        header nav .nav-row { display: flex; flex-direction: row; justify-content: center; gap: 0 30px; padding: 4px 0; flex-wrap: wrap; }
        header nav a { text-decoration: none; color: #E5C86C; font-family: "Julius Sans One", sans-serif; font-size: 1.1em; font-weight: 700; }
        header nav a:hover { color: #fff; }
        header nav a.active { color: #fff; text-decoration: underline; text-underline-offset: 5px; }

        .sub-toolbar { display: flex; justify-content: center; align-items: center; gap: 30px; padding: 8px 0; background: var(--toolbar-bg, #0A333A); margin: 0; }
        .sub-toolbar a { text-decoration: none; color: var(--toolbar-text, #8ED0EC); font-family: "Julius Sans One", sans-serif; font-size: 0.95em; font-weight: 700; }
        .sub-toolbar a:hover { color: #fff; }
        .sub-toolbar a.active { color: #fff; text-decoration: underline; text-underline-offset: 5px; }

        .content { width: 100%; max-width: 1100px; margin: 0 auto; padding: 0 24px 60px; flex: 1; }

        .page-subheading {
            text-align: center;
            font-family: 'Maven Pro', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            color: #666;
            margin: 0 0 8px;
        }
        .page-description {
            text-align: center;
            font-size: 0.95rem;
            color: #555;
            max-width: 800px;
            margin: 0 auto 24px;
            line-height: 1.6;
        }
        .page-body {
            line-height: 1.7;
            font-size: 1rem;
        }
        .page-body img { max-width: 100%; height: auto; border-radius: 6px; margin: 8px 0; }
        .page-body table { border-collapse: collapse; width: 100%; margin: 16px 0; }
        .page-body td, .page-body th { border: 1px solid #ddd; padding: 8px 12px; }
        .page-body blockquote { border-left: 3px solid #E5C86C; margin: 12px 0; padding: 8px 16px; background: #fafafa; }
        .page-body pre { background: #f4f4f4; padding: 12px; border-radius: 6px; overflow-x: auto; }
        .page-body a { color: #31345A; }
    </style>
</head>
<body>

<header>
    <video class="header-video" autoplay muted loop playsinline></video>
    <div class="header-inner">
        <div class="header-logos"><a href="/"><img src="" alt=""></a></div>
        <nav></nav>
    </div>
</header>
<div class="sub-toolbar"></div>

<?php if ($heading): ?>
<div class="page-heading-wrap"><h1 class="page-heading"<?php if ($headingColor || $headingSize) echo ' style="' . ($headingColor ? 'color:' . htmlspecialchars($headingColor) . ';' : '') . ($headingSize ? 'font-size:' . htmlspecialchars($headingSize) . 'em;' : '') . '"'; ?>><?= $heading ?></h1></div>
<?php endif; ?>

<div class="content">
    <?php if ($subheading): ?>
    <p class="page-subheading"<?php if ($subheadingColor || $subheadingSize) echo ' style="' . ($subheadingColor ? 'color:' . htmlspecialchars($subheadingColor) . ';' : '') . ($subheadingSize ? 'font-size:' . htmlspecialchars($subheadingSize) . 'em;' : '') . '"'; ?>><?= $subheading ?></p>
    <?php endif; ?>

    <?php if ($description): ?>
    <p class="page-description"<?php if ($descColor || $descSize) echo ' style="' . ($descColor ? 'color:' . htmlspecialchars($descColor) . ';' : '') . ($descSize ? 'font-size:' . htmlspecialchars($descSize) . 'em;' : '') . '"'; ?>><?= $description ?></p>
    <?php endif; ?>

    <?php if ($body): ?>
    <div class="page-body"><?= $body ?></div>
    <?php endif; ?>
</div>

<footer></footer>

<script src="/js/site-nav.js"></script>
<script src="/js/bg-video.js"></script>
<script src="/js/site-footer.js?v=15"></script>
</body>
</html>
<?php
}
