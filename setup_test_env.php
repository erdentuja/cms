<?php
/**
 * CMS Tesztkörnyezet Inicializáló Script (SQLite verzió)
 * Ez a script felülírja a config.php-t, beimportálja az adatokat a database_sqlite.sql-ből
 * és elindítja a beépített webszervert.
 */

echo "CMS Tesztkörnyezet inicializálása...\n";
echo "====================================\n\n";

// 1. Tesztadatbázis neve
$dbFile = __DIR__ . '/test.sqlite';

echo "[1] A config.php környezeti változókat fog használni, de a setup script létrehozza az SQLite adatbázist...\n";

// 2. test.sqlite inicializálása
$dbFile = __DIR__ . '/test.sqlite';
if (file_exists($dbFile)) {
    unlink($dbFile);
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sqlFile = __DIR__ . '/database_sqlite.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);

        // SQLite pdo tudja futtatni az egybefüggő SQL-t ha nincs benne szintaktikai hiba
        try {
            $db->exec($sql);
            echo "[2] test.sqlite adatbázis létrehozva és adatok beimportálva.\n";
        } catch(PDOException $e) {
            echo "  Figyelmeztetés/Hiba az SQL importálás során: " . $e->getMessage() . "\n";
            // Ha a teljes exec elszáll, megpróbáljuk darabonként futtatni, de figyelembe véve, hogy ; ne robbantsa szét az adatokat.
            // Egyszerű split megoldás helyett a legbiztosabb az egyben exec(), ha átalakítás megfelelő volt.
        }
    } else {
        echo "[2] HIBA: database_sqlite.sql nem található!\n";
    }

} catch (PDOException $e) {
    die("Hiba az adatbázis létrehozásakor: " . $e->getMessage() . "\n");
}

// 3. Mappák létrehozása
$folders = ['/uploads', '/backups'];
foreach ($folders as $folder) {
    $path = __DIR__ . $folder;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
        echo "[3] Mappa létrehozva: {$folder}\n";
    }
}

echo "\n====================================\n";
echo "KÉSZ! A tesztkörnyezet inicializálása befejeződött.\n";
