<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/UrlHelper.php';
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php render_seo_tags($page, $settings); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?php echo UrlHelper::themeAsset('style.css'); ?>">
</head>

<body>
    <header>
        <div class="container">
            <a href="<?php echo UrlHelper::link(); ?>" class="site-title">
                <?php if (!empty($settings['site_info']['logo'])): ?>
                    <img src="<?php echo UrlHelper::asset($settings['site_info']['logo']); ?>"
                        alt="<?php echo htmlspecialchars($settings['site_info']['name'] ?? 'Lexodus'); ?>">
                <?php else: ?>
                    <span style="font-size: 1.5rem; font-weight: 800; color: #003366;">
                        <?php echo htmlspecialchars($settings['site_info']['name'] ?? 'LEXODUS'); ?>
                    </span>
                <?php endif; ?>
            </a>
            <nav>
                <?php render_menu($db); ?>
            </nav>
        </div>
    </header>
    <div class="content">