<?php
// c:\XAMPP\htdocs\cms\core\Email.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Email
{
    private static function initMailer($db)
    {
        // Load settings
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'smtp'");
        $stmt->execute();
        $smtp = json_decode($stmt->fetchColumn() ?: '{}', true);

        if (empty($smtp['host'])) {
            throw new Exception("Az SMTP nincs beállítva az adatbázisban.");
        }

        // --- PHPMailer LIBRARY INCLUSION ---
        // Megpróbáljuk betölteni a PHPMailer-t. 
        // Ha van vendor/autoload.php, használjuk azt, egyébként manuális include.
        $baseDir = dirname(__DIR__);
        if (file_exists($baseDir . '/vendor/autoload.php')) {
            require_once $baseDir . '/vendor/autoload.php';
        } else {
            // Manuális include kísérlet
            $paths = [
                $baseDir . '/vendor/phpmailer/phpmailer/src/',
                $baseDir . '/core/PHPMailer/src/',
                $baseDir . '/admin/PHPMailer/src/',
            ];

            $loaded = false;
            foreach ($paths as $path) {
                if (file_exists($path . 'PHPMailer.php')) {
                    require_once $path . 'Exception.php';
                    require_once $path . 'PHPMailer.php';
                    require_once $path . 'SMTP.php';
                    $loaded = true;
                    break;
                }
            }

            if (!$loaded) {
                throw new Exception("PHPMailer könyvtár nem található! Kérlek töltsd fel a core/PHPMailer/src/ mappába az Exception.php, PHPMailer.php és SMTP.php fájlokat.");
            }
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['user'];
        $mail->Password = $smtp['pass'];
        $mail->Port = $smtp['port'];

        if ($smtp['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtp['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        }

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->CharSet = 'UTF-8';

        return $mail;
    }

    public static function send($db, $to, $subject, $body, $altBody = '')
    {
        try {
            $mail = self::initMailer($db);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $altBody ?: strip_tags($body);

            $mail->send();
            return ['success' => true];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
