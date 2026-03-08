<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

// ---------- Kijelentkezés ----------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    log_activity($db, 'logout', null, null, null, null);
    session_destroy();
    header('Location: index.php');
    exit;
}

// ---------- Bejelentkezés ----------
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!csrf_verify()) {
        $loginError = 'Érvénytelen kérés. Próbálja újra.';
    } else {
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$_POST['username']]);
        $u = $stmt->fetch();

        if ($u && password_verify($_POST['password'], $u['password_hash'])) {
            $_SESSION['admin_id'] = $u['id'];
            $_SESSION['admin_user'] = $u['username'];
            $_SESSION['admin_role'] = $u['role'] ?? 'registered';

            log_activity($db, 'login', null, null, null, null);

            if ($_SESSION['admin_role'] === 'registered') {
                header('Location: profile.php');
            } else {
                header('Location: index.php');
            }
            exit;
        } else {
            $loginError = 'Hibás felhasználónév vagy jelszó.';
        }
    }
}

// ---------- Login form (ha nincs bejelentkezve) ----------
if (!isset($_SESSION['admin_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="hu">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login – CMS</title>
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Segoe UI', Tahoma, sans-serif;
                background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }

            .login-box {
                background: #fff;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                width: 360px;
            }

            .login-box h2 {
                text-align: center;
                margin-bottom: 25px;
                color: #1e293b;
            }

            .login-box input {
                width: 100%;
                padding: 12px;
                margin-bottom: 15px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 1rem;
            }

            .login-box input:focus {
                outline: none;
                border-color: #2563eb;
                box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            }

            .login-box button {
                width: 100%;
                padding: 12px;
                background: #2563eb;
                color: #fff;
                border: none;
                border-radius: 6px;
                font-size: 1rem;
                cursor: pointer;
                font-weight: 600;
                transition: background 0.2s;
            }

            .login-box button:hover {
                background: #1d4ed8;
            }

            .error {
                color: #dc2626;
                text-align: center;
                margin-bottom: 15px;
                font-size: 0.9rem;
            }
        </style>
    </head>

    <body>
        <div class="login-box">
            <h2>🔐 Admin Login</h2>
            <?php if ($loginError): ?>
                <p class="error"><?php echo htmlspecialchars($loginError); ?></p>
            <?php endif; ?>
            <form method="post">
                <?php echo csrf_field(); ?>
                <input type="text" name="username" placeholder="Felhasználónév" required>
                <input type="password" name="password" placeholder="Jelszó" required>
                <button type="submit" name="login">Belépés</button>
            </form>
        </div>
    </body>

    </html>
    <?php
    exit;
}

// ---------- Dashboard ----------
admin_header('Dashboard');

$postCount = $db->query("SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL")->fetchColumn();
$mediaCount = $db->query("SELECT COUNT(*) FROM media")->fetchColumn();
$menuCount = $db->query("SELECT COUNT(*) FROM menus")->fetchColumn();
$userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$trashCount = $db->query("SELECT COUNT(*) FROM posts WHERE deleted_at IS NOT NULL")->fetchColumn();

// Legutóbbi bejegyzések
$recentPosts = $db->query("SELECT id, title, post_type, status FROM posts WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5")->fetchAll();

// Legutóbbi tevékenységek
$recentLogs = [];
try {
    $recentLogs = $db->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 8")->fetchAll();
} catch (PDOException $e) { /* tábla még nem létezik */
}

// Rendszer infó
$mysqlVersion = $db->query("SELECT VERSION()")->fetchColumn();
$dbSizeResult = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.TABLES WHERE table_schema = '" . DB_NAME . "'")->fetch();
$dbSize = $dbSizeResult['size'] ?? '?';
?>

<p style="margin-bottom: 25px; color: #64748b;">Üdvözöllek,
    <strong><?php echo htmlspecialchars($_SESSION['admin_user']); ?></strong>! Itt egy gyors áttekintés.
</p>

<!-- Statisztika kártyák -->
<div
    style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 30px;">
    <div style="background: #eff6ff; padding: 22px; border-radius: 10px; border-left: 4px solid #2563eb;">
        <div style="font-size: 2rem; font-weight: 700; color: #2563eb;"><?php echo $postCount; ?></div>
        <div style="color: #64748b; margin-top: 5px;">📝 Tartalmak</div>
    </div>
    <div style="background: #f0fdf4; padding: 22px; border-radius: 10px; border-left: 4px solid #16a34a;">
        <div style="font-size: 2rem; font-weight: 700; color: #16a34a;"><?php echo $mediaCount; ?></div>
        <div style="color: #64748b; margin-top: 5px;">🖼️ Média fájlok</div>
    </div>
    <div style="background: #fefce8; padding: 22px; border-radius: 10px; border-left: 4px solid #ca8a04;">
        <div style="font-size: 2rem; font-weight: 700; color: #ca8a04;"><?php echo $menuCount; ?></div>
        <div style="color: #64748b; margin-top: 5px;">📋 Menüpontok</div>
    </div>
    <div style="background: #faf5ff; padding: 22px; border-radius: 10px; border-left: 4px solid #9333ea;">
        <div style="font-size: 2rem; font-weight: 700; color: #9333ea;"><?php echo $userCount; ?></div>
        <div style="color: #64748b; margin-top: 5px;">👥 Felhasználók</div>
    </div>
    <?php if ($trashCount > 0): ?>
        <div style="background: #fef2f2; padding: 22px; border-radius: 10px; border-left: 4px solid #dc2626;">
            <div style="font-size: 2rem; font-weight: 700; color: #dc2626;"><?php echo $trashCount; ?></div>
            <div style="color: #64748b; margin-top: 5px;">🗑️ Lomtárban</div>
        </div>
    <?php endif; ?>
</div>

<!-- Két oszlopos rész -->
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">

    <!-- Legutóbbi bejegyzések -->
    <div style="background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb;">
        <h3 style="margin-bottom: 15px; color: #1e293b; font-size: 1rem;">📰 Legutóbbi tartalmak</h3>
        <?php if (empty($recentPosts)): ?>
            <p style="color: #94a3b8;">Nincs még tartalom.</p>
        <?php else: ?>
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ($recentPosts as $rp): ?>
                    <tr>
                        <td style="padding: 8px 4px; border-bottom: 1px solid #f1f5f9;">
                            <a href="posts.php?edit=<?php echo $rp['id']; ?>"
                                style="color: #1e293b; text-decoration: none; font-weight: 500;">
                                <?php echo htmlspecialchars(mb_strimwidth($rp['title'], 0, 35, '...')); ?>
                            </a>
                        </td>
                        <td
                            style="padding: 8px 4px; border-bottom: 1px solid #f1f5f9; color: #94a3b8; font-size: 0.8rem; text-align: right; white-space: nowrap;">
                            <?php
                            $typeIcon = $rp['post_type'] === 'post' ? '📰' : '📄';
                            $statusColors = ['published' => '#16a34a', 'draft' => '#ca8a04', 'scheduled' => '#2563eb'];
                            $statusLabel = ['published' => 'Publikált', 'draft' => 'Vázlat', 'scheduled' => 'Ütemezett'];
                            ?>
                            <?php echo $typeIcon; ?>
                            <span style="color: <?php echo $statusColors[$rp['status']] ?? '#94a3b8'; ?>; font-weight: 600;">
                                <?php echo $statusLabel[$rp['status']] ?? $rp['status']; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <!-- Legutóbbi tevékenységek -->
    <div style="background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb;">
        <h3 style="margin-bottom: 15px; color: #1e293b; font-size: 1rem;">📋 Legutóbbi tevékenységek</h3>
        <?php if (empty($recentLogs)): ?>
            <p style="color: #94a3b8;">Nincs még bejegyzett tevékenység.</p>
        <?php else: ?>
            <?php
            $actionLabels = [
                'login' => ['🔑 Bejelentkezés', '#16a34a'],
                'logout' => ['🚪 Kijelentkezés', '#64748b'],
                'create' => ['➕ Létrehozás', '#2563eb'],
                'update' => ['✏️ Módosítás', '#ca8a04'],
                'delete' => ['🗑️ Lomtárba', '#dc2626'],
                'restore' => ['♻️ Visszaállítás', '#16a34a'],
                'purge' => ['❌ Végleges törlés', '#991b1b'],
            ];
            ?>
            <?php foreach ($recentLogs as $log): ?>
                <?php
                $label = $actionLabels[$log['action']] ?? ['❓ ' . $log['action'], '#64748b'];
                ?>
                <div
                    style="padding: 6px 0; border-bottom: 1px solid #f8fafc; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="color: <?php echo $label[1]; ?>; font-weight: 600;"><?php echo $label[0]; ?></span>
                        <?php if ($log['target_title']): ?>
                            <span style="color: #64748b;"> –
                                <?php echo htmlspecialchars(mb_strimwidth($log['target_title'], 0, 25, '...')); ?></span>
                        <?php endif; ?>
                        <span style="color: #cbd5e1;"> (<?php echo htmlspecialchars($log['username']); ?>)</span>
                    </div>
                    <span style="color: #cbd5e1; font-size: 0.75rem; white-space: nowrap;">
                        <?php echo date('m.d H:i', strtotime($log['created_at'])); ?>
                    </span>
                </div>
            <?php endforeach; ?>
            <?php if (is_super_admin()): ?>
                <a href="activity_log.php"
                    style="display: inline-block; margin-top: 10px; color: #2563eb; font-size: 0.85rem; text-decoration: none;">Összes
                    tevékenység →</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Rendszer info -->
<?php if (is_super_admin()): ?>
    <div style="background: #fff; padding: 20px; border-radius: 10px; border: 1px solid #e5e7eb;">
        <h3 style="margin-bottom: 15px; color: #1e293b; font-size: 1rem;">⚙️ Rendszer információ</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
            <div>
                <div style="color: #94a3b8; font-size: 0.8rem;">PHP verzió</div>
                <div style="font-weight: 600; color: #1e293b;"><?php echo phpversion(); ?></div>
            </div>
            <div>
                <div style="color: #94a3b8; font-size: 0.8rem;">MySQL verzió</div>
                <div style="font-weight: 600; color: #1e293b;"><?php echo $mysqlVersion; ?></div>
            </div>
            <div>
                <div style="color: #94a3b8; font-size: 0.8rem;">Adatbázis méret</div>
                <div style="font-weight: 600; color: #1e293b;"><?php echo $dbSize; ?> MB</div>
            </div>
            <div>
                <div style="color: #94a3b8; font-size: 0.8rem;">Szerver</div>
                <div style="font-weight: 600; color: #1e293b;"><?php echo php_uname('s') . ' ' . php_uname('r'); ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
admin_footer();