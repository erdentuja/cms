<?php
require_once '../config.php';
require_once '../core/functions.php';

if (!isset($_SESSION['admin_id'])) {
    die('Nem vagy bejelentkezve.');
}

if (function_exists('is_super_admin') && !is_super_admin()) {
    die('Nincs jogosultságod ehhez.');
}

echo "<h2>Sablonok tábla törlése</h2>";

try {
    // Ellenőrizzük, hogy létezik-e a tábla
    $tables = $db->query("SHOW TABLES LIKE 'templates'")->fetchAll();

    if (!empty($tables)) {
        $db->exec("DROP TABLE templates");
        echo "✓ templates tábla törölve<br>";
        echo "<br><strong style='color: green;'>✅ Sablon rendszer teljesen eltávolítva!</strong><br>";
    } else {
        echo "<strong style='color: orange;'>⚠️ A templates tábla nem létezik, nincs mit törölni.</strong><br>";
    }

    echo "<br><a href='index.php'>← Vissza a dashboardra</a>";

} catch (PDOException $e) {
    echo "<strong style='color: red;'>❌ Hiba: " . htmlspecialchars($e->getMessage()) . "</strong>";
}
