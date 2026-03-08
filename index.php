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

if (!$page) {
    $page = [
        'title' => 'Üdvözöljük',
        'content' => '<p>Kezdje el a tartalomépítést az adminban!</p>',
    ];
}

// ---------- Beállítások ----------
$settings = [];
$settingsQuery = $db->query("SELECT * FROM settings");
while ($row = $settingsQuery->fetch()) {
    $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
}

// ---------- Megjelenítés ----------
include 'theme/header.php';

if ($slug === 'index' || $slug === ''): ?>
    <section class="hero">
        <div class="container">
            <h1 style="color: #fff; font-size: 3rem; margin-bottom: 20px;">
                <?php echo htmlspecialchars($settings['hero']['title'] ?? 'Üdvözöljük a LEXODUS Kft. megújult oldalán'); ?>
            </h1>
            <p style="color: rgba(255,255,255,0.9); font-size: 1.2rem; max-width: 800px; margin: 0 auto 30px auto;">
                <?php echo nl2br(htmlspecialchars($settings['hero']['description'] ?? 'Az IFS magyarországi képviselete...')); ?>
            </p>
            <div style="display: flex; gap: 15px; justify-content: center;">
                <?php if (!empty($settings['hero']['btn1_text'])): ?>
                    <a href="<?php echo htmlspecialchars($settings['hero']['btn1_link'] ?? '#services'); ?>" class="btn-modern">
                        <?php echo htmlspecialchars($settings['hero']['btn1_text']); ?>
                    </a>
                <?php endif; ?>

                <?php if (!empty($settings['hero']['btn2_text'])): ?>
                    <a href="<?php echo htmlspecialchars($settings['hero']['btn2_link'] ?? '/kapcsolat'); ?>" class="btn-modern"
                        style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3); backdrop-filter: blur(5px);">
                        <?php echo htmlspecialchars($settings['hero']['btn2_text']); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php endif; ?>

<div class="container" style="padding-top: 40px;">
    <?php if ($slug !== 'index' && $slug !== ''): ?>
        <h1 style="border-bottom: 3px solid #003366; padding-bottom: 10px; display: inline-block;">
            <?php echo htmlspecialchars($page['title']); ?>
        </h1>
    <?php endif; ?>
    <div class="page-content">
        <?php
        // Tartalom megjelenítése shortcode-okkal
        $content = parse_shortcodes($db, $page['content'] ?? '');
        echo $content;
        ?>
    </div>
</div>

<?php if ($slug === 'index' || $slug === ''): ?>
    <section id="services" style="padding: 100px 0; background: #fff;">
        <div class="container">
            <h2 style="text-align: center; color: #003366; margin-bottom: 50px;">Szakterületeink</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px;">
                <div class="lex-card">
                    <h3 style="font-size: 1.25rem;">IFS Tanúsítás</h3>
                    <p style="color: #64748b; font-size: 0.95rem;">Teljes körű felkészítés és tanácsadás bármelyik IFS
                        szabvány megszerzéséhez.</p>
                </div>
                <div class="lex-card">
                    <h3 style="font-size: 1.25rem;">Minőségirányítás</h3>
                    <p style="color: #64748b; font-size: 0.95rem;">Egyedi rendszerek kidolgozása és auditálása az
                        élelmiszerlánc minden szereplője számára.</p>
                </div>
                <div class="lex-card">
                    <h3 style="font-size: 1.25rem;">Szakmai Képzések</h3>
                    <p style="color: #64748b; font-size: 0.95rem;">Gyakorlatorientált oktatások és workshopok az aktuális
                        iparági elvárások mentén.</p>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php
include 'theme/footer.php';
?>