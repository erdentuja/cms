<?php
/**
 * Adatbázis és Fájl biztonsági mentés (Backup) és visszaállítás (Restore) osztály
 */
class BackupManager
{
    private PDO $db;
    private string $backupDir;
    private string $rootDir;

    public function __construct(PDO $db, string $backupDir, string $rootDir)
    {
        $this->db = $db;
        $this->backupDir = rtrim($backupDir, '/\\') . '/';
        $this->rootDir = rtrim($rootDir, '/\\') . '/';

        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
            file_put_contents($this->backupDir . '.htaccess', "Order deny,allow\nDeny from all\n");
        }
    }

    /**
     * Visszaadja a meglévő mentések listáját
     */
    public function getBackupsList(): array
    {
        $backups = [];
        if ($handle = opendir($this->backupDir)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != ".." && pathinfo($entry, PATHINFO_EXTENSION) === 'zip') {
                    $filepath = $this->backupDir . $entry;
                    $backups[] = [
                        'filename' => $entry,
                        'size' => filesize($filepath),
                        'date' => filemtime($filepath),
                        'path' => $filepath
                    ];
                }
            }
            closedir($handle);
        }

        // Rendezzük dátum szerint csökkenő sorrendbe
        usort($backups, function ($a, $b) {
            return $b['date'] <=> $a['date'];
        });

        return $backups;
    }

    /**
     * Új mentés készítése
     * 
     * @param array $exclusions Opcionális lista a kizárandó mappákról/fájlokról
     * @return string A generált backup fájlnév (zip) befejezve, vagy kivétel
     */
    public function createBackup(array $exclusions = []): string
    {
        $dateStr = date('Y-m-d_H-i-s');
        $backupFilename = "backup_{$dateStr}.zip";
        $backupPath = $this->backupDir . $backupFilename;
        $sqlFilename = "database_{$dateStr}.sql";
        $sqlPath = $this->backupDir . $sqlFilename;

        // 1. Adatbázis mentése SQL fájlba
        $this->exportDatabase($sqlPath);

        // 2. Minden becsomagolása ZIP-be
        $zip = new ZipArchive();
        if ($zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception("Nem sikerült létrehozni a ZIP archívumot: {$backupPath}");
        }

        // Hozzáadjuk az adatbázis sql fájlt
        $zip->addFile($sqlPath, 'database.sql');

        // Hozzáadjuk a fájlrendszert (gyökeret)
        $this->addFolderToZip($this->rootDir, $zip, $this->rootDir, $exclusions);

        $zip->close();

        // Töröljük a nyers SQL fájlt, miután bekerült a ZIP-be
        @unlink($sqlPath);

        return $backupFilename;
    }

    /**
     * Mentés törlése
     */
    public function deleteBackup(string $filename): bool
    {
        $path = $this->backupDir . basename($filename);
        if (file_exists($path) && pathinfo($path, PATHINFO_EXTENSION) === 'zip') {
            if (unlink($path)) {
                return true;
            } else {
                $error = error_get_last();
                throw new Exception("Nem sikerült törölni a fájlt. Rendszerüzenet: " . ($error['message'] ?? 'Engedély vagy zárolás hiba.'));
            }
        }
        throw new Exception("A mentésfájl nem található: " . basename($filename));
    }

    /**
     * Biztonsági mentés letöltése böngészőből
     */
    public function downloadBackup(string $filename): void
    {
        $path = $this->backupDir . basename($filename);
        if (file_exists($path) && pathinfo($path, PATHINFO_EXTENSION) === 'zip') {
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Content-Length: ' . filesize($path));
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($path);
            exit;
        }
    }

    /**
     * Rendszer visszaállítása egy kiválasztott mentésből
     * 
     * WARNING: Ez felülírja a fájlokat és a teljes adatbázist
     */
    public function restoreBackup(string $filename): void
    {
        $backupPath = $this->backupDir . basename($filename);
        if (!file_exists($backupPath) || pathinfo($backupPath, PATHINFO_EXTENSION) !== 'zip') {
            throw new Exception("A kiválasztott mentés nem található vagy érvénytelen.");
        }

        $tempDir = $this->backupDir . 'restore_temp_' . time() . '/';
        if (!mkdir($tempDir)) {
            throw new Exception("Nem sikerült létrehozni a temp könyvtárat a visszaállításhoz.");
        }

        // 1. Kicsomagoljuk a ZIP-t a temp könyvtárba
        $zip = new ZipArchive();
        if ($zip->open($backupPath) === true) {
            $zip->extractTo($tempDir);
            $zip->close();
        } else {
            $this->removeDirectory($tempDir);
            throw new Exception("A ZIP fájl kicsomagolása sikertelen.");
        }

        // 2. Adatbázis visszaállítása
        $sqlFile = $tempDir . 'database.sql';
        if (file_exists($sqlFile)) {
            $this->importDatabase($sqlFile);
            unlink($sqlFile); // Biztonság kedvéért töröljük, mielőtt a fájlokat másoljuk
        } else {
            $this->removeDirectory($tempDir);
            throw new Exception("Az adatbázis sql fájl hiányzik a mentésből.");
        }

        // 3. Fájlok visszaállítása (felülírás)
        $this->copyDirectory($tempDir, $this->rootDir);

        // 4. Temp könyvtár takarítása
        $this->removeDirectory($tempDir);
    }

    /**
     * Könyvtár tartalmának másolása felülírással
     */
    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            // A backups mappát ne írjuk felül, különben önmagát is belemásolná loopban vagy eltüntetné az új mentéseket!
            // Illetve ha valami egyezik a biztonsági mappával, ugorjuk át
            if (strpos($item->getPathname(), normpath($this->backupDir)) !== false || strpos($item->getPathname(), 'backups') !== false) {
                continue;
            }

            $target = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathname();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Könyvtár és tartalmának törlése
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir))
            return;

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }

    /**
     * Rekurzív mappa hozzáadás a ZIP-hez
     */
    private function addFolderToZip(string $folder, ZipArchive $zip, string $basePath, array $exclusions = [])
    {
        $handle = opendir($folder);
        while (false !== ($file = readdir($handle))) {
            if ($file != '.' && $file != '..') {
                $filePath = $folder . $file;

                // Alapvető biztonsági kizárások
                if (
                    strpos($filePath, normpath($this->backupDir)) !== false ||
                    $file === 'backups' ||
                    $file === '.git' ||
                    strpos($filePath, '.git') !== false
                ) {
                    continue;
                }

                $localPath = substr($filePath, strlen($basePath));
                $localPathNorm = normpath($localPath);

                // Felhasználói kizárások ellenőrzése
                $skip = false;
                foreach ($exclusions as $excl) {
                    $exclNorm = normpath($excl);
                    // Ha a helyi útvonal elején pontosan szerepel a mappa neve (pl. "uploads") vagy a pontos fájlnév
                    if ($localPathNorm === $exclNorm || strpos($localPathNorm, $exclNorm . DIRECTORY_SEPARATOR) === 0) {
                        $skip = true;
                        break;
                    }
                }

                if ($skip) {
                    continue;
                }

                if (is_file($filePath)) {
                    $zip->addFile($filePath, $localPath);
                } elseif (is_dir($filePath)) {
                    $zip->addEmptyDir($localPath);
                    $this->addFolderToZip($filePath . '/', $zip, $basePath, $exclusions);
                }
            }
        }
        closedir($handle);
    }

    /**
     * Teljes adatbázis exportálása SQL fáljba
     */
    private function exportDatabase(string $filepath): void
    {
        $f = fopen($filepath, 'w+');
        if (!$f) {
            throw new Exception("Nem lehet írni a mentési fájlt: " . $filepath);
        }

        fwrite($f, "-- CMS Adatbázis Biztonsági Mentés\n");
        fwrite($f, "-- Ideje: " . date('Y-m-d H:i:s') . "\n\n");
        fwrite($f, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $this->db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // Tábla szerkezet
            $createTableStmt = $this->db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            fwrite($f, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($f, $createTableStmt['Create Table'] . ";\n\n");

            // Tábla adatok
            $rows = $this->db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                foreach ($rows as $row) {
                    $keys = array_keys($row);
                    $values = array_values($row);

                    $keysStr = implode('`, `', $keys);
                    $valuesStr = implode(', ', array_map(function ($val) {
                        if ($val === null)
                            return 'NULL';
                        return $this->db->quote((string) $val);
                    }, $values));

                    fwrite($f, "INSERT INTO `{$table}` (`{$keysStr}`) VALUES ({$valuesStr});\n");
                }
                fwrite($f, "\n");
            }
        }

        fwrite($f, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($f);
    }

    /**
     * Adatbázis importálása SQL fájlból
     */
    private function importDatabase(string $filepath): void
    {
        $sql = file_get_contents($filepath);
        if ($sql === false) {
            throw new Exception("Nem sikerült beolvasni az adatbázis SQL fájlt.");
        }

        try {
            // Kisebb utasításokká tördeljük, vagy egyben futtatjuk ha a PDO bírja (általában bírja az egyben lévőt multi-queryként)
            // Biztonságosabb egyben executolni, amiben benne van a FOREIGN_KEY_CHECKS=0
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, 0); // Biztosítjuk a multi-query működését (driver-függő)
            $this->db->exec($sql);
        } catch (PDOException $e) {
            // Fallback, ha a multi-query exec nem futna le PDO-ban (ritka MySQLnél, de előfordul)
            $this->db->exec("SET FOREIGN_KEY_CHECKS = 0;");

            // Fájl feldolgozása darabokban
            $commands = explode(';', $sql);
            foreach ($commands as $command) {
                $command = trim($command);
                if (!empty($command)) {
                    $this->db->exec($command);
                }
            }

            $this->db->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    }
}

function normpath($path)
{
    return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}
