<?php
function current_user_role()
{
    return $_SESSION['admin_role'] ?? 'registered';
}
function is_super_admin()
{
    return current_user_role() === 'super_admin';
}
function is_admin()
{
    $role = current_user_role();
    return $role === 'super_admin' || $role === 'admin';
}

function render_seo_tags($page, $settings)
{
    $site_name = $settings['site_info']['name'] ?? 'CMS';
    $title = !empty($page['meta_title']) ? $page['meta_title'] : ($page['title'] ?? 'Főoldal');
    $desc = !empty($page['meta_description']) ? $page['meta_description'] : ($settings['site_info']['description'] ?? '');
    echo "<title>" . htmlspecialchars($title) . " | " . htmlspecialchars($site_name) . "</title>\n";
    echo '<meta name="description" content="' . htmlspecialchars($desc) . '">' . "\n";
}
function render_menu($db)
{
    $items = $db->query("SELECT * FROM menus ORDER BY sort_order ASC")->fetchAll();
    echo "<ul>";
    foreach ($items as $item) {
        $url = (strpos($item['url'], 'http') === 0) ? $item['url'] : UrlHelper::link($item['url']);
        echo "<li><a href='" . htmlspecialchars($url) . "'>" . htmlspecialchars($item['label']) . "</a></li>";
    }
    echo "</ul>";
}

function log_activity($db, $action, $targetType = null, $targetId = null, $targetTitle = null, $details = null)
{
    $userId = $_SESSION['admin_id'] ?? null;
    $username = $_SESSION['admin_user'] ?? 'ismeretlen';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    try {
        $stmt = $db->prepare(
            'INSERT INTO activity_log (user_id, username, action, target_type, target_id, target_title, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $username, $action, $targetType, $targetId, $targetTitle, $details, $ip]);
    } catch (PDOException $e) {
        // Tábla még nem létezik – silent fail
    }
}

/**
 * [gallery id="123"] shortcode-ok keresése és cseréje
 */
function parse_shortcodes($db, $content)
{
    if (empty($content))
        return $content;

    // Regex a [gallery id="X"] formátumra
    $pattern = '/\[gallery\s+id=["\'](\d+)["\']\]/i';

    $content = preg_replace_callback($pattern, function ($matches) use ($db) {
        $galleryId = $matches[1];
        return render_gallery($db, $galleryId);
    }, $content);

    return $content;
}

/**
 * Egy adott galéria HTML kódjának legenerálása
 */
function render_gallery($db, $galleryId)
{
    try {
        $stmt = $db->prepare("
            SELECT m.* FROM media m
            JOIN gallery_items gi ON m.id = gi.media_id
            WHERE gi.gallery_id = ?
            ORDER BY gi.sort_order ASC
        ");
        $stmt->execute([$galleryId]);
        $items = $stmt->fetchAll();

        if (empty($items))
            return '';

        ob_start();
        ?>
        <div class="cms-gallery-grid"
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin: 25px 0;">
            <?php foreach ($items as $item): ?>
                <div class="cms-gallery-item"
                    style="border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <a href="uploads/<?php echo htmlspecialchars($item['filepath']); ?>"
                        data-fslightbox="gallery-<?php echo $galleryId; ?>">
                        <img src="uploads/<?php echo htmlspecialchars($item['filepath']); ?>"
                            alt="<?php echo htmlspecialchars($item['alt_text'] ?: $item['filename']); ?>"
                            style="width: 100%; height: 200px; object-fit: cover; display: block; transition: transform 0.3s;"
                            onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    } catch (PDOException $e) {
        return "<!-- Gallery Error: " . $e->getMessage() . " -->";
    }
}

/**
 * Munkamenet időtúllépés ellenőrzése
 */
function check_session_timeout($db)
{
    if (!isset($_SESSION['admin_id'])) {
        return;
    }

    // Beállítások betöltése
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'security' LIMIT 1");
    $stmt->execute();
    $security = json_decode($stmt->fetchColumn() ?: '{}', true);

    $neverExpire = $security['session_never_expire'] ?? 0;
    if ($neverExpire) {
        return;
    }

    $lifetimeMinutes = intval($security['session_lifetime'] ?? 30);
    $lifetimeSeconds = $lifetimeMinutes * 60;

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $lifetimeSeconds)) {
        // Időtúllépés történt
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_message'] = 'A munkamenet időtúllépés miatt lejárt. Kérjük, jelentkezzen be újra!';
        $_SESSION['flash_type'] = 'error';
        header('Location: index.php');
        exit;
    }

    // Utolsó aktivitás frissítése
    $_SESSION['last_activity'] = time();
}
?>