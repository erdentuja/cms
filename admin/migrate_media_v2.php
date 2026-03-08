<?php
require_once '../config.php';
require_once '../core/functions.php';

if (!isset($_SESSION['admin_id'])) {
    die('Nem vagy bejelentkezve.');
}

// Ellenőrizzük, hogy admin-e (ha a függvény létezik)
if (function_exists('is_admin') && !is_admin()) {
    die('Nincs jogosultságod ehhez.');
}

echo "<h2>Média tábla frissítése (v2)</h2>";

try {
    // Ellenőrizzük, hogy léteznek-e már a mezők
    $columns = $db->query("SHOW COLUMNS FROM media LIKE 'alt_text'")->fetchAll();

    if (empty($columns)) {
        // Alt text és caption mezők hozzáadása
        $db->exec("ALTER TABLE media ADD COLUMN alt_text VARCHAR(255) DEFAULT NULL AFTER filepath");
        echo "✓ alt_text mező hozzáadva<br>";

        $db->exec("ALTER TABLE media ADD COLUMN caption TEXT DEFAULT NULL AFTER alt_text");
        echo "✓ caption mező hozzáadva<br>";

        // File méret tárolása
        $db->exec("ALTER TABLE media ADD COLUMN file_size INT DEFAULT NULL AFTER caption");
        echo "✓ file_size mező hozzáadva<br>";

        // MIME típus tárolása
        $db->exec("ALTER TABLE media ADD COLUMN mime_type VARCHAR(100) DEFAULT NULL AFTER file_size");
        echo "✓ mime_type mező hozzáadva<br>";

        // Képméretek (szélesség x magasság)
        $db->exec("ALTER TABLE media ADD COLUMN width INT DEFAULT NULL AFTER mime_type");
        echo "✓ width mező hozzáadva<br>";

        $db->exec("ALTER TABLE media ADD COLUMN height INT DEFAULT NULL AFTER width");
        echo "✓ height mező hozzáadva<br>";

        // Miniatűr és különböző méretek tárolása (JSON formátumban)
        $db->exec("ALTER TABLE media ADD COLUMN sizes TEXT DEFAULT NULL AFTER height");
        echo "✓ sizes mező hozzáadva (thumbnails, medium, large)<br>";

        echo "<br><strong style='color: green;'>✅ Migráció sikeresen lefutott!</strong><br>";
    } else {
        echo "<strong style='color: orange;'>⚠️ A mezők már léteznek, migráció kihagyva.</strong><br>";
    }

    echo "<br><a href='media.php'>← Vissza a médiához</a>";

} catch (PDOException $e) {
    echo "<strong style='color: red;'>❌ Hiba: " . htmlspecialchars($e->getMessage()) . "</strong>";
}
