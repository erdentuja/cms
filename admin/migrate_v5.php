<?php
require_once '../config.php';

// Ellenőrizzük, hogy van-e jogunk futtatni (pl. bejelentkezett szuper admin)
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['admin_id'])) {
        die('Nincs bejelentkezve.');
    }

    $stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['role'] !== 'super_admin') {
        die('Nincs jogosultságod futtatni ezt a migrációt.');
    }
}

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS post_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            admin_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    echo "Sikeres migráció! A `post_revisions` tábla létrejött.<br><br>";
    echo "<a href='index.php'>Vissza a vezérlőpultra</a>";

} catch (PDOException $e) {
    die("Hiba történt a migráció során: " . $e->getMessage());
}
