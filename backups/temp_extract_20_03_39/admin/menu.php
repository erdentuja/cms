<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_super_admin()) {
    header('Location: index.php');
    exit;
}

$message = '';
$messageType = '';

// ---------- Hozzáadás ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $label = trim($_POST['label'] ?? '');
        $url = trim($_POST['url'] ?? '');
        if ($label === '' || $url === '') {
            $message = 'A név és URL megadása kötelező.';
            $messageType = 'error';
        } else {
            $maxOrder = $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM menus')->fetchColumn();
            $nextOrder = $maxOrder + 1;
            $db->prepare('INSERT INTO menus (label, url, sort_order) VALUES (?, ?, ?)')
                ->execute([$label, $url, $nextOrder]);
            log_activity($db, 'create', 'menu', (int) $db->lastInsertId(), $label);
            $message = 'Menüpont hozzáadva.';
            $messageType = 'success';
        }
    }
}

// ---------- Törlés (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('SELECT label FROM menus WHERE id = ?');
        $stmt->execute([$_POST['delete_id']]);
        $delMenu = $stmt->fetch();
        $db->prepare('DELETE FROM menus WHERE id = ?')->execute([$_POST['delete_id']]);
        log_activity($db, 'delete', 'menu', (int) $_POST['delete_id'], $delMenu['label'] ?? '');
        $message = 'Menüpont törölve.';
        $messageType = 'success';
    }
}

// ---------- Sorrend módosítás ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_id'], $_POST['direction'])) {
    if (csrf_verify()) {
        $id = (int) $_POST['move_id'];
        $dir = $_POST['direction'];
        $current = $db->prepare('SELECT * FROM menus WHERE id = ?');
        $current->execute([$id]);
        $item = $current->fetch();

        if ($item) {
            if ($dir === 'up') {
                $swap = $db->prepare('SELECT * FROM menus WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1');
                $swap->execute([$item['sort_order']]);
            } else {
                $swap = $db->prepare('SELECT * FROM menus WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1');
                $swap->execute([$item['sort_order']]);
            }
            $swapItem = $swap->fetch();
            if ($swapItem) {
                $db->prepare('UPDATE menus SET sort_order = ? WHERE id = ?')->execute([$swapItem['sort_order'], $item['id']]);
                $db->prepare('UPDATE menus SET sort_order = ? WHERE id = ?')->execute([$item['sort_order'], $swapItem['id']]);
            }
        }
    }
}

$menus = $db->query('SELECT * FROM menus ORDER BY sort_order ASC')->fetchAll();

// Oldalak és kategóriák betöltése a tartalom-választóhoz
$pages = $db->query("SELECT id, title, slug FROM posts WHERE post_type = 'page' ORDER BY title ASC")->fetchAll();
$menuCategories = $db->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetchAll();

admin_header('Menü kezelés');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div style="background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 25px;">
    <h3 style="margin-bottom: 15px;">➕ Menüpont hozzáadása</h3>
    <form method="post">
        <?php echo csrf_field(); ?>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div class="form-group">
                <label>Tartalom típusa</label>
                <select id="menu-type" onchange="toggleMenuType()" style="width: 100%;">
                    <option value="page">📄 Oldal</option>
                    <option value="category">🏷️ Kategória</option>
                    <option value="blog">📰 Blog oldal</option>
                    <option value="custom">🔗 Egyéni link</option>
                </select>
            </div>

            <!-- Oldal választó -->
            <div class="form-group" id="page-selector">
                <label>Válassz oldalt</label>
                <select id="page-select" onchange="fillFromPage()" style="width: 100%;">
                    <option value="">-- Válassz --</option>
                    <?php foreach ($pages as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['slug']); ?>"
                            data-title="<?php echo htmlspecialchars($p['title']); ?>">
                            <?php echo htmlspecialchars($p['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Kategória választó -->
            <div class="form-group" id="cat-selector" style="display: none;">
                <label>Válassz kategóriát</label>
                <select id="cat-select" onchange="fillFromCat()" style="width: 100%;">
                    <option value="">-- Válassz --</option>
                    <?php foreach ($menuCategories as $mc): ?>
                        <option value="blog?cat=<?php echo htmlspecialchars($mc['slug']); ?>"
                            data-title="<?php echo htmlspecialchars($mc['name']); ?>">
                            <?php echo htmlspecialchars($mc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Egyéni URL -->
            <div class="form-group" id="custom-url" style="display: none;">
                <label>URL</label>
                <input type="text" id="url-input" name="url" placeholder="pl. https://example.com" style="width: 100%;">
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 15px;">
            <label>Megjelenített név</label>
            <input type="text" id="label-input" name="label" placeholder="Menüpont neve" required
                style="width: 100%; max-width: 400px;">
        </div>

        <!-- Rejtett URL mező oldal módhoz -->
        <input type="hidden" id="url-hidden" name="url" value="">

        <button type="submit" name="add" class="btn">➕ Hozzáadás</button>
    </form>
</div>

<script>
    function toggleMenuType() {
        const type = document.getElementById('menu-type').value;
        const pageSel = document.getElementById('page-selector');
        const catSel = document.getElementById('cat-selector');
        const customUrl = document.getElementById('custom-url');
        const urlInput = document.getElementById('url-input');
        const urlHidden = document.getElementById('url-hidden');

        pageSel.style.display = 'none';
        catSel.style.display = 'none';
        customUrl.style.display = 'none';

        if (type === 'page') {
            pageSel.style.display = '';
            urlInput.removeAttribute('name');
            urlHidden.setAttribute('name', 'url');
        } else if (type === 'category') {
            catSel.style.display = '';
            urlInput.removeAttribute('name');
            urlHidden.setAttribute('name', 'url');
        } else if (type === 'blog') {
            urlInput.removeAttribute('name');
            urlHidden.setAttribute('name', 'url');
            urlHidden.value = 'blog';
            document.getElementById('label-input').value = 'Blog';
            return;
        } else {
            customUrl.style.display = '';
            urlInput.setAttribute('name', 'url');
            urlHidden.removeAttribute('name');
        }
        document.getElementById('label-input').value = '';
        urlHidden.value = '';
        urlInput.value = '';
        document.getElementById('page-select').value = '';
        document.getElementById('cat-select').value = '';
    }

    function fillFromPage() {
        const sel = document.getElementById('page-select');
        const opt = sel.options[sel.selectedIndex];
        if (opt.value) {
            document.getElementById('label-input').value = opt.dataset.title;
            document.getElementById('url-hidden').value = opt.value;
        }
    }

    function fillFromCat() {
        const sel = document.getElementById('cat-select');
        const opt = sel.options[sel.selectedIndex];
        if (opt.value) {
            document.getElementById('label-input').value = opt.dataset.title;
            document.getElementById('url-hidden').value = opt.value;
        }
    }
</script>

<?php if (empty($menus)): ?>
    <p style="color: #94a3b8;">Nincs még menüpont.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width: 50px;">#</th>
                <th>Név</th>
                <th>URL</th>
                <th style="width: 180px;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($menus as $index => $m): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo htmlspecialchars($m['label']); ?></td>
                    <td><?php echo htmlspecialchars($m['url']); ?></td>
                    <td>
                        <div style="display: flex; gap: 5px;">
                            <form method="post" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="move_id" value="<?php echo $m['id']; ?>">
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.8rem;"
                                    title="Fel">▲</button>
                            </form>
                            <form method="post" style="display: inline;">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="move_id" value="<?php echo $m['id']; ?>">
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.8rem;"
                                    title="Le">▼</button>
                            </form>
                            <form method="post" style="display: inline;" onsubmit="return confirm('Biztosan törlöd?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo $m['id']; ?>">
                                <button type="submit" class="btn btn-danger"
                                    style="padding: 4px 8px; font-size: 0.8rem;">🗑️</button>
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