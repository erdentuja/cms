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

        // Mentés
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['site_info', json_encode($siteInfo, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['design', json_encode($design, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['ai', json_encode($ai, JSON_UNESCAPED_UNICODE)]);
        $stmt->execute(['smtp', json_encode($smtp, JSON_UNESCAPED_UNICODE)]);

        $message = 'Beállítások mentve!';
        $messageType = 'success';
        log_activity($db, 'update', 'settings', null, 'Beállítások');
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

admin_header('Beállítások');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?php echo csrf_field(); ?>

    <!-- Oldal információk -->
    <div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
        <h3 style="margin-bottom: 15px; color: #1e293b;">🌐 Oldal információk</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label>Oldal neve</label>
                <input type="text" name="site_name" value="<?php echo htmlspecialchars($siteName); ?>"
                    style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Oldal leírása</label>
                <input type="text" name="site_description" value="<?php echo htmlspecialchars($siteDesc); ?>"
                    style="width: 100%;">
            </div>
        </div>

        <div class="form-group" style="margin-top: 15px;">
            <label>Logó</label>
            <?php if ($siteLogo): ?>
                <div style="margin-bottom: 10px; display: flex; align-items: center; gap: 15px;">
                    <img src="../uploads/<?php echo htmlspecialchars($siteLogo); ?>"
                        style="max-height: 60px; border-radius: 6px; border: 1px solid #e5e7eb;">
                    <label style="font-weight: normal; color: #dc2626; cursor: pointer;">
                        <input type="checkbox" name="remove_logo" value="1"> Logó törlése
                    </label>
                </div>
            <?php endif; ?>
            <input type="file" name="logo" accept=".jpg,.jpeg,.png,.gif,.webp,.svg">
            <p style="margin-top: 5px; color: #94a3b8; font-size: 0.85rem;">Max 2 MB · JPG, PNG, GIF, WebP, SVG</p>
        </div>
    </div>

    <!-- Megjelenés -->
    <div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
        <h3 style="margin-bottom: 15px; color: #1e293b;">🎨 Megjelenés</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label>Footer szöveg</label>
                <input type="text" name="footer_text" value="<?php echo htmlspecialchars($footerTxt); ?>"
                    placeholder="pl. © 2026 Cégnév" style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Elsődleges szín</label>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="color" name="primary_color" value="<?php echo htmlspecialchars($primaryColor); ?>"
                        style="width: 50px; height: 38px; padding: 2px; cursor: pointer;">
                    <code><?php echo htmlspecialchars($primaryColor); ?></code>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Integráció -->
    <div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
        <h3 style="margin-bottom: 5px; color: #1e293b;">🤖 AI Integráció (DeepSeek)</h3>
        <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px;">Add meg a DeepSeek API kulcsodat a tartalom
            generáló funkcióhoz.</p>
        <div class="form-group">
            <label>DeepSeek API Kulcs</label>
            <input type="password" name="deepseek_api_key" value="<?php echo htmlspecialchars($deepseekKey); ?>"
                placeholder="sk-..." style="width: 100%; max-width: 500px;">
        </div>
    </div>

    <!-- SMTP Beállítások -->
    <div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
        <h3 style="margin-bottom: 5px; color: #1e293b;">📧 Email Beállítások (SMTP)</h3>
        <p style="color: #64748b; font-size: 0.85rem; margin-bottom: 15px;">Állítsd be az SMTP szervert az emailek
            küldéséhez.</p>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom:15px;">
            <div class="form-group">
                <label>SMTP Szerver</label>
                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($smtpHost); ?>"
                    placeholder="mail.pelda.hu" style="width:100%;">
            </div>
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($smtpPort); ?>"
                    placeholder="587" style="width:100%;">
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom:15px;">
            <div class="form-group">
                <label>Felhasználónév (Email)</label>
                <input type="text" name="smtp_user" value="<?php echo htmlspecialchars($smtpUser); ?>"
                    style="width:100%;">
            </div>
            <div class="form-group">
                <label>Jelszó</label>
                <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars($smtpPass); ?>"
                    style="width:100%;">
            </div>
            <div class="form-group">
                <label>Titkosítás</label>
                <select name="smtp_encryption"
                    style="width:100%; height:38px; border:1px solid #d1d5db; border-radius:6px;">
                    <option value="tls" <?php echo $smtpEnc === 'tls' ? 'selected' : ''; ?>>TLS (STARTTLS)</option>
                    <option value="ssl" <?php echo $smtpEnc === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    <option value="none" <?php echo $smtpEnc === 'none' ? 'selected' : ''; ?>>Nincs</option>
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom:20px;">
            <div class="form-group">
                <label>Feladó Email címe</label>
                <input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars($smtpFromE); ?>"
                    style="width:100%;">
            </div>
            <div class="form-group">
                <label>Feladó Neve</label>
                <input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars($smtpFromN); ?>"
                    style="width:100%;">
            </div>
        </div>

        <div style="padding-top:15px; border-top:1px solid #f1f5f9; display:flex; align-items:center; gap:15px;">
            <div style="flex:1;">
                <input type="email" id="test-email-to" placeholder="Teszt email címzettje..."
                    style="width:100%; height:42px; border:1px solid #d1d5db; border-radius:6px; padding:0 10px;">
            </div>
            <button type="button" onclick="sendTestEmail()" class="btn"
                style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; height:42px;">📧 Teszt
                küldése</button>
        </div>
        <div id="test-result" style="margin-top:10px; font-size:0.9rem; display:none;"></div>
    </div>

    <script>
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
