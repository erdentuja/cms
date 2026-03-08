<?php
require_once '../config.php';

try {
    // 1. Galleries table
    $db->exec("CREATE TABLE IF NOT EXISTS galleries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 2. Gallery Items table (linking media to galleries with sort order)
    $db->exec("CREATE TABLE IF NOT EXISTS gallery_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gallery_id INT NOT NULL,
        media_id INT NOT NULL,
        sort_order INT DEFAULT 0,
        FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
        FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    echo "✅ Galéria táblák sikeresen létrehozva.<br>";

} catch (PDOException $e) {
    echo "❌ Hiba történt a migráció során: " . $e->getMessage() . "<br>";
}
