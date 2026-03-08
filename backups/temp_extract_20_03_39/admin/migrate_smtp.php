<?php
require_once '../config.php';

try {
    // Ellenőrizzük, hogy létezik-e az SMTP beállítás kulcs
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'smtp'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $smtp_data = [
            'host' => 'mail.privateemail.com',
            'user' => 'info@qfxdesign.hu',
            'pass' => '680817-Aa',
            'port' => 587,
            'encryption' => 'tls', // starttls in test_phpmailer translates to tls/ssl
            'from_email' => 'info@qfxdesign.hu',
            'from_name' => 'CMS Rendszer'
        ];
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute(['smtp', json_encode($smtp_data)]);
        echo "✅ SMTP beállítások hozzáadva a settings táblához.<br>";
    } else {
        echo "✅ Az SMTP beállítások már léteznek.<br>";
    }

} catch (PDOException $e) {
    echo "❌ Hiba történt a migráció során: " . $e->getMessage() . "<br>";
}
