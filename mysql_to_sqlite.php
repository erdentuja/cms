<?php
$sql = file_get_contents('database.sql');

// 1. Eltávolítjuk a FOREIGN_KEY_CHECKS beállításokat
$sql = preg_replace('/SET FOREIGN_KEY_CHECKS\s*=\s*[01];/i', '', $sql);

// 2. Töröljük a backtickeket
$sql = str_replace('`', '', $sql);

// Escaped idézőjelek kezelése (az SQL dump \' használ, SQLite '' vár)
$sql = str_replace("\\'", "''", $sql);

// 3. AUTO_INCREMENT cseréje AUTOINCREMENT-re
$sql = preg_replace('/AUTO_INCREMENT(=\d+)?/i', 'AUTOINCREMENT', $sql);

// 4. ENGINE, CHARSET, COLLATE eltávolítása a tábla végéről
$sql = preg_replace('/ENGINE=InnoDB.*?;/i', ';', $sql);
$sql = preg_replace('/DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/i', ';', $sql);
$sql = preg_replace('/DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/i', ';', $sql);

// 5. INT(11) cseréje INTEGER-re, mivel az SQLite AUTOINCREMENT csak INTEGER PRIMARY KEY esetén működik
$sql = preg_replace('/int\(\d+\)/i', 'INTEGER', $sql);

// 6. UNIQUE KEY és KEY eltávolítása a tábla definíciókból (SQLite máshogy kezeli, vagy utólag kéne indexelni, teszthez kihagyjuk a sima kulcsokat, az UNIQUE-ot beolvasztjuk ha lehet, de egyszerűbb kivenni a teszthez)
// Ezt egyszerűsített regex-szel csináljuk
$sql = preg_replace('/,\s*(UNIQUE )?KEY\s+.*?\(.*?\)/i', '', $sql);
$sql = preg_replace('/,\s*CONSTRAINT\s+.*?FOREIGN KEY\s*\(.*?\)\s*REFERENCES\s+.*?\s*ON DELETE CASCADE/i', '', $sql);

// Javítjuk az AUTOINCREMENT szintaxist: az SQLite-ban "INTEGER PRIMARY KEY AUTOINCREMENT" formában kell lennie.
// Tehát az id INTEGER NOT NULL AUTOINCREMENT, PRIMARY KEY (id) átalakítása:
$sql = preg_replace('/(id\s+INTEGER\s+NOT NULL\s+)AUTOINCREMENT/i', '$1PRIMARY KEY AUTOINCREMENT', $sql);
// és kivesszük a különálló PRIMARY KEY definíciót
$sql = preg_replace('/,\s*PRIMARY KEY\s*\([^\)]+\)/i', '', $sql);

// Mivel az eredeti dump explode(';', $sql)-lel lesz szétszedve a PHP kódban, de a tartalom tartalmazhat ';' karaktert (például HTML entitások &eacute; )
// az SQLite beolvasónak ez gondot okoz, ha mi robbantjuk fel. Ezért a mysql_to_sqlite feladata, hogy csak a tiszta SQL maradjon,
// vagy megváltoztatjuk a setup_test_env.php-t, hogy PDO exec($sql)-t csináljon egyszerre.
// Ehelyett töröljük a felesleges MySQL specifikus dolgokat.
$sql = preg_replace('/CHARACTER SET utf8mb4 COLLATE utf8mb4_bin/i', '', $sql);

// 7. current_timestamp() -> CURRENT_TIMESTAMP
$sql = str_replace('current_timestamp()', 'CURRENT_TIMESTAMP', $sql);

// 8. timestamp cseréje DATETIME-ra
$sql = preg_replace('/timestamp\s+NOT NULL\s+DEFAULT\s+CURRENT_TIMESTAMP/i', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql);

// 9. CHECK JSON kényszer eltávolítása (a settings táblából)
$sql = preg_replace('/CHECK\s*\(json_valid\([^)]+\)\)/i', '', $sql);

// 10. datetime DEFAULT NULL
$sql = preg_replace('/datetime DEFAULT NULL/i', 'DATETIME DEFAULT NULL', $sql);

// 11. Szenzitív adatok sanitizálása (pl. jelszavak, API kulcsok a settings táblából)
$sql = str_replace('sk-3e97a4d13e8c4b0ea3c7f48e5037c476', 'DUMMY_API_KEY', $sql);
$sql = str_replace('680817-Aa', 'DUMMY_PASSWORD', $sql);

file_put_contents('database_sqlite.sql', $sql);
echo "database_sqlite.sql elkészült.\n";
