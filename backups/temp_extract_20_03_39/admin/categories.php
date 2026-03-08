<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$message = '';
$messageType = '';
$editCat = null;

// ---------- Törlés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('SELECT name FROM categories WHERE id = ?');
        $stmt->execute([$_POST['delete_id']]);
        $delCat = $stmt->fetch();
        $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$_POST['delete_id']]);
        log_activity($db, 'delete', 'category', (int) $_POST['delete_id'], $delCat['name'] ?? '');
        $message = 'Kategória törölve.';
        $messageType = 'success';
    }
}

// ---------- Mentés (új vagy szerkesztés) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $name = trim($_POST['name'] ?? '');
        $slug = UrlHelper::sanitizeSlug($_POST['slug'] ?: $name);
        $desc = trim($_POST['description'] ?? '');
        $catId = $_POST['cat_id'] ?? '';

        if ($name === '') {
            $message = 'A név megadása kötelező.';
            $messageType = 'error';
        } else {
            if ($catId) {
                $db->prepare('UPDATE categories SET name = ?, slug = ?, description = ? WHERE id = ?')
                    ->execute([$name, $slug, $desc, $catId]);
                log_activity($db, 'update', 'category', (int) $catId, $name);
                $message = 'Kategória frissítve!';
            } else {
                $maxOrder = $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM categories')->fetchColumn();
                $db->prepare('INSERT INTO categories (name, slug, description, sort_order) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $slug, $desc, $maxOrder + 1]);
                log_activity($db, 'create', 'category', (int) $db->lastInsertId(), $name);
                $message = 'Kategória létrehozva!';
            }
            $messageType = 'success';
        }
    }
}

// ---------- Szerkesztés ----------
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$_GET['edit']]);
    $editCat = $stmt->fetch();
}

$categories = $db->query('SELECT c.*, (SELECT COUNT(*) FROM post_categories pc WHERE pc.category_id = c.id) as post_count FROM categories c ORDER BY c.sort_order ASC')->fetchAll();

admin_header('Kategóriák');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Szerkesztő form -->
<div style="background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 25px;">
    <h3 style="margin-bottom: 15px;">
        <?php echo $editCat ? '✏️ Kategória szerkesztése' : '➕ Új kategória'; ?>
    </h3>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="cat_id" value="<?php echo $editCat['id'] ?? ''; ?>">
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div class="form-group">
                <label>Név *</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($editCat['name'] ?? ''); ?>" required
                    style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Slug</label>
                <input type="text" name="slug" value="<?php echo htmlspecialchars($editCat['slug'] ?? ''); ?>"
                    placeholder="automatikus" style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Leírás</label>
                <input type="text" name="description"
                    value="<?php echo htmlspecialchars($editCat['description'] ?? ''); ?>" style="width: 100%;">
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <button type="submit" name="save" class="btn btn-success">💾 Mentés</button>
            <?php if ($editCat): ?>
                <a href="categories.php" class="btn" style="text-decoration: none;">Mégse</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Kategória lista -->
<?php if (empty($categories)): ?>
    <p style="color: #94a3b8;">Nincs még kategória.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Név</th>
                <th>Slug</th>
                <th>Bejegyzések</th>
                <th style="width: 120px;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><strong>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </strong></td>
                    <td><code><?php echo htmlspecialchars($c['slug']); ?></code></td>
                    <td>
                        <?php echo $c['post_count']; ?> db
                    </td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <a href="categories.php?edit=<?php echo $c['id']; ?>" class="btn"
                                style="padding: 4px 10px; font-size: 0.8rem; text-decoration: none;">✏️</a>
                            <form method="post" style="display: inline;"
                                onsubmit="return confirm('Biztosan törlöd? A hozzárendelések is törlődnek.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $c['id']; ?>">
                                <button type="submit" class="btn btn-danger"
                                    style="padding: 4px 10px; font-size: 0.8rem;">🗑️</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php
admin_footer();
