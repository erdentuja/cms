<?php
/**
 * Blog lista – Bejegyzések megjelenítése lapozóval
 */
require_once 'config.php';
require_once 'core/UrlHelper.php';
require_once 'core/functions.php';

// ---------- Beállítások ----------
$settings = [];
$settingsQuery = $db->query("SELECT * FROM settings");
while ($row = $settingsQuery->fetch()) {
    $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
}

// ---------- Lapozó ----------
$perPage = 6;
$currentPage = max(1, (int) ($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

// ---------- Kategória szűrő ----------
$catSlug = $_GET['cat'] ?? '';
$catInfo = null;
$whereExtra = '';
$params = [];

if ($catSlug) {
    $catStmt = $db->prepare("SELECT * FROM categories WHERE slug = ?");
    $catStmt->execute([$catSlug]);
    $catInfo = $catStmt->fetch();
    if ($catInfo) {
        $whereExtra = " AND p.id IN (SELECT post_id FROM post_categories WHERE category_id = ?)";
        $params[] = $catInfo['id'];
    }
}

// ---------- Bejegyzések lekérdezés ----------
$countSql = "SELECT COUNT(*) FROM posts p WHERE p.post_type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL" . $whereExtra;
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalPosts = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalPosts / $perPage));

$sql = "SELECT p.* FROM posts p WHERE p.post_type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL" . $whereExtra . " ORDER BY p.id DESC LIMIT $perPage OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Kategóriák előzetes lekérése a posztokhoz (N+1 probléma elkerülése)
$postCategoriesMap = [];
if (!empty($posts)) {
    $postIds = array_column($posts, 'id');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));
    $catSql = "SELECT pc.post_id, c.name, c.slug
               FROM categories c
               JOIN post_categories pc ON c.id = pc.category_id
               WHERE pc.post_id IN ($placeholders)";
    $catStmt = $db->prepare($catSql);
    $catStmt->execute($postIds);
    $allCats = $catStmt->fetchAll();

    foreach ($allCats as $cat) {
        $postCategoriesMap[$cat['post_id']][] = $cat;
    }
}

// ---------- Kategóriák ----------
$categories = $db->query("SELECT c.*, (SELECT COUNT(*) FROM post_categories pc JOIN posts p2 ON pc.post_id = p2.id WHERE pc.category_id = c.id AND p2.status = 'published' AND p2.post_type = 'post' AND p2.deleted_at IS NULL) as post_count FROM categories c ORDER BY c.name")->fetchAll();

// Dummy page for header
$page = [
    'title' => $catInfo ? $catInfo['name'] : 'Blog',
    'meta_title' => $catInfo ? $catInfo['name'] . ' – Blog' : 'Blog',
    'meta_description' => $catInfo ? ($catInfo['description'] ?? '') : ($settings['site_info']['description'] ?? ''),
];

include 'theme/header.php';
?>

<div class="container">
    <h1>
        <?php echo htmlspecialchars($page['title']); ?>
    </h1>

    <?php if (!empty($categories)): ?>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin: 20px 0;">
            <a href="<?php echo UrlHelper::link('blog'); ?>"
                style="padding: 5px 14px; border-radius: 20px; font-size: 0.85rem; text-decoration: none;
                      <?php echo !$catSlug ? 'background: #2563eb; color: #fff;' : 'background: #f1f5f9; color: #64748b;'; ?>">
                Összes
            </a>
            <?php foreach ($categories as $cat): ?>
                <?php if ($cat['post_count'] > 0): ?>
                    <a href="<?php echo UrlHelper::link('blog?cat=' . urlencode($cat['slug'])); ?>"
                        style="padding: 5px 14px; border-radius: 20px; font-size: 0.85rem; text-decoration: none;
                              <?php echo $catSlug === $cat['slug'] ? 'background: #2563eb; color: #fff;' : 'background: #f1f5f9; color: #64748b;'; ?>">
                        <?php echo htmlspecialchars($cat['name']); ?> (
                        <?php echo $cat['post_count']; ?>)
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
        <p style="color: #94a3b8; padding: 40px 0;">Nincs bejegyzés
            <?php echo $catInfo ? ' ebben a kategóriában' : ''; ?>.
        </p>
    <?php else: ?>
        <div
            style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; margin-top: 20px;">
            <?php foreach ($posts as $post): ?>
                <article
                    style="background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.06); transition: transform 0.2s, box-shadow 0.2s;"
                    onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 25px rgba(0,0,0,0.1)'"
                    onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 10px rgba(0,0,0,0.06)'">
                    <?php if (!empty($post['featured_image'])): ?>
                        <a href="<?php echo UrlHelper::link($post['slug']); ?>">
                            <img src="<?php echo UrlHelper::asset($post['featured_image']); ?>"
                                alt="<?php echo htmlspecialchars($post['title']); ?>"
                                style="width: 100%; height: 200px; object-fit: cover; display: block;">
                        </a>
                    <?php else: ?>
                        <div style="width: 100%; height: 200px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                        </div>
                    <?php endif; ?>
                    <div style="padding: 20px;">
                        <?php
                        // Kategóriák
                        $postCatList = $postCategoriesMap[$post['id']] ?? [];
                        if ($postCatList): ?>
                            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 8px;">
                                <?php foreach ($postCatList as $pc): ?>
                                    <a href="<?php echo UrlHelper::link('blog?cat=' . urlencode($pc['slug'])); ?>"
                                        style="font-size: 0.75rem; padding: 2px 8px; background: #eff6ff; color: #2563eb; border-radius: 10px; text-decoration: none;">
                                        <?php echo htmlspecialchars($pc['name']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <h2 style="font-size: 1.15rem; margin-bottom: 8px; line-height: 1.4;">
                            <a href="<?php echo UrlHelper::link($post['slug']); ?>"
                                style="color: #1e293b; text-decoration: none;">
                                <?php echo htmlspecialchars($post['title']); ?>
                            </a>
                        </h2>
                        <p style="color: #64748b; font-size: 0.9rem; line-height: 1.5;">
                            <?php echo htmlspecialchars(mb_strimwidth(html_entity_decode(strip_tags($post['content']), ENT_QUOTES, 'UTF-8'), 0, 120, '...')); ?>
                        </p>
                        <a href="<?php echo UrlHelper::link($post['slug']); ?>"
                            style="display: inline-block; margin-top: 12px; color: #2563eb; font-weight: 600; font-size: 0.9rem; text-decoration: none;">
                            Tovább olvasás →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Lapozó -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: center; gap: 8px; margin: 40px 0;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="<?php echo UrlHelper::link('blog?p=' . $i . ($catSlug ? '&cat=' . urlencode($catSlug) : '')); ?>"
                        style="padding: 8px 14px; border-radius: 6px; text-decoration: none; font-weight: 600;
                              <?php echo $i === $currentPage
                                  ? 'background: #2563eb; color: #fff;'
                                  : 'background: #f1f5f9; color: #64748b;'; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
include 'theme/footer.php';
