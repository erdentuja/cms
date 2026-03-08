<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_super_admin()) {
    header('Location: index.php');
    exit;
}

$message = '';
$messageType = '';

// ---------- Mentés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        // Logó feltöltés
        $logoPath = '';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (in_array($ext, $allowed) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                $name = 'logo_' . time() . '.' . $ext;
                $dir = '../uploads';
                if (!is_dir($dir))
                    mkdir($dir, 0755, true);
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $name)) {
                    $logoPath = $name;
                }
            }
        }

        // Site info
        $siteInfo = [
            'name' => trim($_POST['site_name'] ?? ''),
            'description' => trim($_POST['site_description'] ?? ''),
        ];
        if ($logoPath) {
            $siteInfo['logo'] = $logoPath;
        } else {
            // Meglévő logó megtartása
            $existing = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'site_info'")->fetchColumn();
            if ($existing) {
                $existingData = json_decode($existing, true);
                if (!empty($existingData['logo'])) {
                    $siteInfo['logo'] = $existingData['logo'];
                }
            }
        }

        // Logó törlése
        if (isset($_POST['remove_logo'])) {
            unset($siteInfo['logo']);
        }

        $design = [
            'footer_text' => trim($_POST['footer_text'] ?? ''),
            'primary_color' => trim($_POST['primary_color'] ?? '#2563eb'),
        ];

        // AI
        $ai = [
            'deepseek_api_key' => trim($_POST['deepseek_api_key'] ?? '')
        ];

        // SMTP
        $smtp = [
            'host' => trim($_POST['smtp_host'] ?? ''),
            'user' => trim($_POST['smtp_user'] ?? ''),
            'pass' => trim($_POST['smtp_pass'] ?? ''),
            'port' => intval($_POST['smtp_port'] ?? 587),
            'encryption' => trim($_POST['smtp_encryption'] ?? 'tls'),
            'from_email' => trim($_POST['smtp_from_email'] ?? ''),
            'from_name' => trim($_POST['smtp_from_name'] ?? ''),
        ];

        // Security
        $security = [
            'session_lifetime' => intval($_POST['session_lifetime'] ?? 30),
            'session_never_expire' => isset($_POST['session_never_expire']) ? 1 : 0
        ];

        // Hero
        $hero = [
            'title' => trim($_POST['hero_title'] ?? ''),
            'description' => trim($_POST['hero_description'] ?? ''),
            'btn1_text' => trim($_POST['hero_btn1_text'] ?? ''),
            'btn1_link' => trim($_POST['hero_btn1_link'] ?? ''),
            'btn2_text' => trim($_POST['hero_btn2_text'] ?? ''),
            'btn2_link' => trim($_POST['hero_btn2_link'] ?? ''),
        ];

        // Footer Settings
        $footer = [
            'col1_title' => trim($_POST['footer_col1_title'] ?? ''),
            'col1_text' => trim($_POST['footer_col1_text'] ?? ''),
            'col2_title' => trim($_POST['footer_col2_title'] ?? ''),
            'col3_title' => trim($_POST['footer_col3_title'] ?? ''),
        ];

        // Mentés
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['site_info', json_encode($siteInfo, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['design', json_encode($design, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['ai', json_encode($ai, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['smtp', json_encode($smtp, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['security', json_encode($security, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['hero', json_encode($hero, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['footer', json_encode($footer, JSON_UNESCAPED_UNICODE)]);

        $message = 'Beállítások mentve!';
        $messageType = 'success';
        log_activity($db, 'update', 'settings', null, 'Beállítások');
    }
}

// ---------- Dead Zone (Adatok törlése) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dead_zone_reset'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } elseif (trim($_POST['confirm_text'] ?? '') !== 'VÉGLEGES TÖRLÉS') {
        $message = 'A megerősítő szöveg nem egyezik. A törlés megszakítva.';
        $messageType = 'error';
    } else {
        try {
            // Táblák ürítése
            $tables = ['posts', 'post_revisions', 'categories', 'media', 'galleries', 'gallery_items', 'activity_log', 'menus'];
            foreach ($tables as $table) {
                try {
                    $db->exec("TRUNCATE TABLE $table");
                } catch (PDOException $e) {
                    // Ha a truncate nem megy (pl foreign key), akkor delete
                    $db->exec("DELETE FROM $table");
                }
            }

            // Képek törlése (uploads mappa)
            $uploadDir = '../uploads/';
            if (is_dir($uploadDir)) {
                $files = glob($uploadDir . '*');
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== '.htaccess') {
                        unlink($file);
                    }
                }
            }

            $message = 'A rendszer sikeresen visszaállítva (adatok törölve, beállítások megtartva).';
            $messageType = 'success';
            log_activity($db, 'reset', 'system', null, 'Dead Zone');
        } catch (Exception $e) {
            $message = 'Hiba történt a törlés során: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ---------- Beállítások betöltése ----------
$settings = [];
$rows = $db->query("SELECT * FROM settings")->fetchAll();
foreach ($rows as $row) {
    $settings[$row['setting_key']] = json_decode($row['setting_value'], true);
}

$siteName = $settings['site_info']['name'] ?? '';
$siteDesc = $settings['site_info']['description'] ?? '';
$siteLogo = $settings['site_info']['logo'] ?? '';
$footerTxt = $settings['design']['footer_text'] ?? '';
$primaryColor = $settings['design']['primary_color'] ?? '#2563eb';
$deepseekKey = $settings['ai']['deepseek_api_key'] ?? '';
$smtp = $settings['smtp'] ?? [];
$smtpHost = $smtp['host'] ?? '';
$smtpUser = $smtp['user'] ?? '';
$smtpPass = $smtp['pass'] ?? '';
$smtpPort = $smtp['port'] ?? 587;
$smtpEnc = $smtp['encryption'] ?? 'tls';
$smtpFromE = $smtp['from_email'] ?? '';
$smtpFromN = $smtp['from_name'] ?? '';

$security = $settings['security'] ?? [];
$sessionLifetime = $security['session_lifetime'] ?? 30;
$sessionNeverExpire = $security['session_never_expire'] ?? 0;

$hero = $settings['hero'] ?? [];
$footerSettings = $settings['footer'] ?? [];

admin_header('Beállítások');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>


<style>
    .settings-tabs {
        display: flex;
        gap: 5px;
        margin-bottom: 25px;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 0;
        overflow-x: auto;
    }

    .tab-btn {
        padding: 12px 20px;
        border: none;
        background: none;
        cursor: pointer;
        font-weight: 600;
        color: #64748b;
        border-bottom: 3px solid transparent;
        transition: all 0.2s;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tab-btn:hover {
        color: #1e293b;
        background: #f1f5f9;
        border-radius: 8px 8px 0 0;
    }

    .tab-btn.active {
        color: #2563eb;
        border-bottom-color: #2563eb;
    }

    .tab-content {
        display: none;
        animation: fadeIn 0.3s ease-out;
    }

    .tab-content.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .settings-card {
        background: #fff;
        padding: 24px;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        margin-bottom: 20px;
    }

    .settings-card h3 {
        margin-bottom: 20px;
        color: #1e293b;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .compact-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .help-text {
        color: #64748b;
        font-size: 0.85rem;
        margin-top: -10px;
        margin-bottom: 15px;
    }
</style>

<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>

    <div class="settings-tabs">
        <button type="button" class="tab-btn active" onclick="showTab('site')">🌐 Oldal</button>
        <button type="button" class="tab-btn" onclick="showTab('homepage')">🏠 Kezdőlap</button>
        <button type="button" class="tab-btn" onclick="showTab('design')">🎨 Megjelenés</button>
        <button type="button" class="tab-btn" onclick="showTab('ai')">🤖 AI</button>
        <button type="button" class="tab-btn" onclick="showTab('security')">🛡️ Biztonság</button>
        <button type="button" class="tab-btn" onclick="showTab('email')">📧 Email</button>
        <button type="button" class="tab-btn" style="color: #dc2626;" onclick="showTab('maintenance')">⚙️ Karbantartás</button>
    </div>

    <!-- Oldal információk -->
    <div id="tab-site" class="tab-content active">
        <div class="settings-card">
            <h3>🌐 Oldal információk</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Oldal neve</label>
                    <input type="text" name="site_name" value="<?php echo htmlspecialchars($siteName); ?>">
                </div>
                <div class="form-group">
                    <label>Oldal leírása</label>
                    <input type="text" name="site_description" value="<?php echo htmlspecialchars($siteDesc); ?>">
                </div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label>Logó</label>
                <?php if ($siteLogo): ?>
                    <div
                        style="margin-bottom: 12px; display: flex; align-items: center; gap: 15px; background: #f8fafc; padding: 10px; border-radius: 8px;">
                        <img src="../uploads/<?php echo htmlspecialchars($siteLogo); ?>"
                            style="max-height: 50px; border-radius: 4px;">
                        <label
                            style="font-weight: 500; color: #dc2626; cursor: pointer; font-size: 0.9rem; display: flex; align-items: center; gap: 5px;">
                            <input type="checkbox" name="remove_logo" value="1"> Logó törlése
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" name="logo" accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
                <p class="help-text" style="margin-top: 5px;">Max 2 MB · JPG, PNG, GIF, WebP, SVG</p>
            </div>
        </div>
    </div>

    <!-- Kezdőlap (Hero) Beállítások -->
    <div id="tab-homepage" class="tab-content">
        <div class="settings-card">
            <h3>🏠 Kezdőlap - Hero szekció</h3>
            <div class="form-group">
                <label>Főcím</label>
                <input type="text" name="hero_title"
                    value="<?php echo htmlspecialchars($hero['title'] ?? 'Üdvözöljük a LEXODUS Kft. megújult oldalán'); ?>">
            </div>
            <div class="form-group">
                <label>Leírás</label>
                <textarea name="hero_description"
                    style="height: 100px;"><?php echo htmlspecialchars($hero['description'] ?? 'Az IFS magyarországi képviselete...'); ?></textarea>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>1. Gomb felirata</label>
                    <input type="text" name="hero_btn1_text"
                        value="<?php echo htmlspecialchars($hero['btn1_text'] ?? 'Szolgáltatásaink'); ?>">
                </div>
                <div class="form-group">
                    <label>1. Gomb linkje</label>
                    <input type="text" name="hero_btn1_link"
                        value="<?php echo htmlspecialchars($hero['btn1_link'] ?? '#services'); ?>">
                </div>
                <div class="form-group">
                    <label>2. Gomb felirata</label>
                    <input type="text" name="hero_btn2_text"
                        value="<?php echo htmlspecialchars($hero['btn2_text'] ?? 'Kapcsolat'); ?>">
                </div>
                <div class="form-group">
                    <label>2. Gomb linkje</label>
                    <input type="text" name="hero_btn2_link"
                        value="<?php echo htmlspecialchars($hero['btn2_link'] ?? '/kapcsolat'); ?>">
                </div>
            </div>
        </div>

        <div class="settings-card">
            <h3>📖 Lábléc (Footer) oszlopok</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>1. Oszlop címe</label>
                    <input type="text" name="footer_col1_title"
                        value="<?php echo htmlspecialchars($footerSettings['col1_title'] ?? 'LEXODUS Kft.'); ?>">
                </div>
                <div class="form-group">
                    <label>1. Oszlop szövege</label>
                    <textarea name="footer_col1_text"
                        style="height: 100px;"><?php echo htmlspecialchars($footerSettings['col1_text'] ?? 'Társaságunk az IFS magyarországi képviselete...'); ?></textarea>
                </div>
                <div class="form-group">
                    <label>2. Oszlop címe (Navigáció)</label>
                    <input type="text" name="footer_col2_title"
                        value="<?php echo htmlspecialchars($footerSettings['col2_title'] ?? 'Navigáció'); ?>">
                </div>
                <div class="form-group">
                    <label>3. Oszlop címe (Kapcsolat)</label>
                    <input type="text" name="footer_col3_title"
                        value="<?php echo htmlspecialchars($footerSettings['col3_title'] ?? 'Kapcsolat'); ?>">
                </div>
            </div>
        </div>
    </div>
    <div id="tab-design" class="tab-content">
        <div class="settings-card">
            <h3>🎨 Megjelenés</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Footer szöveg</label>
                    <input type="text" name="footer_text" value="<?php echo htmlspecialchars($footerTxt); ?>"
                        placeholder="pl. © 2026 Cégnév">
                </div>
                <div class="form-group">
                    <label>Elsődleges szín</label>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <input type="color" name="primary_color" value="<?php echo htmlspecialchars($primaryColor); ?>"
                            style="width: 45px; height: 40px; border: 1px solid #e2e8f0; border-radius: 6px; padding: 2px; cursor: pointer;">
                        <code
                            style="background: #f1f5f9; padding: 4px 8px; border-radius: 6px;"><?php echo htmlspecialchars($primaryColor); ?></code>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Integráció -->
    <div id="tab-ai" class="tab-content">
        <div class="settings-card">
            <h3>🤖 AI Integráció (DeepSeek)</h3>
            <p class="help-text">Add meg a DeepSeek API kulcsodat a tartalom generáló funkcióhoz.</p>
            <div class="form-group">
                <label>DeepSeek API Kulcs</label>
                <input type="password" name="deepseek_api_key" value="<?php echo htmlspecialchars($deepseekKey); ?>"
                    placeholder="sk-..." style="max-width: 500px;">
            </div>
        </div>
    </div>

    <!-- Biztonsági Beállítások -->
    <div id="tab-security" class="tab-content">
        <div class="settings-card">
            <h3>🛡️ Biztonsági Beállítások</h3>
            <p class="help-text">Adminisztrációs munkamenet (session) kezelése.</p>
            <div class="form-grid">
                <div class="form-group">
                    <label>Munkamenet időtartama (perc)</label>
                    <input type="number" name="session_lifetime"
                        value="<?php echo htmlspecialchars($sessionLifetime); ?>" min="1" max="1440">
                </div>
                <div class="form-group" style="display: flex; align-items: center; padding-top: 25px;">
                    <label
                        style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 500; margin: 0;">
                        <input type="checkbox" name="session_never_expire" value="1" <?php echo $sessionNeverExpire ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                        Soha ne járjon le
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- SMTP Beállítások -->
    <div id="tab-email" class="tab-content">
        <div class="settings-card">
            <h3>📧 Email Beállítások (SMTP)</h3>
            <p class="help-text">Állítsd be az SMTP szervert az emailek küldéséhez.</p>

            <div class="form-grid" style="margin-bottom: 20px;">
                <div class="form-group">
                    <label>SMTP Szerver</label>
                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($smtpHost); ?>"
                        placeholder="mail.pelda.hu">
                </div>
                <div class="form-group">
                    <label>Port</label>
                    <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($smtpPort); ?>"
                        placeholder="587">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Felhasználónév (Email)</label>
                    <input type="text" name="smtp_user" value="<?php echo htmlspecialchars($smtpUser); ?>">
                </div>
                <div class="form-group">
                    <label>Jelszó</label>
                    <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars($smtpPass); ?>">
                </div>
                <div class="form-group">
                    <label>Titkosítás</label>
                    <select name="smtp_encryption">
                        <option value="tls" <?php echo $smtpEnc === 'tls' ? 'selected' : ''; ?>>TLS (STARTTLS)</option>
                        <option value="ssl" <?php echo $smtpEnc === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="none" <?php echo $smtpEnc === 'none' ? 'selected' : ''; ?>>Nincs</option>
                    </select>
                </div>
            </div>

            <div class="form-grid" style="margin-bottom: 25px;">
                <div class="form-group">
                    <label>Feladó Email címe</label>
                    <input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($smtpFromE); ?>">
                </div>
                <div class="form-group">
                    <label>Feladó Neve</label>
                    <input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($smtpFromN); ?>">
                </div>
            </div>

            <div style="padding-top:20px; border-top:1px solid #f1f5f9; display:flex; align-items:center; gap:15px;">
                <div style="flex:1;">
                    <input type="email" id="test-email-to" placeholder="Teszt email címzettje..." style="height:42px;">
                </div>
                <button type="button" onclick="sendTestEmail()" class="btn btn-outline"
                    style="height:42px; background: #fff;">📧 Teszt küldése</button>
            </div>
            <div id="test-result" style="margin-top:10px; font-size:0.9rem; display:none;"></div>
        </div>
    </div>    <!-- Karbantartás / Dead Zone -->
    <div id="tab-maintenance" class="tab-content">
        <div class="settings-card" style="border: 2px solid #fee2e2; background: #fffafb;">
            <h3 style="color: #991b1b;">⚠️ Dead Zone - Rendszer visszaállítása</h3>
            <p style="color: #b91c1c; font-weight: 600; margin-bottom: 10px;">Figyelem! Ez a művelet nem vonható vissza.</p>
            <p class="help-text" style="color: #7f1d1d;">
                A gombra kattintva a rendszer törli az összes:<br>
                - Bejegyzést és revíziót<br>
                - Kategóriát és menüpontot<br>
                - Feltöltött képet és média bejegyzést<br>
                - Tevékenységi naplót<br><br>
                <strong>A rendszerbeállítások és az adminisztrátori fiókok megmaradnak.</strong>
            </p>

            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #fecaca; margin-top: 15px;">
                <label style="display: block; margin-bottom: 10px; font-weight: 600; color: #1e293b;">
                    A törléshez írd be csupa nagybetűvel: <span style="color: #dc2626; user-select: none;">VÉGLEGES TÖRLÉS</span>
                </label>
                <div style="display: flex; gap: 10px;">
                    <input type="text" name="confirm_text" placeholder="Írd ide a szöveget..." autocomplete="off"
                        style="border: 1px solid #fca5a5; border-radius: 8px; padding: 10px; flex: 1;">
                    <button type="submit" name="dead_zone_reset" class="btn btn-danger"
                        onclick="return confirm('Biztosan törölni akarsz MINDEN adatot? Ezt nem lehet visszacsinálni!')">
                        🚀 Adatok végleges törlése
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabId) {
            // Rejtsünk el minden fület
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            // Vegyük le az aktív osztályt minden gombról
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            // Jelenítsük meg a kiválasztott fület
            document.getElementById('tab-' + tabId).classList.add('active');

            // Tegyük aktívvá a gombot
            event.currentTarget.classList.add('active');

            // Mentés localStorage-ba, hogy frissítés után is ezen a fülön maradjunk
            localStorage.setItem('activeSettingsTab', tabId);
        }

        // Oldal betöltésekor állítsuk vissza az utolsó fület
        window.addEventListener('DOMContentLoaded', () => {
            const lastTab = localStorage.getItem('activeSettingsTab');
            if (lastTab) {
                const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.innerText.toLowerCase().includes(lastTab) || b.getAttribute('onclick').includes(lastTab));
                if (btn) btn.click();
            }
        });

        function sendTestEmail() {
            const to = document.getElementById('test-email-to').value.trim();
            const resultDiv = document.getElementById('test-result');

            if (!to) {
                alert('Kérlek adj meg egy email címet a teszteléshez!');
                return;
            }

            resultDiv.style.display = 'block';
            resultDiv.style.color = '#64748b';
            resultDiv.innerHTML = '⏳ Küldés folyamatban...';

            const formData = new FormData();
            formData.append('to', to);
            formData.append('csrf_token', '<?php echo csrf_token(); ?>');

            fetch('ajax_test_email.php', {
                method: 'POST',
                body: formData
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.style.color = '#16a34a';
                        resultDiv.innerHTML = '✅ ' + data.message;
                    } else {
                        resultDiv.style.color = '#dc2626';
                        resultDiv.innerHTML = '❌ Hiba: ' + (data.error || 'Ismeretlen hiba történt.');
                    }
                })
                .catch(err => {
                    resultDiv.style.color = '#dc2626';
                    resultDiv.innerHTML = '❌ Hálózati hiba történt.';
                    console.error(err);
                });
        }
    </script>

    <button type="submit" name="save" class="btn btn-success" style="font-size: 1rem; padding: 12px 30px;">💾
        Beállítások mentése</button>
</form>

<?php
admin_footer();
