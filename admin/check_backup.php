<?php
require_once '../config.php';
require_once '../core/functions.php';

if (!isset($_SESSION['admin_id'])) {
    die('Nem vagy bejelentkezve.');
}

if (!is_super_admin()) {
    die('Nincs jogosultságod ehhez.');
}

echo "<h2>📊 Backup tartalom ellenőrző</h2>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
    .box { background: #fff; padding: 20px; margin: 15px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    h3 { color: #2563eb; margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
    th { background: #f8fafc; font-weight: 600; }
    .good { color: #16a34a; font-weight: bold; }
    .bad { color: #dc2626; font-weight: bold; }
    .info { background: #eff6ff; padding: 10px; border-left: 4px solid #2563eb; margin: 10px 0; }
</style>";

try {
    // ========================================
    // 1. ADATBÁZIS TÁBLÁK LISTÁJA
    // ========================================
    echo "<div class='box'>";
    echo "<h3>1️⃣ Adatbázisban lévő táblák</h3>";

    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $tableCount = count($tables);

    echo "<p class='good'>Összesen: {$tableCount} tábla</p>";
    echo "<table>";
    echo "<thead><tr><th>#</th><th>Tábla név</th><th>Sorok száma</th><th>Méret (becslés)</th></tr></thead>";
    echo "<tbody>";

    $totalRows = 0;
    foreach ($tables as $index => $table) {
        $rowCount = $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $totalRows += $rowCount;

        // Táblameméret becslése
        $status = $db->query("SHOW TABLE STATUS LIKE '{$table}'")->fetch(PDO::FETCH_ASSOC);
        $sizeKB = ($status['Data_length'] + $status['Index_length']) / 1024;

        echo "<tr>";
        echo "<td>" . ($index + 1) . "</td>";
        echo "<td><strong>{$table}</strong></td>";
        echo "<td style='text-align:right;'>" . number_format($rowCount) . "</td>";
        echo "<td style='text-align:right;'>" . number_format($sizeKB, 1) . " KB</td>";
        echo "</tr>";
    }

    echo "</tbody></table>";
    echo "<p style='margin-top:10px; color:#64748b;'>Összes sor: " . number_format($totalRows) . "</p>";
    echo "</div>";

    // ========================================
    // 2. LEGUTÓBBI BACKUP TARTALMÁNAK ELLENŐRZÉSE
    // ========================================
    echo "<div class='box'>";
    echo "<h3>2️⃣ Legutóbbi backup tartalmának ellenőrzése</h3>";

    $backupDir = __DIR__ . '/../backups/';
    $backups = [];

    if ($handle = opendir($backupDir)) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && pathinfo($entry, PATHINFO_EXTENSION) === 'zip') {
                $filepath = $backupDir . $entry;
                $backups[] = [
                    'filename' => $entry,
                    'date' => filemtime($filepath),
                    'path' => $filepath
                ];
            }
        }
        closedir($handle);
    }

    if (empty($backups)) {
        echo "<p class='bad'>❌ Nincs még backup fájl. Készíts egyet a Backups oldalon!</p>";
    } else {
        // Rendezzük dátum szerint csökkenő sorrendbe
        usort($backups, function ($a, $b) {
            return $b['date'] <=> $a['date'];
        });

        $latestBackup = $backups[0];
        echo "<p class='info'><strong>Legutóbbi backup:</strong> {$latestBackup['filename']}<br>";
        echo "<strong>Készítve:</strong> " . date('Y-m-d H:i:s', $latestBackup['date']) . "</p>";

        // ZIP tartalmának vizsgálata
        $zip = new ZipArchive();
        if ($zip->open($latestBackup['path']) === true) {
            echo "<h4>ZIP tartalma:</h4>";
            echo "<table>";
            echo "<thead><tr><th>#</th><th>Fájlnév</th><th>Méret</th></tr></thead>";
            echo "<tbody>";

            $hasDatabaseSQL = false;
            $fileCount = $zip->numFiles;

            for ($i = 0; $i < $fileCount; $i++) {
                $stat = $zip->statIndex($i);
                $filename = $stat['name'];
                $size = $stat['size'];

                if ($filename === 'database.sql') {
                    $hasDatabaseSQL = true;
                    echo "<tr style='background:#dcfce7;'>";
                } else {
                    echo "<tr>";
                }

                echo "<td>" . ($i + 1) . "</td>";
                echo "<td><strong>{$filename}</strong></td>";
                echo "<td style='text-align:right;'>" . number_format($size / 1024, 1) . " KB</td>";
                echo "</tr>";
            }

            echo "</tbody></table>";

            // Adatbázis SQL tartalmának elemzése
            if ($hasDatabaseSQL) {
                echo "<h4 style='margin-top:20px;'>database.sql tartalma:</h4>";
                $sqlContent = $zip->getFromName('database.sql');

                if ($sqlContent) {
                    // Keressük meg a CREATE TABLE utasításokat
                    preg_match_all('/CREATE TABLE `([^`]+)`/i', $sqlContent, $matches);
                    $tablesInBackup = $matches[1];

                    echo "<p class='good'>Táblák a mentésben: " . count($tablesInBackup) . " db</p>";

                    echo "<table>";
                    echo "<thead><tr><th>#</th><th>Tábla név</th><th>Státusz</th></tr></thead>";
                    echo "<tbody>";

                    foreach ($tablesInBackup as $index => $tableName) {
                        $inDB = in_array($tableName, $tables);
                        echo "<tr>";
                        echo "<td>" . ($index + 1) . "</td>";
                        echo "<td><strong>{$tableName}</strong></td>";
                        echo "<td>" . ($inDB ? "<span class='good'>✓ Létezik</span>" : "<span class='bad'>✗ Hiányzik az adatbázisból</span>") . "</td>";
                        echo "</tr>";
                    }

                    echo "</tbody></table>";

                    // Hiányzó táblák keresése
                    echo "<h4 style='margin-top:20px;'>❓ Mely táblák HIÁNYOZNAK a backup-ból?</h4>";
                    $missingTables = array_diff($tables, $tablesInBackup);

                    if (empty($missingTables)) {
                        echo "<p class='good'>✅ Minden tábla benne van a mentésben!</p>";
                    } else {
                        echo "<p class='bad'>❌ A következő " . count($missingTables) . " tábla HIÁNYZIK a mentésből:</p>";
                        echo "<ul>";
                        foreach ($missingTables as $missingTable) {
                            $rowCount = $db->query("SELECT COUNT(*) FROM `{$missingTable}`")->fetchColumn();
                            echo "<li><strong>{$missingTable}</strong> ({$rowCount} sor)</li>";
                        }
                        echo "</ul>";
                    }

                } else {
                    echo "<p class='bad'>❌ Nem sikerült beolvasni a database.sql fájlt!</p>";
                }
            } else {
                echo "<p class='bad'>❌ HIÁNYZIK a database.sql fájl a ZIP-ből!</p>";
            }

            $zip->close();
        } else {
            echo "<p class='bad'>❌ Nem sikerült megnyitni a ZIP fájlt!</p>";
        }
    }

    echo "</div>";

    // ========================================
    // 3. ÖSSZEGZÉS ÉS JAVASLATOK
    // ========================================
    echo "<div class='box'>";
    echo "<h3>3️⃣ Összegzés</h3>";
    echo "<p>✅ Adatbázis táblák száma: <strong>{$tableCount}</strong></p>";

    if (!empty($backups)) {
        $tablesInBackupCount = isset($tablesInBackup) ? count($tablesInBackup) : 0;
        $missingCount = isset($missingTables) ? count($missingTables) : 0;

        echo "<p>✅ Backup-ban lévő táblák: <strong>{$tablesInBackupCount}</strong></p>";

        if ($missingCount > 0) {
            echo "<p class='bad'>❌ Hiányzó táblák: <strong>{$missingCount}</strong></p>";
            echo "<div class='info' style='background:#fef2f2; border-left-color:#dc2626;'>";
            echo "<strong>⚠️ Probléma észlelve!</strong><br>";
            echo "Nem minden tábla került bele a mentésbe. Valószínű okok:<br>";
            echo "• Időtúllépés (timeout) a mentés közben<br>";
            echo "• Memória limit elérése<br>";
            echo "• Hiba történt egy tábla mentésekor, de csendben kihagyta<br><br>";
            echo "Javasolt megoldás: Növeld a memória limitet és időkorlátot a BackupManager-ben.";
            echo "</div>";
        } else {
            echo "<p class='good'>✅ Minden tábla benne van a mentésben! 🎉</p>";
        }
    }

    echo "</div>";

    echo "<p style='text-align:center; margin-top:30px;'><a href='backups.php' style='padding:10px 20px; background:#2563eb; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;'>← Vissza a Backups-hoz</a></p>";

} catch (Exception $e) {
    echo "<div class='box'>";
    echo "<p class='bad'>❌ Hiba: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
