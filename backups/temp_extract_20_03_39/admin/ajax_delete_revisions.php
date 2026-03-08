<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Csak adminok vagy szuper adminok törölhetnek történetet
$stmt = $db->prepare('SELECT role FROM users WHERE id = ?');
$stmt->execute([$_SESSION['admin_id']]);
$user = $stmt->fetch();

if (!$user || ($user['role'] !== 'super_admin' && $user['role'] !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Nincs jogosultságod a történet törléséhez.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$postId = $input['post_id'] ?? null;

if (!$postId || !is_numeric($postId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Érvénytelen bejegyzés ID.']);
    exit;
}

try {
    $db->prepare('DELETE FROM post_revisions WHERE post_id = ?')->execute([$postId]);

    // Naplózzuk a törlést
    require_once '../core/functions.php';
    log_activity($db, 'delete_history', 'post', (int) $postId, 'Verziótörténet törölve');

    echo json_encode(['success' => true, 'message' => 'A verziótörténet sikeresen törölve.']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Adatbázis hiba: ' . $e->getMessage()]);
}
