<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_super_admin()) {
    die('<div style="margin:50px auto;max-width:500px;text-align:center;font-family:sans-serif;">
            <h2 style="color:#ef4444;">Nincs jogosultságod!</h2>
            <p>Ezt az oldalt csak <b>Szuper Adminok</b> láthatják.</p>
            <a href="index.php" style="color:#2563eb;">Vissza a Dashboardra</a>
         </div>');
}

$message = '';
$messageType = '';
$editUser = null;

// ---------- Törlés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        $delId = (int) $_POST['delete_id'];
        if ($delId === (int) $_SESSION['admin_id']) {
            $message = 'Saját magadat nem törölheted!';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
            $stmt->execute([$delId]);
            $delUser = $stmt->fetch();
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$delId]);
            log_activity($db, 'delete', 'user', $delId, $delUser['username'] ?? '');
            $message = 'Felhasználó törölve.';
            $messageType = 'success';
        }
    }
}

// ---------- Tömeges műveletek ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && !empty($_POST['bulk_ids'])) {
    if (csrf_verify()) {
        $action = $_POST['bulk_action'];
        $ids = $_POST['bulk_ids'];
        $count = 0;

        if ($action === 'bulk_delete') {
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id === (int) $_SESSION['admin_id'])
                    continue; // Önvédelem

                $stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
                $stmt->execute([$id]);
                $u = $stmt->fetch();
                if (!$u)
                    continue;

                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                log_activity($db, 'bulk_delete', 'user', $id, $u['username']);
                $count++;
            }
        }

        if ($count > 0) {
            $message = "$count felhasználó sikeresen törölve.";
            $messageType = 'success';
        }
    }
}

// ---------- Mentés (Új vagy Szerkesztés) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $userId = $_POST['user_id'] ?? '';
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'registered';
        $password = $_POST['password'] ?? '';

        if ($username === '') {
            $message = 'A felhasználónév kötelező.';
            $messageType = 'error';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Érvénytelen email cím formátum.';
            $messageType = 'error';
        } else {
            // Check for duplicates
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$username, $userId ?: 0]);
            if ($stmt->fetch()) {
                $message = 'Ez a felhasználónév már létezik!';
                $messageType = 'error';
            } else {
                if ($userId) {
                    // Update
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_ARGON2I);
                        $db->prepare('UPDATE users SET username = ?, email = ?, role = ?, password_hash = ? WHERE id = ?')
                            ->execute([$username, $email, $role, $hash, $userId]);
                    } else {
                        $db->prepare('UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?')
                            ->execute([$username, $email, $role, $userId]);
                    }
                    $message = 'Felhasználó frissítve!';
                    $messageType = 'success';
                    log_activity($db, 'update', 'user', (int) $userId, $username);

                    // Ha a saját profilunkat szerkesztettük, frissítsük a sessiont
                    if ((int) $userId === (int) $_SESSION['admin_id']) {
                        $_SESSION['admin_user'] = $username;
                        $_SESSION['admin_role'] = $role;
                    }
                } else {
                    // Insert
                    if ($password === '') {
                        $message = 'Új felhasználónál kötelező jelszót megadni!';
                        $messageType = 'error';
                    } else {
                        $hash = password_hash($password, PASSWORD_ARGON2I);
                        $db->prepare('INSERT INTO users (username, email, role, password_hash) VALUES (?, ?, ?, ?)')
                            ->execute([$username, $email, $role, $hash]);
                        log_activity($db, 'create', 'user', (int) $db->lastInsertId(), $username);
                        $message = 'Felhasználó létrehozva!';
                        $messageType = 'success';
                    }
                }
            }
        }
    }
}

// ---------- Szerkesztés betöltése ----------
if (isset($_GET['edit'])) {
    // Ellenőrizzük, hogy létezik-e email oszlop
    try {
        $stmt = $db->prepare('SELECT id, username, email, role FROM users WHERE id = ?');
        $stmt->execute([$_GET['edit']]);
        $editUser = $stmt->fetch();
    } catch (PDOException $e) {
        $stmt = $db->prepare('SELECT id, username, NULL as email, role FROM users WHERE id = ?');
        $stmt->execute([$_GET['edit']]);
        $editUser = $stmt->fetch();
    }
}

try {
    $users = $db->query('SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC')->fetchAll();
} catch (PDOException $e) {
    try {
        $users = $db->query('SELECT id, username, NULL as email, role, created_at FROM users ORDER BY created_at DESC')->fetchAll();
    } catch (PDOException $e2) {
        $users = $db->query('SELECT id, username, NULL as email, role, NULL as created_at FROM users ORDER BY id DESC')->fetchAll();
    }
}

admin_header('Felhasználók');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Űrlap -->
<div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 30px;">
    <h3 style="margin-bottom: 15px;">
        <?php echo $editUser ? '✏️ Felhasználó szerkesztése' : '➕ Új felhasználó'; ?>
    </h3>
    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="user_id" value="<?php echo $editUser['id'] ?? ''; ?>">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div class="form-group">
                <label>Felhasználónév *</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($editUser['username'] ?? ''); ?>"
                    required style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Email cím</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($editUser['email'] ?? ''); ?>"
                    style="width: 100%;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div class="form-group">
                <label>Szerepkör</label>
                <select name="role" style="width: 100%;">
                    <?php
                    $roleList = [
                        'super_admin' => '👑 Szuper Admin',
                        'admin' => '📝 Admin (Tartalom szerkesztő)',
                        'registered' => '👤 Regisztrált (Olvasó)'
                    ];
                    $currentRole = $editUser['role'] ?? 'registered';
                    foreach ($roleList as $val => $label) {
                        $sel = ($val === $currentRole) ? 'selected' : '';
                        echo "<option value=\"$val\" $sel>$label</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>
                    <?php echo $editUser ? 'Új Jelszó (Hagyd üresen, ha nem változik)' : 'Jelszó *'; ?>
                </label>
                <input type="password" name="password" style="width: 100%;" <?php echo $editUser ? '' : 'required'; ?>>
            </div>
        </div>

        <div style="display: flex; gap: 10px;">
            <button type="submit" name="save" class="btn btn-success">💾 Mentés</button>
            <?php if ($editUser): ?>
                <a href="users.php" class="btn" style="text-decoration: none;">Mégse</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Lista -->
<form method="post" id="bulk-form">
    <?php echo csrf_field(); ?>

    <!-- Bulk Action Bar -->
    <div id="bulk-action-bar"
        style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 20px; margin-bottom: 15px; align-items: center; gap: 15px; position: sticky; top: 10px; z-index: 100; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
        <span style="font-weight: 600; color: #475569;"><span id="selected-count">0</span> kijelölve</span>
        <div style="height: 20px; width: 1px; background: #cbd5e1;"></div>
        <input type="hidden" name="bulk_action" value="bulk_delete">
        <button type="button" onclick="submitBulkAction()" class="btn btn-danger"
            style="padding: 6px 15px; font-size: 0.9rem;">Kijelöltek törlése</button>
    </div>

    <table
        style="width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <thead style="background: #f8fafc; border-bottom: 2px solid #e5e7eb; text-align: left;">
            <tr>
                <th style="padding: 15px; width: 40px;"><input type="checkbox" id="select-all" style="cursor: pointer;">
                </th>
                <th style="padding: 15px; width: 60px;">ID</th>
                <th style="padding: 15px;">Felhasználó</th>
                <th style="padding: 15px;">Szerepkör</th>
                <th style="padding: 15px;">Regisztrált</th>
                <th style="padding: 15px; width: 120px;">Műveletek</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 15px;">
                        <?php if ((int) $u['id'] !== (int) $_SESSION['admin_id']): ?>
                            <input type="checkbox" name="bulk_ids[]" value="<?php echo $u['id']; ?>" class="user-checkbox"
                                onclick="event.stopPropagation(); updateBulkBar();" style="cursor: pointer;">
                        <?php endif; ?>
                    </td>
                    <td style="padding: 15px; color: #64748b;">#
                        <?php echo $u['id']; ?>
                    </td>
                    <td style="padding: 15px;">
                        <div>
                            <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                            <?php if ((int) $u['id'] === (int) $_SESSION['admin_id']): ?>
                                <span
                                    style="font-size: 0.75rem; background: #2563eb; color: #fff; padding: 2px 6px; border-radius: 10px; margin-left: 5px;">Te
                                    vagy</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($u['email'])): ?>
                            <div style="font-size: 0.85rem; color: #64748b; margin-top: 2px;">
                                ✉️ <?php echo htmlspecialchars($u['email']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 15px;">
                        <?php
                        if ($u['role'] === 'super_admin')
                            echo '<span style="color:#dc2626; font-weight:bold;">Szuper Admin</span>';
                        elseif ($u['role'] === 'admin')
                            echo '<span style="color:#059669; font-weight:bold;">Admin</span>';
                        else
                            echo '<span style="color:#64748b;">Regisztrált</span>';
                        ?>
                    </td>
                    <td style="padding: 15px; color: #64748b; font-size: 0.9rem;">
                        <?php echo date('Y.m.d. H:i', strtotime($u['created_at'] ?? 'now')); ?>
                    </td>
                    <td style="padding: 15px;">
                        <div style="display: flex; gap: 5px;">
                            <a href="users.php?edit=<?php echo $u['id']; ?>" class="btn"
                                style="padding: 4px 10px; font-size: 0.8rem; text-decoration: none;">✏️</a>
                            <?php if ((int) $u['id'] !== (int) $_SESSION['admin_id']): ?>
                                <form method="post" style="display: inline;"
                                    onsubmit="return confirm('Biztosan törlöd ezt a felhasználót?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="delete_id" value="<?php echo $u['id']; ?>">
                                    <button type="submit" class="btn btn-danger"
                                        style="padding: 4px 10px; font-size: 0.8rem;">🗑️</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</form>

<script>
    // --- Bulk Action Logic ---
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.user-checkbox');
    const bulkBar = document.getElementById('bulk-action-bar');
    const selectedCount = document.getElementById('selected-count');

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
            });
            updateBulkBar();
        });
    }

    function updateBulkBar() {
        const checkedCount = document.querySelectorAll('.user-checkbox:checked').length;
        if (checkedCount > 0) {
            bulkBar.style.display = 'flex';
            selectedCount.textContent = checkedCount;
        } else {
            bulkBar.style.display = 'none';
        }
    }

    function submitBulkAction() {
        if (confirm('Biztosan TÖRÖLNI akarod a kijelölt felhasználókat? Ez a művelet nem vonható vissza!')) {
            document.getElementById('bulk-form').submit();
        }
    }
</script>

<?php
admin_footer();
