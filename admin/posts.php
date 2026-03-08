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

// ---------- Aktuális típus (szűrés) ----------
$currentType = $_GET['type'] ?? 'all';
$showTrash = ($currentType === 'trash');

// ---------- Soft Delete (lomtárba) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('SELECT title, post_type FROM posts WHERE id = ?');
        $stmt->execute([$_POST['delete_id']]);
        $delPost = $stmt->fetch();
        if ($delPost) {
            $db->prepare('UPDATE posts SET deleted_at = NOW() WHERE id = ?')->execute([$_POST['delete_id']]);
            log_activity($db, 'delete', $delPost['post_type'] ?? 'post', (int) $_POST['delete_id'], $delPost['title'] ?? '');
            $_SESSION['flash_message'] = 'Lomtárba helyezve.';
            $_SESSION['flash_type'] = 'success';
        }
    }
    header('Location: posts.php?type=' . $currentType);
    exit;
}

// ---------- Visszaállítás ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('SELECT title, post_type FROM posts WHERE id = ?');
        $stmt->execute([$_POST['restore_id']]);
        $resPost = $stmt->fetch();
        if ($resPost) {
            $db->prepare('UPDATE posts SET deleted_at = NULL WHERE id = ?')->execute([$_POST['restore_id']]);
            log_activity($db, 'restore', $resPost['post_type'] ?? 'post', (int) $_POST['restore_id'], $resPost['title'] ?? '');
            $_SESSION['flash_message'] = 'Visszaállítva!';
            $_SESSION['flash_type'] = 'success';
        }
    }
    header('Location: posts.php?type=trash');
    exit;
}

// ---------- Végleges törlés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('SELECT title, post_type FROM posts WHERE id = ?');
        $stmt->execute([$_POST['purge_id']]);
        $purPost = $stmt->fetch();
        if ($purPost) {
            $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$_POST['purge_id']]);
            log_activity($db, 'purge', $purPost['post_type'] ?? 'post', (int) $_POST['purge_id'], $purPost['title'] ?? '');
            $_SESSION['flash_message'] = 'Véglegesen törölve.';
            $_SESSION['flash_type'] = 'success';
        }
    }
    header('Location: posts.php?type=trash');
    exit;
}

// ---------- Tömeges műveletek ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !empty($_POST['bulk_ids'])) {
    if (csrf_verify()) {
        $action = $_POST['bulk_action'];
        $ids = $_POST['bulk_ids'];
        $count = 0;

        foreach ($ids as $id) {
            $id = (int) $id;
            $stmt = $db->prepare('SELECT title, post_type FROM posts WHERE id = ?');
            $stmt->execute([$id]);
            $p = $stmt->fetch();
            if (!$p)
                continue;

            if ($action === 'bulk_delete') {
                $db->prepare('UPDATE posts SET deleted_at = NOW() WHERE id = ?')->execute([$id]);
                log_activity($db, 'bulk_delete', $p['post_type'], $id, $p['title']);
                $count++;
            } elseif ($action === 'bulk_restore') {
                $db->prepare('UPDATE posts SET deleted_at = NULL WHERE id = ?')->execute([$id]);
                log_activity($db, 'bulk_restore', $p['post_type'], $id, $p['title']);
                $count++;
            } elseif ($action === 'bulk_purge') {
                $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
                log_activity($db, 'bulk_purge', $p['post_type'], $id, $p['title']);
                $count++;
            }
        }

        if ($count > 0) {
            $_SESSION['flash_message'] = "$count elem sikeresen frissítve.";
            $_SESSION['flash_type'] = 'success';
        }
    }
    header('Location: posts.php?type=' . $currentType);
    exit;
}

// ---------- Adatok lekérése ----------
if ($showTrash) {
    $posts = $db->query('SELECT * FROM posts WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC')->fetchAll();
} elseif ($currentType === 'all') {
    $posts = $db->query('SELECT * FROM posts WHERE deleted_at IS NULL ORDER BY id DESC')->fetchAll();
} else {
    $stmt = $db->prepare('SELECT * FROM posts WHERE post_type = ? AND deleted_at IS NULL ORDER BY id DESC');
    $stmt->execute([$currentType]);
    $posts = $stmt->fetchAll();
}

$trashCount = $db->query('SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL')->fetchColumn();

$pageTitle = 'Tartalom kezelés';
if ($currentType === 'page')
    $pageTitle = 'Oldalak';
elseif ($currentType === 'post')
    $pageTitle = 'Bejegyzések';
elseif ($showTrash)
    $pageTitle = 'Lomtár';

$topBar = [];
if (!$showTrash) {
    $topBar['actions_html'] = '<a href="post_edit.php" class="btn btn-success" style="font-weight: bold;">➕ Új tartalom</a>';
}

admin_header($pageTitle, $topBar);
?>

<div style="display: flex; gap: 8px; margin-bottom: 25px; align-items: center;">
    <a href="posts.php?type=all" class="btn <?php echo $currentType === 'all' ? '' : 'btn-outline'; ?>"
        style="text-decoration:none; font-size:0.85rem;">Összes</a>
    <a href="posts.php?type=page" class="btn <?php echo $currentType === 'page' ? '' : 'btn-outline'; ?>"
        style="text-decoration:none; font-size:0.85rem;">📄 Oldalak</a>
    <a href="posts.php?type=post" class="btn <?php echo $currentType === 'post' ? '' : 'btn-outline'; ?>"
        style="text-decoration:none; font-size:0.85rem;">📰 Bejegyzések</a>
    <div style="flex-grow: 1;"></div>
    <?php if ($trashCount > 0): ?>
        <a href="posts.php?type=trash" class="btn"
            style="<?php echo $currentType !== 'trash' ? 'background:transparent; color:#dc2626; border:1px solid #dc2626;' : 'background:#dc2626;'; ?> text-decoration:none; font-size:0.85rem;">🗑️
            Lomtár (<?php echo $trashCount; ?>)</a>
    <?php endif; ?>
</div>

<?php if (empty($posts)): ?>
    <div style="text-align: center; padding: 50px; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;">
        <div style="font-size: 3rem; margin-bottom: 15px;">📂</div>
        <p style="color: #64748b; font-size: 1.1rem;">
            <?php echo $showTrash ? 'A lomtár üres.' : 'Nincs megjeleníthető tartalom.'; ?></p>
        <?php if (!$showTrash): ?>
            <a href="post_edit.php" class="btn btn-success" style="margin-top: 15px;">Hozd létre az elsőt!</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <form method="post" id="bulk-form">
        <?php echo csrf_field(); ?>

        <div id="bulk-action-bar"
            style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 20px; margin-bottom: 15px; align-items: center; gap: 15px; position: sticky; top: 10px; z-index: 100; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
            <span style="font-weight: 600; color: #475569;"><span id="selected-count">0</span> kijelölve</span>
            <div style="height: 20px; width: 1px; background: #cbd5e1;"></div>
            <select name="bulk_action" id="bulk-action-select" class="form-control"
                style="width: auto; height: 36px; padding: 0 10px; font-size: 0.9rem;">
                <option value="">-- Művelet kiválasztása --</option>
                <?php if ($showTrash): ?>
                    <option value="bulk_restore">♻️ Visszaállítás</option>
                    <option value="bulk_purge">❌ Végleges törlés</option>
                <?php else: ?>
                    <option value="bulk_delete">🗑️ Lomtárba helyezés</option>
                <?php endif; ?>
            </select>
            <button type="button" onclick="submitBulkAction()" class="btn btn-success"
                style="padding: 6px 15px; font-size: 0.9rem;">Végrehajtás</button>
        </div>

        <div style="background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden;">
            <table style="margin: 0;">
                <thead>
                    <tr>
                        <th style="width: 45px; text-align: center;"><input type="checkbox" id="select-all"
                                style="width: 18px; height: 18px; cursor: pointer;"></th>
                        <th style="width: 60px;">Kép</th>
                        <th>Cím</th>
                        <th style="width: 120px;">Típus</th>
                        <th style="width: 150px;"><?php echo $showTrash ? 'Törölve' : 'Státusz'; ?></th>
                        <th style="width: 160px; text-align: right;">Műveletek</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $p): ?>
                        <tr style="cursor: pointer;" onclick="window.location='post_edit.php?id=<?php echo $p['id']; ?>'">
                            <td onclick="event.stopPropagation();" style="text-align: center;">
                                <input type="checkbox" name="bulk_ids[]" value="<?php echo $p['id']; ?>" class="post-checkbox"
                                    onclick="updateBulkBar()" style="width: 18px; height: 18px; cursor: pointer;">
                            </td>
                            <td>
                                <?php if (!empty($p['featured_image'])): ?>
                                    <img src="../uploads/<?php echo htmlspecialchars($p['featured_image']); ?>"
                                        style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; border: 1px solid #e5e7eb;">
                                <?php else: ?>
                                    <div
                                        style="width: 40px; height: 40px; background: #f1f5f9; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #cbd5e1; font-size: 0.8rem;">
                                        🖼️</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($p['title']); ?>
                                </div>
                                <div style="font-size: 0.75rem; color: #94a3b8; font-family: monospace;">
                                    <?php echo htmlspecialchars($p['slug']); ?></div>
                            </td>
                            <td>
                                <span
                                    style="font-size: 0.85rem; padding: 4px 8px; background: #f1f5f9; border-radius: 4px; color: #475569;">
                                    <?php echo ($p['post_type'] ?? 'page') === 'post' ? '📰 Bejegyzés' : '📄 Oldal'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($showTrash): ?>
                                    <span style="color: #dc2626; font-size: 0.85rem; font-weight: 500;">🗑️
                                        <?php echo date('Y.m.d', strtotime($p['deleted_at'])); ?></span>
                                <?php elseif ($p['status'] === 'published'): ?>
                                    <span style="color: #16a34a; font-weight: 600; font-size: 0.85rem;">● Publikált</span>
                                <?php elseif ($p['status'] === 'scheduled'): ?>
                                    <span style="color: #ca8a04; font-weight: 600; font-size: 0.85rem;">⏰
                                        <?php echo date('m.d H:i', strtotime($p['publish_at'])); ?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 0.85rem;">○ Piszkozat</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;" onclick="event.stopPropagation();">
                                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                    <?php if ($showTrash): ?>
                                        <form method="post" style="display: inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="restore_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn btn-success" style="padding: 6px 10px;"
                                                title="Visszaállítás">♻️</button>
                                        </form>
                                        <form method="post" style="display: inline;"
                                            onsubmit="return confirm('Véglegesen törlöd? Ez nem visszavonható!');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="purge_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 6px 10px;"
                                                title="Végleges törlés">❌</button>
                                        </form>
                                    <?php else: ?>
                                        <a href="post_edit.php?id=<?php echo $p['id']; ?>" class="btn"
                                            style="padding: 6px 10px; text-decoration: none;">✏️</a>
                                        <a href="<?php echo UrlHelper::link($p['slug']); ?>" target="_blank" class="btn btn-outline"
                                            style="padding: 6px 10px; text-decoration: none; border: 1px solid #e2e8f0;">👁️</a>
                                        <form method="post" style="display: inline;"
                                            onsubmit="return confirm('Lomtárba helyezed?');">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="delete_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn btn-danger" style="padding: 6px 10px;">🗑️</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
<?php endif; ?>

<script>
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.post-checkbox');
    const bulkBar = document.getElementById('bulk-action-bar');
    const selectedCount = document.getElementById('selected-count');

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateBulkBar();
        });
    }

    function updateBulkBar() {
        const checkedCount = document.querySelectorAll('.post-checkbox:checked').length;
        if (checkedCount > 0) {
            bulkBar.style.display = 'flex';
            selectedCount.textContent = checkedCount;
        } else {
            bulkBar.style.display = 'none';
        }
    }

    function submitBulkAction() {
        const action = document.getElementById('bulk-action-select').value;
        if (!action) return alert('Kérlek válassz egy műveletet!');
        let confirmMsg = 'Biztosan végrehajtod a műveletet a kijelölt elemeken?';
        if (action === 'bulk_purge') confirmMsg = 'FIGYELEM: A kijelölt elemek VÉGLEGESEN törlődni fognak! Folytatod?';
        if (confirm(confirmMsg)) document.getElementById('bulk-form').submit();
    }
</script>

<?php admin_footer(); ?>