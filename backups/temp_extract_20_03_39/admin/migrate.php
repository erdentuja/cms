<?php
/**
 * CMS Fázis 2 – Adatbázis migráció
 * Futtatás: böngészőben nyisd meg: http://localhost/cms/admin/migrate.php
 * Futtatás után TÖRÖLD EZT A FÁJLT!
 */
require_once '../config.php';

if (!isset($_SESSION['admin_id'])) {
    die('Jelentkezz be az adminba először.');
}

$results = [];

try {
    // 1. post_type mező
    $db->exec("ALTER TABLE posts ADD COLUMN IF NOT EXISTS post_type VARCHAR(20) DEFAULT 'page' AFTER status");
    $results[] = '✅ posts.post_type mező hozzáadva';

    // 2. featured_image mező
    $db->exec("ALTER TABLE posts ADD COLUMN IF NOT EXISTS featured_image VARCHAR(255) DEFAULT NULL AFTER meta_description");
    $results[] = '✅ posts.featured_image mező hozzáadva';

    // 3. publish_at mező
    $db->exec("ALTER TABLE posts ADD COLUMN IF NOT EXISTS publish_at DATETIME DEFAULT NULL AFTER featured_image");
    $results[] = '✅ posts.publish_at mező hozzáadva';

    // 4. Kategóriák tábla
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        slug VARCHAR(100) UNIQUE NOT NULL,
        description TEXT,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $results[] = '✅ categories tábla létrehozva';

    // 5. Post-kategória kapcsolótábla
    $db->exec("CREATE TABLE IF NOT EXISTS post_categories (
        post_id INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (post_id, category_id),
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");
    $results[] = '✅ post_categories kapcsolótábla létrehozva';

} catch (Exception $e) {
    $results[] = '❌ Hiba: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <title>DB Migráció</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            max-width: 600px;
            margin: 60px auto;
            background: #f4f7f6;
        }

        .box {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .result {
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            border-radius: 6px;
        }
    </style>
</head>

<body>
    <div class="box">
        <h2>🔄 Fázis 2 – DB Migráció</h2>
        <?php foreach ($results as $r): ?>
            <div class="result">
                <?php echo $r; ?>
            </div>
        <?php endforeach; ?>
        <p style="margin-top: 20px; color: #dc2626; font-weight: 600;">⚠️ Töröld ezt a fájlt a migráció után!</p>
        <a href="index.php" class="btn">← Vissza az adminba</a>
    </div>
</body>

</html>