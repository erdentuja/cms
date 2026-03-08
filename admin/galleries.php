<?php
require_once '../config.php';
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

// Törlés
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        $id = (int) $_POST['delete_id'];
        $stmt = $db->prepare("SELECT title FROM galleries WHERE id = ?");
        $stmt->execute([$id]);
        $title = $stmt->fetchColumn();

        if ($title) {
            $db->prepare("DELETE FROM galleries WHERE id = ?")->execute([$id]);
            log_activity($db, 'delete', 'gallery', $id, $title);
            $message = 'Galéria törölve.';
            $messageType = 'success';
        }
    }
}

// Új galéria (ha nem a médiatárból jön)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_gallery'])) {
    if (csrf_verify()) {
        $title = trim($_POST['title'] ?? '');
        if ($title) {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
            $stmt = $db->prepare("INSERT INTO galleries (title, slug) VALUES (?, ?)");
            $stmt->execute([$title, $slug]);
            $id = $db->lastInsertId();
            log_activity($db, 'create', 'gallery', (int) $id, $title);
            header("Location: gallery_edit.php?id=$id");
            exit;
        }
    }
}

$galleries = $db->query("
    SELECT g.*, COUNT(gi.id) as item_count 
    FROM galleries g 
    LEFT JOIN gallery_items gi ON g.id = gi.gallery_id 
    GROUP BY g.id 
    ORDER BY g.created_at DESC
")->fetchAll();

admin_header('Galériák kezelése');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 30px;">
    <h3 style="margin-bottom: 15px;">➕ Új galéria létrehozása</h3>
    <form method="post" class="form-inline">
        <?php echo csrf_field(); ?>
        <input type="text" name="title" placeholder="Galéria neve (pl. Referenciák)" required
            style="flex: 1; min-width: 300px;">
        <button type="submit" name="create_gallery" class="btn btn-success">Létrehozás és szerkesztés</button>
    </form>
</div>

<?php if (empty($galleries)): ?>
    <p
        style="color: #64748b; text-align: center; padding: 40px; background: #fff; border-radius: 10px; border: 1px solid #e5e7eb;">
        Nincs még létrehozott galéria.</p>
<?php else: ?>
    <div style="background: #fff; border-radius: 10px; border: 1px solid #e5e7eb; overflow: hidden;">
        <table>
            <thead>
                <tr>
                    <th>Cím</th>
                    <th>Slug</th>
                    <th>Képek száma</th>
                    <th>Létrehozva</th>
                    <th style="text-align: right;">Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($galleries as $g): ?>
                    <tr>
                        <td style="font-weight: 600;">
                            <?php echo htmlspecialchars($g['title']); ?>
                        </td>
                        <td style="color: #64748b; font-size: 0.9rem;"><code><?php echo htmlspecialchars($g['slug']); ?></code>
                        </td>
                        <td>
                            <?php echo $g['item_count']; ?> db
                        </td>
                        <td style="color: #64748b; font-size: 0.9rem;">
                            <?php echo date('Y.m.d. H:i', strtotime($g['created_at'])); ?>
                        </td>
                        <td style="text-align: right;">
                            <a href="gallery_edit.php?id=<?php echo $g['id']; ?>" class="btn"
                                style="padding: 5px 12px; font-size: 0.85rem;">✏️ Szerkesztés</a>
                            <form method="post" style="display: inline;"
                                onsubmit="return confirm('Biztosan törlöd ezt a galériát? Maguk a képek megmaradnak a médiatárban.');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $g['id']; ?>">
                                <button type="submit" class="btn btn-danger"
                                    style="padding: 5px 10px; font-size: 0.85rem;">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
admin_footer();
