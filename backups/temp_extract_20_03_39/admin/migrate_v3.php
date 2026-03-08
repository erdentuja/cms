<?php
/**
 * CMS Fázis 3 – Adatbázis migráció
 * Új funkciók: Lomtár (soft delete), Műveletnapló (activity log)
 * Futtatás: böngészőben nyisd meg: http://localhost/cms/admin/migrate_v3.php
 * Futtatás után TÖRÖLD EZT A FÁJLT!
 */
require_once '../config.php';

if (!isset($_SESSION['admin_id'])) {
    die('Jelentkezz be az adminba először.');
}

$results = [];

try {
    // 1. Soft delete mező a posts táblához
    $db->exec("ALTER TABLE posts ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
    $results[] = '✅ posts.deleted_at mező hozzáadva (soft delete)';

    // 2. Activity log tábla
    $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        username VARCHAR(100),
        action VARCHAR(50) NOT NULL,
        target_type VARCHAR(50) DEFAULT NULL,
        target_id INT DEFAULT NULL,
        target_title VARCHAR(255) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_user (user_id)
    )");
    $results[] = '✅ activity_log tábla létrehozva';

} catch (Exception $e) {
    $results[] = '❌ Hiba: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="hu">

<head>
    <meta charset="UTF-8">
    <title>DB Migráció v3</title>
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
        <h2>🔄 Fázis 3 – DB Migráció</h2>
        <p style="color: #64748b; margin-bottom: 15px;">Lomtár + Műveletnapló</p>
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