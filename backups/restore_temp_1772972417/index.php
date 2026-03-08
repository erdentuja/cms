<?php
require_once 'config.php';
require_once 'core/UrlHelper.php';
require_once 'core/functions.php';

// ---------- Ütemezett bejegyzések auto-publikálása ----------
try {
    $db->exec("UPDATE posts SET status = 'published' WHERE status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= NOW()");
} catch (PDOException $e) {
    // publish_at mező még nem létezik – migráció szükséges
}

// ---------- Slug alapú oldal lekérdezés ----------
$slug = UrlHelper::sanitizeSlug($_GET['route'] ?? 'index');

$stmt = $db->prepare("SELECT * FROM posts WHERE slug = ? AND status = 'published' AND deleted_at IS NULL LIMIT 1");
$stmt->execute([$slug]);
$page = $stmt->fetch();

if (!$page && $slug !== 'index' && $slug !== '') {
    http_response_code(404);
    $page = [
        'title' => '404 - Az oldal nem található',
        'content' => '<p>A keresett oldal nem létezik.</p>',
        'post_type' => 'page',
    ];
}

if (!$page) {
    $page = [
        'title' => $settings['site_info']['name'] ?? 'Kezdőlap',
        'content' => '',
        'post_type' => 'page',
    ];
}

// ---------- Beállítások ----------
$settings = [];
$settingsQuery = $db->query("SELECT * FROM settings");
while ($row = $settingsQuery->fetch()) {
    $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
}

// ---------- Helper: olvasási idő ----------
function estimate_read_time($content) {
    $wordCount = str_word_count(strip_tags($content));
    $minutes = max(1, ceil($wordCount / 200));
    return $minutes . ' perc';
}

// ---------- Helper: kategória tag CSS class ----------
function get_category_tag_class($categoryName) {
    $name = mb_strtolower($categoryName);
    $map = [
        'financ' => 'tag-financing', 'pénz' => 'tag-financing', 'gazdaság' => 'tag-financing',
        'lifestyle' => 'tag-lifestyle', 'élet' => 'tag-lifestyle',
        'community' => 'tag-community', 'közösség' => 'tag-community',
        'wellness' => 'tag-wellness', 'egészség' => 'tag-wellness',
        'travel' => 'tag-travel', 'utazás' => 'tag-travel',
        'creativ' => 'tag-creativity', 'kreativ' => 'tag-creativity', 'művész' => 'tag-creativity',
        'growth' => 'tag-growth', 'fejlőd' => 'tag-growth', 'tanul' => 'tag-growth',
    ];
    foreach ($map as $key => $class) {
        if (strpos($name, $key) !== false) return $class;
    }
    // Fallback: rotate through colors based on name hash
    $colors = ['tag-financing', 'tag-lifestyle', 'tag-community', 'tag-wellness', 'tag-travel', 'tag-creativity', 'tag-growth'];
    return $colors[crc32($categoryName) % count($colors)];
}

// ---------- Megjelenítés ----------
include 'theme/header.php';

if ($slug === 'index' || $slug === ''):
    // ==================== FŐOLDAL ====================

    // Kiemelt cikkek lekérdezés
    $featuredPosts = $db->query("SELECT p.* FROM posts p WHERE p.post_type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 6")->fetchAll();
?>

    <!-- Hero Section -->
    <div class="container">
        <section class="hero-section">
            <div class="hero-image-wrap">
                <?php if (!empty($settings['hero']['hero_image'])): ?>
                    <img src="<?php echo UrlHelper::asset($settings['hero']['hero_image']); ?>" alt="Hero">
                <?php elseif (!empty($featuredPosts[0]['featured_image'])): ?>
                    <img src="<?php echo UrlHelper::asset($featuredPosts[0]['featured_image']); ?>" alt="Hero">
                <?php else: ?>
                    <div style="width:100%;height:100%;background:var(--secondary);"></div>
                <?php endif; ?>
            </div>
            <div class="hero-content">
                <h1 class="hero-title"><?php echo htmlspecialchars($settings['hero']['title'] ?? 'Üdvözöljük'); ?></h1>
                <p class="hero-subtitle"><?php echo htmlspecialchars($settings['hero']['description'] ?? ''); ?></p>
                <div class="hero-actions">
                    <?php if (!empty($settings['hero']['btn1_text'])): ?>
                        <a href="<?php echo htmlspecialchars($settings['hero']['btn1_link'] ?? '#'); ?>" class="btn-primary btn-lg">
                            <?php echo htmlspecialchars($settings['hero']['btn1_text']); ?>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($settings['hero']['btn2_text'])): ?>
                        <a href="<?php echo htmlspecialchars($settings['hero']['btn2_link'] ?? '#'); ?>" class="btn-outline btn-lg">
                            <?php echo htmlspecialchars($settings['hero']['btn2_text']); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <!-- Intro Section -->
    <div class="container">
        <section class="intro-section" data-animate="slide-up">
            <h2><?php echo htmlspecialchars($settings['site_info']['name'] ?? ''); ?></h2>
            <p><?php echo htmlspecialchars($settings['site_info']['description'] ?? ''); ?></p>
        </section>
    </div>

    <!-- Featured Articles -->
    <?php if (!empty($featuredPosts)): ?>
    <div class="container">
        <div class="section-header" data-animate="fade-in">
            <h2>Legújabb cikkek</h2>
            <a href="<?php echo UrlHelper::link('blog'); ?>">Összes cikk &rarr;</a>
        </div>

        <div class="article-grid">
            <?php foreach ($featuredPosts as $i => $post):
                // Kategóriák lekérdezés
                $catStmt = $db->prepare("SELECT c.name, c.slug FROM categories c JOIN post_categories pc ON c.id = pc.category_id WHERE pc.post_id = ?");
                $catStmt->execute([$post['id']]);
                $postCats = $catStmt->fetchAll();
                $firstCat = $postCats[0] ?? null;
                $staggerClass = 'stagger-' . min($i + 1, 6);
            ?>
                <a href="<?php echo UrlHelper::link($post['slug']); ?>" class="article-card" data-animate="slide-up" style="animation-delay: <?php echo ($i * 0.1); ?>s">
                    <div class="article-card-image">
                        <?php if (!empty($post['featured_image'])): ?>
                            <img src="<?php echo UrlHelper::asset($post['featured_image']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                        <?php else: ?>
                            <div style="width:100%;height:100%;background:linear-gradient(135deg, var(--secondary), var(--accent));"></div>
                        <?php endif; ?>
                        <div class="gradient-overlay"></div>
                    </div>
                    <div class="article-card-content">
                        <div class="article-card-top">
                            <?php if ($firstCat): ?>
                                <span class="category-tag <?php echo get_category_tag_class($firstCat['name']); ?>"><?php echo htmlspecialchars($firstCat['name']); ?></span>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                            <span class="date-badge"><?php echo date('Y.m.d', strtotime($post['created_at'] ?? $post['updated_at'] ?? 'now')); ?></span>
                        </div>
                        <div class="article-card-bottom">
                            <h3 class="article-card-title"><?php echo htmlspecialchars($post['title']); ?></h3>
                            <span class="floating-button">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Newsletter Section -->
    <div class="container">
        <section class="newsletter-section" data-animate="scale-in">
            <h2>Maradj naprakész</h2>
            <p class="subtitle">Iratkozz fel hírlevelünkre, és értesülj elsőként a legújabb tartalmakról.</p>
            <form class="newsletter-form" onsubmit="event.preventDefault();">
                <input type="email" placeholder="E-mail címed..." required>
                <button type="submit" class="btn-primary">Feliratkozás</button>
            </form>
        </section>
    </div>

<?php
elseif (isset($page['post_type']) && $page['post_type'] === 'post'):
    // ==================== BEJEGYZÉS RÉSZLETEK ====================

    // Kategóriák
    $catStmt = $db->prepare("SELECT c.name, c.slug FROM categories c JOIN post_categories pc ON c.id = pc.category_id WHERE pc.post_id = ?");
    $catStmt->execute([$page['id']]);
    $articleCats = $catStmt->fetchAll();
    $firstCat = $articleCats[0] ?? null;

    // Kapcsolódó cikkek
    $relatedPosts = [];
    if ($firstCat) {
        $relStmt = $db->prepare("SELECT p.* FROM posts p JOIN post_categories pc ON p.id = pc.post_id JOIN categories c ON c.id = pc.category_id WHERE c.slug = ? AND p.id != ? AND p.status = 'published' AND p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 3");
        $relStmt->execute([$firstCat['slug'], $page['id']]);
        $relatedPosts = $relStmt->fetchAll();
    }
    if (count($relatedPosts) < 3) {
        $excludeIds = array_merge([$page['id']], array_column($relatedPosts, 'id'));
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        $fillStmt = $db->prepare("SELECT * FROM posts WHERE post_type = 'post' AND status = 'published' AND deleted_at IS NULL AND id NOT IN ($placeholders) ORDER BY id DESC LIMIT " . (3 - count($relatedPosts)));
        $fillStmt->execute($excludeIds);
        $relatedPosts = array_merge($relatedPosts, $fillStmt->fetchAll());
    }
?>

    <div class="container animate-fade-in">
        <!-- Back link -->
        <a href="<?php echo UrlHelper::link('blog'); ?>" class="article-back-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Vissza a cikkekhez
        </a>

        <!-- Hero Image -->
        <?php if (!empty($page['featured_image'])): ?>
        <div class="article-hero-image">
            <img src="<?php echo UrlHelper::asset($page['featured_image']); ?>" alt="<?php echo htmlspecialchars($page['title']); ?>">
            <div class="hero-gradient"></div>
        </div>
        <?php endif; ?>

        <!-- Article Container -->
        <div class="article-container" <?php echo !empty($page['featured_image']) ? '' : 'style="margin-top:0"'; ?>>
            <!-- Meta -->
            <div class="article-meta animate-slide-up">
                <?php if ($firstCat): ?>
                    <span class="category-tag <?php echo get_category_tag_class($firstCat['name']); ?>"><?php echo htmlspecialchars($firstCat['name']); ?></span>
                <?php endif; ?>
                <span class="article-meta-text"><?php echo date('Y. F d.', strtotime($page['created_at'] ?? 'now')); ?></span>
                <span class="article-meta-text">&middot;</span>
                <span class="article-meta-text"><?php echo estimate_read_time($page['content'] ?? ''); ?> olvasás</span>
            </div>

            <!-- Title -->
            <h1 class="article-detail-title animate-slide-up"><?php echo htmlspecialchars($page['title']); ?></h1>

            <?php if (!empty($page['meta_description'])): ?>
                <p class="article-subtitle"><?php echo htmlspecialchars($page['meta_description']); ?></p>
            <?php endif; ?>

            <!-- Share buttons -->
            <div class="share-buttons">
                <button class="share-btn share-copy-btn" title="Link másolása">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                </button>
                <a class="share-btn" href="https://twitter.com/intent/tweet?url=<?php echo urlencode(UrlHelper::link($page['slug'] ?? '')); ?>&text=<?php echo urlencode($page['title']); ?>" target="_blank" rel="noopener" title="Megosztás Twitteren">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                </a>
                <a class="share-btn" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(UrlHelper::link($page['slug'] ?? '')); ?>" target="_blank" rel="noopener" title="Megosztás Facebookon">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                </a>
            </div>

            <!-- Content -->
            <div class="article-content">
                <?php echo parse_shortcodes($db, $page['content'] ?? ''); ?>
            </div>

            <!-- Category tags -->
            <?php if (!empty($articleCats)): ?>
            <div class="article-tags">
                <?php foreach ($articleCats as $cat): ?>
                    <a href="<?php echo UrlHelper::link('blog?cat=' . urlencode($cat['slug'])); ?>" class="tag"><?php echo htmlspecialchars($cat['name']); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Newsletter CTA -->
            <section class="newsletter-section">
                <h2>Tetszett a cikk?</h2>
                <p class="subtitle">Iratkozz fel, hogy ne maradj le a legújabb tartalmakról.</p>
                <form class="newsletter-form" onsubmit="event.preventDefault();">
                    <input type="email" placeholder="E-mail címed..." required>
                    <button type="submit" class="btn-primary">Feliratkozás</button>
                </form>
            </section>
        </div>
    </div>

    <!-- Related Articles -->
    <?php if (!empty($relatedPosts)): ?>
    <div class="container">
        <section class="related-section" data-animate="fade-in">
            <div class="container">
                <h2>Kapcsolódó cikkek</h2>
                <div class="article-grid">
                    <?php foreach ($relatedPosts as $i => $rPost):
                        $rcStmt = $db->prepare("SELECT c.name FROM categories c JOIN post_categories pc ON c.id = pc.category_id WHERE pc.post_id = ? LIMIT 1");
                        $rcStmt->execute([$rPost['id']]);
                        $rCat = $rcStmt->fetch();
                    ?>
                        <a href="<?php echo UrlHelper::link($rPost['slug']); ?>" class="article-card" data-animate="slide-up" style="animation-delay: <?php echo ($i * 0.1); ?>s">
                            <div class="article-card-image">
                                <?php if (!empty($rPost['featured_image'])): ?>
                                    <img src="<?php echo UrlHelper::asset($rPost['featured_image']); ?>" alt="<?php echo htmlspecialchars($rPost['title']); ?>">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;background:linear-gradient(135deg, var(--secondary), var(--accent));"></div>
                                <?php endif; ?>
                                <div class="gradient-overlay"></div>
                            </div>
                            <div class="article-card-content">
                                <div class="article-card-top">
                                    <?php if ($rCat): ?>
                                        <span class="category-tag <?php echo get_category_tag_class($rCat['name']); ?>"><?php echo htmlspecialchars($rCat['name']); ?></span>
                                    <?php else: ?>
                                        <span></span>
                                    <?php endif; ?>
                                    <span class="date-badge"><?php echo date('Y.m.d', strtotime($rPost['created_at'] ?? $rPost['updated_at'] ?? 'now')); ?></span>
                                </div>
                                <div class="article-card-bottom">
                                    <h3 class="article-card-title"><?php echo htmlspecialchars($rPost['title']); ?></h3>
                                    <span class="floating-button">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="7 7 17 7 17 17"/></svg>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
    <?php endif; ?>

<?php
else:
    // ==================== EGYÉB OLDAL (page) ====================
?>
    <div class="container" style="padding-top: 2rem; min-height: 50vh;">
        <h1 data-animate="slide-up"><?php echo htmlspecialchars($page['title']); ?></h1>
        <div class="page-content" data-animate="fade-in">
            <?php echo parse_shortcodes($db, $page['content'] ?? ''); ?>
        </div>
    </div>

<?php endif; ?>

<?php include 'theme/footer.php'; ?>
