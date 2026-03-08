<?php
/**
 * Média API – Média könyvtár lekérdezése JSON-ban
 * TinyMCE média böngésző használja.
 */
require_once '../config.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$items = $db->query("SELECT * FROM media ORDER BY id DESC")->fetchAll();

$result = [];
foreach ($items as $item) {
    $ext = strtolower(pathinfo($item['filepath'], PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
    $result[] = [
        'id' => $item['id'],
        'filename' => $item['filename'],
        'filepath' => $item['filepath'],
        'url' => '../uploads/' . $item['filepath'],
        'is_image' => $isImage,
    ];
}

echo json_encode($result);
