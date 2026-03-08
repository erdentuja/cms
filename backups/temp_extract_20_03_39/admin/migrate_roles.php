<?php
require_once '../config.php';
session_start();

// Ellenőrizzük, hogy van-e már admin bejelentkezve
if (!isset($_SESSION['admin_id'])) {
    die('Ezt a szkriptet csak bejelentkezett felhasználó futtathatja!');
}

try {
    // 1. Oszlopok hozzáadása a users táblához, ha még nincsenek
    $columns = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('role', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN role ENUM('super_admin', 'admin', 'registered') NOT NULL DEFAULT 'registered' AFTER password_hash");
        echo "<p>✅ 'role' oszlop létrehozva a users táblában.</p>";
    } else {
        echo "<p>ℹ️ A 'role' oszlop már létezik.</p>";
    }

    if (!in_array('created_at', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        echo "<p>✅ 'created_at' oszlop létrehozva a users táblában.</p>";
    } else {
        echo "<p>ℹ️ A 'created_at' oszlop már létezik.</p>";
    }

    // 2. Az első usert (vagy a jelenlegit) kinevezzük szuper adminnak
    $db->exec("UPDATE users SET role = 'super_admin' WHERE id = " . (int) $_SESSION['admin_id'] . " OR id = 1");
    echo "<p>✅ Admin fiók(ok) super_admin-ná léptetve.</p>";

    // Frissítsük a sessiont is azonnal
    $_SESSION['admin_role'] = 'super_admin';

    echo "<h3 style='color:green'>A migráció sikeresen lefutott!</h3>";
    echo "<p>Ide kattintva visszatérhetsz az <a href='index.php'>admin felületre</a>.</p>";

} catch (PDOException $e) {
    die("Hiba történt a migráció során: " . $e->getMessage());
}
