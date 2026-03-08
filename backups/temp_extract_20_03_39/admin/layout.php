<?php
/**
 * Admin Layout Helper
 * Közös sidebar + fejléc minden admin oldalhoz.
 */
require_once __DIR__ . '/../core/functions.php';

function admin_header(string $pageTitle = 'Admin', array $topBar = []): void
{
    ?>
    <!DOCTYPE html>
    <html lang="hu">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>
            <?php echo htmlspecialchars($pageTitle); ?> – CMS Admin
        </title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                display: flex;
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                background: #f1f5f9;
                height: 100vh;
                overflow: hidden;
                color: #1e293b;
            }

            nav.sidebar {
                width: 260px;
                background: #001f3f;
                color: #fff;
                padding: 30px 20px;
                flex-shrink: 0;
                display: flex;
                flex-direction: column;
                box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
            }

            nav.sidebar h3 {
                margin-bottom: 35px;
                font-size: 1.4rem;
                font-weight: 800;
                letter-spacing: -0.02em;
                color: #fff;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            nav.sidebar h3::before {
                content: 'LX';
                background: #2563eb;
                padding: 4px 8px;
                border-radius: 6px;
                font-size: 0.9rem;
            }

            nav.sidebar a {
                display: flex;
                align-items: center;
                gap: 12px;
                color: #94a3b8;
                padding: 12px 15px;
                text-decoration: none;
                border-radius: 8px;
                margin-bottom: 6px;
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                font-weight: 500;
            }

            nav.sidebar a:hover,
            nav.sidebar a.active {
                background: rgba(255, 255, 255, 0.1);
                color: #fff;
            }

            nav.sidebar a.active {
                background: #2563eb;
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            }

            nav.sidebar .logout {
                margin-top: auto;
                color: #fb7185;
                font-weight: 600;
            }

            nav.sidebar .logout:hover {
                background: rgba(225, 29, 72, 0.1);
                color: #fff;
            }

            main.content {
                flex: 1;
                padding: 0 40px 40px 40px;
                overflow-y: auto;
                background: #f8fafc;
            }

            main.content h2 {
                margin-bottom: 25px;
                color: #001f3f;
                font-weight: 700;
                font-size: 1.75rem;
            }

            /* Form elements */
            input[type="text"],
            input[type="password"],
            input[type="url"],
            input[type="email"],
            input[type="number"],
            textarea,
            select {
                width: 100%;
                padding: 10px 14px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-size: 0.95rem;
                font-family: inherit;
                background: #fff;
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            input:focus,
            textarea:focus,
            select:focus {
                outline: none;
                border-color: #2563eb;
                box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            }

            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 10px 20px;
                background: #2563eb;
                color: #fff;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 0.9rem;
                font-weight: 600;
                font-family: inherit;
                transition: all 0.2s;
                text-decoration: none;
            }

            .btn:hover {
                background: #1d4ed8;
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            }

            .btn-outline {
                background: transparent;
                border: 1px solid #e2e8f0;
                color: #475569;
            }

            .btn-outline:hover {
                background: #f1f5f9;
                border-color: #cbd5e1;
                color: #1e293b;
                box-shadow: none;
            }

            .btn-danger {
                background: #e11d48;
            }

            .btn-danger:hover {
                background: #be123c;
                box-shadow: 0 4px 12px rgba(225, 29, 72, 0.2);
            }

            .btn-success {
                background: #059669;
            }

            .btn-success:hover {
                background: #047857;
                box-shadow: 0 4px 12px rgba(5, 150, 105, 0.2);
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }

            th,
            td {
                padding: 10px 14px;
                text-align: left;
                border-bottom: 1px solid #e5e7eb;
            }

            th {
                background: #f8fafc;
                font-weight: 600;
                color: #475569;
            }

            tr:hover {
                background: #f8fafc;
            }

            .alert {
                padding: 12px 16px;
                border-radius: 6px;
                margin-bottom: 20px;
            }

            .alert-success {
                background: #dcfce7;
                color: #166534;
            }

            .alert-error {
                background: #fef2f2;
                color: #991b1b;
            }

            .form-group {
                margin-bottom: 15px;
            }

            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                color: #374151;
            }

            .form-inline {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }
        </style>
    </head>

    <body>
        <nav class="sidebar">
            <h3>🔧 CMS Admin</h3>
            <a href="index.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'index.php' ? "class='active'" : ''; ?>>📊
                Dashboard</a>

            <?php if (is_admin()): ?>
                <a href="posts.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'posts.php' ? "class='active'" : ''; ?>>📝
                    Tartalom</a>
                <a href="categories.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'categories.php' ? "class='active'" : ''; ?>>🏷️ Kategóriák</a>
                <a href="media.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'media.php' ? "class='active'" : ''; ?>>🖼️
                    Média</a>
                <a href="galleries.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'galleries.php' ? "class='active'" : ''; ?>>🖼️ Galériák</a>
            <?php endif; ?>

            <?php if (is_super_admin()): ?>
                <a href="menu.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'menu.php' ? "class='active'" : ''; ?>>📋
                    Menü</a>
                <div style="border-top: 1px solid #334155; margin: 15px 0;"></div>
                <a href="settings.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'settings.php' ? "class='active'" : ''; ?>>⚙️ Beállítások</a>
                <a href="backups.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'backups.php' ? "class='active'" : ''; ?>>📦 Mentések</a>
                <a href="activity_log.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'activity_log.php' ? "class='active'" : ''; ?>>📋 Napló</a>
                <a href="users.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'users.php' ? "class='active'" : ''; ?>>👥
                    Felhasználók</a>
            <?php endif; ?>

            <?php if (!is_super_admin() && !is_admin()): ?>
                <div style="border-top: 1px solid #334155; margin: 15px 0;"></div>
            <?php endif; ?>

            <a href="profile.php" <?php echo basename($_SERVER['SCRIPT_NAME']) === 'profile.php' ? "class='active'" : ''; ?>>👤 Profil</a>
            <a href="index.php?action=logout" class="logout">🚪 Kijelentkezés</a>
        </nav>
        <main class="content">
            <!-- Tetejére tapadó action bar -->
            <div
                style="position: sticky; top: 0; z-index: 999; background: #fff; padding: 15px 40px; margin: 0 -40px 30px -40px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <span style="font-weight: 600; color: #1e293b; font-size: 1.05rem;">👋 Üdv,
                        <?php echo htmlspecialchars($_SESSION['admin_user'] ?? 'Admin'); ?>!</span>
                    <?php if (!empty($topBar['view_link'])): ?>
                        <div style="width: 1px; height: 20px; background: #e5e7eb;"></div>
                        <a href="<?php echo htmlspecialchars($topBar['view_link']); ?>" target="_blank"
                            style="color: #3b82f6; text-decoration: none; font-size: 0.95rem; font-weight: 500;">👁️ Az oldal
                            megtekintése</a>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php echo $topBar['actions_html'] ?? ''; ?>
                </div>
            </div>

            <h2>
                <?php echo htmlspecialchars($pageTitle); ?>
            </h2>

            <?php
            // Flash üzenetek megjelenítése
            if (isset($_SESSION['flash_message'])) {
                $fType = $_SESSION['flash_type'] ?? 'success';
                echo '<div class="alert alert-' . $fType . '">' . htmlspecialchars($_SESSION['flash_message']) . '</div>';
                unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            }
            ?>
            <?php
}

function admin_footer(): void
{
    ?>
        </main>
    </body>

    </html>
    <?php
}
