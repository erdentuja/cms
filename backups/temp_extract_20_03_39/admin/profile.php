<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$message = '';
$messageType = '';

// ---------- Jelszóváltoztatás ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pass'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        // Jelenlegi jelszó ellenőrzése
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['admin_id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($currentPass, $user['password_hash'])) {
            $message = 'A jelenlegi jelszó hibás.';
            $messageType = 'error';
        } elseif (strlen($newPass) < 6) {
            $message = 'Az új jelszó legalább 6 karakter legyen.';
            $messageType = 'error';
        } elseif ($newPass !== $confirmPass) {
            $message = 'A két új jelszó nem egyezik.';
            $messageType = 'error';
        } else {
            $hash = password_hash($newPass, PASSWORD_ARGON2I);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$hash, $_SESSION['admin_id']]);
            $message = 'Jelszó sikeresen megváltoztatva!';
            $messageType = 'success';
        }
    }
}

// ---------- Profil (Név & Email) változtatás ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $newName = trim($_POST['new_username'] ?? '');
        $newEmail = trim($_POST['new_email'] ?? '');

        if ($newName === '') {
            $message = 'A felhasználónév nem lehet üres.';
            $messageType = 'error';
        } elseif (strlen($newName) < 3) {
            $message = 'A felhasználónév legalább 3 karakter legyen.';
            $messageType = 'error';
        } elseif ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Érvénytelen email formátum.';
            $messageType = 'error';
        } else {
            // Ellenőrzés: foglalt-e a név?
            $check = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $check->execute([$newName, $_SESSION['admin_id']]);
            if ($check->fetch()) {
                $message = 'Ez a felhasználónév már foglalt.';
                $messageType = 'error';
            } else {
                try {
                    $db->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?')
                        ->execute([$newName, $newEmail, $_SESSION['admin_id']]);
                    $_SESSION['admin_user'] = $newName;
                    $message = 'Profil adatok sikeresen frissítve!';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = 'DB hiba: ' . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
}

// Aktuális user
try {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $currentUser = $stmt->fetch();
} catch (PDOException $e) {
    // Ha az email oszlop még nincs
    $stmt = $db->prepare('SELECT id, username, NULL as email, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $currentUser = $stmt->fetch();
}

admin_header('Profil');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<!-- Felhasználó info -->
<div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px;">
    <h3 style="margin-bottom: 15px; color: #1e293b;">👤 Profil adatok módosítása</h3>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div style="max-width: 400px;">
            <div class="form-group">
                <label>Felhasználónév</label>
                <input type="text" name="new_username" value="<?php echo htmlspecialchars($currentUser['username']); ?>"
                    minlength="3" required style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Email cím</label>
                <input type="email" name="new_email"
                    value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" style="width: 100%;">
            </div>
            <button type="submit" name="save_profile" class="btn">💾 Mentés</button>
        </div>
    </form>
</div>

<!-- Jelszó változtatás -->
<div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb;">
    <h3 style="margin-bottom: 15px; color: #1e293b;">🔒 Jelszó változtatás</h3>
    <form method="post">
        <?php echo csrf_field(); ?>
        <div style="max-width: 400px;">
            <div class="form-group">
                <label>Jelenlegi jelszó</label>
                <input type="password" name="current_password" required style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Új jelszó</label>
                <input type="password" name="new_password" minlength="6" required style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Új jelszó megerősítése</label>
                <input type="password" name="confirm_password" minlength="6" required style="width: 100%;">
            </div>
            <button type="submit" name="change_pass" class="btn btn-success">🔒 Jelszó mentése</button>
        </div>
    </form>
</div>

<?php
admin_footer();
