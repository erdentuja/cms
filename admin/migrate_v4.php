<?php
require_once '../config.php';

try {
    // Ellenőrizzük, hogy létezik-e már az oszlop
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'email'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL AFTER username");
        echo "✅ V4 migráció sikeres: kiterjesztettük a 'users' táblát az 'email' oszloppal.<br>";
    } else {
        echo "✅ A 'users' tábla már tartalmaz 'email' oszlopot.<br>";
    }

} catch (PDOException $e) {
    echo "❌ Hiba történt a migráció során: " . $e->getMessage() . "<br>";
}
