<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once '../core/BackupManager.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_super_admin()) {
    header('Location: index.php');
    exit;
}

// Inaktiváljuk a futási és memória korlátokat, mivel a ZIPelés/Kicsomagolás sokáig tarthat
set_time_limit(0);
ini_set('memory_limit', '512M');

$backupDir = __DIR__ . '/../backups/';
$rootDir = __DIR__ . '/../';
$backupManager = new BackupManager($db, $backupDir, $rootDir);

$message = '';
$messageType = '';

// Kérések kezelése
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        try {
            if (isset($_POST['action'])) {
                $action = $_POST['action'];

                if ($action === 'create') {
                    $exclusionsArr = $_POST['exclusions'] ?? [];
                    if (!is_array($exclusionsArr))
                        $exclusionsArr = [];

                    $filename = $backupManager->createBackup($exclusionsArr);
                    require_once '../core/functions.php';
                    log_activity($db, 'create', 'backup', 0, $filename);
                    $message = "Biztonsági mentés sikeresen elkészült: {$filename}";
                    $messageType = 'success';
                } elseif ($action === 'delete') {
                    $filename = $_POST['filename'] ?? '';
                    $backupManager->deleteBackup($filename);
                    require_once '../core/functions.php';
                    log_activity($db, 'delete', 'backup', 0, $filename);
                    $message = "Biztonsági mentés törölve: {$filename}";
                    $messageType = 'success';
                } elseif ($action === 'restore') {
                    $filename = $_POST['filename'] ?? '';
                    $backupManager->restoreBackup($filename);
                    require_once '../core/functions.php';
                    log_activity($db, 'restore', 'backup', 0, $filename);
                    $message = "✅ Rendszer sikeresen visszaállítva a(z) {$filename} mentésből!";
                    $messageType = 'success';
                } elseif ($action === 'upload') {
                    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                        $file = $_FILES['backup_file'];
                        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                        if ($ext !== 'zip') {
                            $message = "Csak ZIP kiterjesztésű fájl tölthető fel!";
                            $messageType = 'error';
                        } else {
                            $targetPath = $backupDir . basename($file['name']);
                            if (file_exists($targetPath)) {
                                $message = "Már létezik ilyen nevű mentés!";
                                $messageType = 'error';
                            } else {
                                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                                    require_once '../core/functions.php';
                                    log_activity($db, 'upload', 'backup', 0, $file['name']);
                                    $message = "Mentés sikeresen feltöltve: {$file['name']}";
                                    $messageType = 'success';
                                } else {
                                    $message = "Hiba történt a fájl másolásakor.";
                                    $messageType = 'error';
                                }
                            }
                        }
                    } else {
                        $message = "Kérjük, válasszon ki egy fájlt a feltöltéshez!";
                        $messageType = 'error';
                    }
                }
            }
        } catch (Exception $e) {
            $message = "Hiba: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fájlok letöltésének kiszolgálása GET kérésre (nem form)
if (isset($_GET['download']) && !empty($_GET['filename'])) {
    // Ellenőrizzük, hogy Szuper Admin-e
    if (is_super_admin()) {
        $filename = basename($_GET['filename']);
        $backupManager->downloadBackup($filename);
    }
}

$backups = $backupManager->getBackupsList();

// Top szintű mappák lekérdezése a root könyvtárból
$topDirs = [];
$iterator = new DirectoryIterator($rootDir);
foreach ($iterator as $fileinfo) {
    if ($fileinfo->isDir() && !$fileinfo->isDot()) {
        $dirname = $fileinfo->getFilename();
        if ($dirname !== 'backups' && $dirname !== '.git') {
            $topDirs[] = $dirname;
        }
    }
}
sort($topDirs);

admin_header('Biztonsági mentések');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 25px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <div>
            <h3 style="margin: 0; color: #1e293b;">📦 Mentések (Backups)</h3>
            <p style="color: #64748b; font-size: 0.9rem; margin-top: 5px;">A rendszer a teljes adatbázist és a weboldal
                fájljait is menti.</p>
        </div>
        <form method="post"
            onsubmit="return confirm('Biztosan készítesz egy új biztonsági mentést? Ez eltarthat egy ideig.');"
            style="display: flex; gap: 15px; align-items: flex-start; flex-wrap: wrap;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <div style="flex: 1; min-width: 300px;">
                <label
                    style="font-size: 0.85rem; color: #475569; display: block; margin-bottom: 8px; font-weight: 600;">Kizárandó
                    mappák (amiket NEM akarsz beletenni a mentésbe):</label>
                <div
                    style="display: flex; gap: 10px; flex-wrap: wrap; max-height: 120px; overflow-y: auto; border: 1px solid #cbd5e1; padding: 10px; border-radius: 6px; background: #f8fafc;">
                    <?php foreach ($topDirs as $dir): ?>
                        <label
                            style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; cursor: pointer; background: #fff; padding: 4px 10px; border-radius: 4px; border: 1px solid #e2e8f0; user-select: none;">
                            <input type="checkbox" name="exclusions[]" value="<?php echo htmlspecialchars($dir); ?>">
                            <span style="color: #334155;">📁 <?php echo htmlspecialchars($dir); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-success"
                style="font-weight: 600; display: flex; align-items: center; gap: 8px; height: 38px; margin-top: 25px;">
                ➕ Mentés Létrehozása
            </button>
        </form>
    </div>

    <!-- Betöltés UI a hosszú folyamatokhoz -->
    <div id="loader"
        style="display: none; padding: 20px; text-align: center; background: #eff6ff; border-radius: 8px; border: 1px solid #bfdbfe; margin-bottom: 20px; margin-top: 20px;">
        <div style="font-size: 2rem; display: inline-block; animation: spin 2s linear infinite; margin-bottom: 10px;">⏳
        </div>
        <h4 style="margin: 0; color: #1e40af;">Folyamatban...</h4>
        <p style="margin-top: 5px; color: #3b82f6; font-size: 0.9rem;">Kérlek légy türelemmel, ez eltarthat 1-2 percig.
            Ne zárd be az ablakot!</p>
        <style>
            @keyframes spin {
                100% {
                    transform: rotate(360deg);
                }
            }
        </style>
    </div>

    <!-- Feltöltés űrlap -->
    <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        <h4 style="margin: 0 0 10px 0; color: #1e293b; font-size: 1rem;">⬇️ Régi mentés feltöltése (ZIP)</h4>
        <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px;">Ha korábban letöltöttél egy ZIP fájlt, itt
            feltöltheted a szerverre, hogy visszaállítsd belőle az adatokat.</p>
        <form method="post" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center;"
            onsubmit="return confirm('Biztosan feltöltöd ezt a mentést? A visszaállításhoz majd a táblázatban kell a Restore gombra kattintanod.');">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="upload">
            <input type="file" name="backup_file" accept=".zip" required
                style="padding: 6px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem; background: #f8fafc; flex: 1; max-width: 400px;">
            <button type="submit" class="btn"
                style="background: #3b82f6; color: #fff; font-weight: 600; padding: 8px 15px;">
                📤 Feltöltés
            </button>
        </form>
    </div>
</div>

<script>
    // Form küldésnél jelenítsük meg a betöltőt
    document.querySelectorAll('form').forEach(f => {
        f.addEventListener('submit', function (e) {
            // Csak a mentéshez és visszaállításhoz
            const action = this.querySelector('input[name="action"]')?.value;
            if (action === 'create' || action === 'restore') {
                document.getElementById('loader').style.display = 'block';
                this.style.opacity = '0.5';
            }
        });
    });
</script>
</div>

<!-- Mentések listája -->
<div style="background: #fff; padding: 0; border-radius: 10px; border: 1px solid #e5e7eb; overflow: hidden;">
    <?php if (empty($backups)): ?>
        <div style="padding: 40px 20px; text-align: center; color: #94a3b8;">
            <div style="font-size: 3rem; margin-bottom: 10px;">🗄️</div>
            Még nem készült biztonsági mentés. Csinálj egyet most!
        </div>
    <?php else: ?>
        <table style="margin-top: 0;">
            <thead style="background: #f8fafc; border-bottom: 2px solid #e5e7eb;">
                <tr>
                    <th style="padding: 15px;">Fájlnév</th>
                    <th style="padding: 15px;">Dátum</th>
                    <th style="padding: 15px;">Méret</th>
                    <th style="padding: 15px; width: 250px;">Műveletek</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($backups as $index => $b): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="padding: 15px;">
                            <strong style="color: #334155;">ZIP Archívum</strong><br>
                            <span style="color: #64748b; font-size: 0.85rem; font-family: monospace;">
                                <?php echo htmlspecialchars($b['filename']); ?>
                            </span>
                        </td>
                        <td style="padding: 15px; color: #475569;">
                            <?php echo date('Y. m. d. - H:i', $b['date']); ?>
                            <?php if ($index === 0): ?>
                                <span
                                    style="background:#22c55e; color:#fff; font-size:0.7rem; padding: 2px 6px; border-radius: 10px; margin-left: 5px;">Legújabb</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 15px; color: #475569;">
                            <?php echo number_format($b['size'] / 1024 / 1024, 2); ?> MB
                        </td>
                        <td style="padding: 15px; display: flex; gap: 8px;">
                            <!-- Műveletek -->
                            <a href="?download=1&filename=<?php echo urlencode($b['filename']); ?>" class="btn"
                                style="background: #3b82f6; padding: 6px 10px; font-size: 0.85rem; text-decoration: none;">⬇️</a>

                            <form method="post" style="display:inline;"
                                onsubmit="return confirm('⚠️ FIGYELEM!\n\nA visszaállítás művelet VÉGLEGES. A jelenlegi adatbázis és minden új feltöltés, cikk felülíródik a(z) <?php echo htmlspecialchars($b['filename']); ?> mentés állapotára!\n\nBiztosan elindítod?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                <button type="submit" class="btn"
                                    style="background: #eab308; color: #fff; padding: 6px 10px; font-size: 0.85rem;"
                                    title="Rendszer visszaállítása ebből a mentésből">♻️ Restore</button>
                            </form>

                            <form method="post" style="display:inline;"
                                onsubmit="return confirm('Biztosan törlöd ezt a mentést?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($b['filename']); ?>">
                                <button type="submit" class="btn btn-danger" style="padding: 6px 10px; font-size: 0.85rem;"
                                    title="Törlés">🗑️</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php
admin_footer();
