<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$postId = $_GET['post_id'] ?? null;
$revisionId = $_GET['revision_id'] ?? null;

if (!$postId || !is_numeric($postId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid post ID']);
    exit;
}

try {
    if ($revisionId && is_numeric($revisionId)) {
        // Egy konkrét verzió lekérése
        $stmt = $db->prepare('
            SELECT pr.*, u.username as admin_name 
            FROM post_revisions pr 
            LEFT JOIN users u ON pr.admin_id = u.id 
            WHERE pr.id = ? AND pr.post_id = ?
        ');
        $stmt->execute([$revisionId, $postId]);
        $revision = $stmt->fetch();

        if ($revision) {
            echo json_encode(['success' => true, 'revision' => $revision]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Revision not found']);
        }
    } else {
        // Összes verzió listázása a bejegyzéshez
        $stmt = $db->prepare('
            SELECT pr.id, pr.post_id, pr.admin_id, pr.title, pr.created_at, u.username as admin_name 
            FROM post_revisions pr 
            LEFT JOIN users u ON pr.admin_id = u.id 
            WHERE pr.post_id = ? 
            ORDER BY pr.created_at DESC
        ');
        $stmt->execute([$postId]);
        $revisions = $stmt->fetchAll();

        echo json_encode(['success' => true, 'revisions' => $revisions]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
