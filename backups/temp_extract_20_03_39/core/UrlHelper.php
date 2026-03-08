<?php
class UrlHelper {
    public static function link($path = '') {
        return BASE_URL . '/' . ltrim($path, '/');
    }
    public static function asset($path) {
        return BASE_URL . '/uploads/' . ltrim($path, '/');
    }
    public static function themeAsset($path) {
        return BASE_URL . '/theme/' . ltrim($path, '/');
    }
    public static function sanitizeSlug($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(['á','é','í','ó','ö','ő','ú','ü','ű'], ['a','e','i','o','o','o','u','u','u'], $text);
        $text = preg_replace('/[^a-z0-9\-]/', '-', $text);
        return trim(preg_replace('/-+/', '-', $text), '-');
    }

    /**
     * Egyedi slug generálása, ami nincs még használatban
     * @param PDO $db Adatbázis kapcsolat
     * @param string $baseSlug Alap slug
     * @param int|null $excludeId Kizárandó post ID (szerkesztésnél)
     * @return string Egyedi slug
     */
    public static function generateUniqueSlug($db, $baseSlug, $excludeId = null) {
        $slug = self::sanitizeSlug($baseSlug);
        $originalSlug = $slug;
        $counter = 1;

        // Ellenőrizzük, hogy létezik-e már
        while (true) {
            if ($excludeId) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE slug = ? AND id != ?');
                $stmt->execute([$slug, $excludeId]);
            } else {
                $stmt = $db->prepare('SELECT COUNT(*) FROM posts WHERE slug = ?');
                $stmt->execute([$slug]);
            }

            $count = $stmt->fetchColumn();

            // Ha nincs ilyen slug, vagy csak a saját bejegyzésünkben van (szerkesztésnél)
            if ($count == 0) {
                return $slug;
            }

            // Ha már létezik, adjunk hozzá egy számot
            $counter++;
            $slug = $originalSlug . '-' . $counter;

            // Végtelen ciklus elleni védelem
            if ($counter > 1000) {
                // Ha 1000 után sem találtunk egyedit, adjunk random stringet
                $slug = $originalSlug . '-' . uniqid();
                return $slug;
            }
        }
    }
} ?>