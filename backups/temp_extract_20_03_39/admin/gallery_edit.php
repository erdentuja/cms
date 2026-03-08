<?php
require_once '../config.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: galleries.php');
    exit;
}

$gallery = $db->prepare("SELECT * FROM galleries WHERE id = ?");
$gallery->execute([$id]);
$gallery = $gallery->fetch();

if (!$gallery) {
    header('Location: galleries.php');
    exit;
}

// Mentés (Cím és leírás)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gallery'])) {
    if (csrf_verify()) {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($title) {
            $stmt = $db->prepare("UPDATE galleries SET title = ?, description = ? WHERE id = ?");
            $stmt->execute([$title, $desc, $id]);
            log_activity($db, 'update', 'gallery', (int) $id, $title);
            header("Location: gallery_edit.php?id=$id&saved=1");
            exit;
        }
    }
}

$items = $db->prepare("
    SELECT gi.*, m.filename, m.filepath, m.alt_text 
    FROM gallery_items gi 
    JOIN media m ON gi.media_id = m.id 
    WHERE gi.gallery_id = ? 
    ORDER BY gi.sort_order ASC
");
$items->execute([$id]);
$items = $items->fetchAll();

admin_header("Galéria szerkesztése: " . $gallery['title']);
?>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<div style="display: flex; gap: 30px; align-items: flex-start;">

    <!-- Bal oldal: Beállítások -->
    <div
        style="width: 350px; background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; position: sticky; top: 120px;">
        <h3 style="margin-bottom: 20px;">⚙️ Galéria beállításai</h3>

        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>Galéria neve</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($gallery['title']); ?>" required
                    style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Leírás (opcionális)</label>
                <textarea name="description"
                    style="width: 100%; height: 100px;"><?php echo htmlspecialchars($gallery['description'] ?? ''); ?></textarea>
            </div>
            <button type="submit" name="save_gallery" class="btn btn-success" style="width: 100%; margin-top: 10px;">💾
                Alapadatok mentése</button>
        </form>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #f1f5f9;">
            <p style="font-size: 0.9rem; color: #64748b; margin-bottom: 15px;">A képek sorrendjét a **vonszolás (drag &
                drop)** módszerével tudod módosítani a jobb oldali listában.</p>
            <a href="media.php" class="btn"
                style="width: 100%; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; text-align: center; text-decoration: none;">➕
                Képek hozzáadása a médiatárból</a>
        </div>
    </div>

    <!-- Jobb oldal: Képek és Sorrend -->
    <div style="flex: 1;">
        <div style="background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0;">🖼️ Galéria képei (<span id="count-badge">
                        <?php echo count($items); ?>
                    </span>)</h3>
                <div id="save-status" style="font-size: 0.9rem; font-weight: 500;"></div>
            </div>

            <?php if (empty($items)): ?>
                <div style="text-align: center; padding: 60px; color: #94a3b8;">
                    <div style="font-size: 3rem; margin-bottom: 10px;">Empty</div>
                    <p>Még nincsenek képek ebben a galériában.</p>
                </div>
            <?php else: ?>
                <div id="sortable-gallery"
                    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 20px;">
                    <?php foreach ($items as $item): ?>
                        <div class="gallery-item-card" data-id="<?php echo $item['media_id']; ?>"
                            style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #fff; cursor: grab; position: relative; transition: transform 0.2s;">
                            <img src="../uploads/<?php echo htmlspecialchars($item['filepath']); ?>"
                                style="width: 100%; height: 140px; object-fit: cover; display: block;">
                            <div style="padding: 8px; font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; background: #f8fafc;"
                                title="<?php echo htmlspecialchars($item['filename']); ?>">
                                <?php echo htmlspecialchars($item['filename']); ?>
                            </div>
                            <button onclick="removeFromGallery(<?php echo $item['media_id']; ?>, this)"
                                style="position: absolute; top: 5px; right: 5px; background: rgba(220, 38, 38, 0.8); color: #fff; border: none; width: 24px; height: 24px; border-radius: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold;"
                                title="Eltávolítás">✕</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const el = document.getElementById('sortable-gallery');
        if (el) {
            Sortable.create(el, {
                animation: 150,
                ghostClass: 'bg-indigo-100',
                onEnd: function () {
                    saveOrder();
                }
            });
        }
    });

    function saveOrder() {
        const statusEl = document.getElementById('save-status');
        statusEl.innerHTML = '⏳ Sorrend mentése...';
        statusEl.style.color = '#64748b';

        const itemIds = Array.from(document.querySelectorAll('.gallery-item-card')).map(el => el.dataset.id);

        const formData = new FormData();
        formData.append('action', 'update_order');
        formData.append('gallery_id', '<?php echo $id; ?>');
        formData.append('order', JSON.stringify(itemIds));
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');

        fetch('ajax_gallery.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    statusEl.innerHTML = '✅ Sorrend elmentve!';
                    statusEl.style.color = '#16a34a';
                    setTimeout(() => { statusEl.innerHTML = ''; }, 3000);
                } else {
                    statusEl.innerHTML = '❌ Hiba a mentésnél!';
                    statusEl.style.color = '#dc2626';
                }
            })
            .catch(err => {
                statusEl.innerHTML = '❌ Hálózati hiba!';
                statusEl.style.color = '#dc2626';
                console.error(err);
            });
    }

    function removeFromGallery(mediaId, btn) {
        if (!confirm('Biztosan eltávolítod ezt a képet a galériából?')) return;

        const card = btn.closest('.gallery-item-card');
        const formData = new FormData();
        formData.append('action', 'remove_from_gallery');
        formData.append('gallery_id', '<?php echo $id; ?>');
        formData.append('media_id', mediaId);
        formData.append('csrf_token', '<?php echo csrf_token(); ?>');

        fetch('ajax_gallery.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    card.remove();
                    const badge = document.getElementById('count-badge');
                    badge.textContent = parseInt(badge.textContent) - 1;
                } else {
                    alert('Hiba: ' + (data.error || 'Ismeretlen hiba'));
                }
            });
    }
</script>

<style>
    .gallery-item-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .sortable-ghost {
        opacity: 0.4;
        border: 2px dashed #6366f1 !important;
    }
</style>

<?php
admin_footer();
