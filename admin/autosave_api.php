<?php
require_once '../config.php';

header('Content-Type: application/json');

// Csak bejelentkezett adminoknak
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$postId = $data['post_id'] ?? null;
$title = trim($data['title'] ?? '');
$content = $data['content'] ?? '';
$baseSlug = trim($data['slug'] ?? '');
$postType = $data['post_type'] ?? 'page';
$status = $data['status'] ?? 'draft';
$metaTitle = trim($data['meta_title'] ?? '');
$metaDesc = trim($data['meta_description'] ?? '');
$featured = trim($data['featured_image'] ?? '');
$publishAt = !empty($data['publish_at']) ? $data['publish_at'] : null;

try {
    // Ha nincs cím, ne mentsünk (kötelező mező)
    if (empty($title)) {
        echo json_encode(['success' => true, 'message' => 'Skipped (no title)', 'action' => 'skipped']);
        exit;
    }

    // Egyedi slug generálás
    require_once '../core/UrlHelper.php';
    if (empty($baseSlug)) {
        $baseSlug = $title;
    }
    $slug = UrlHelper::generateUniqueSlug($db, $baseSlug, $postId ?: null);

    if ($postId && is_numeric($postId)) {
        // Meglévő bejegyzés frissítése (autosave nem változtatja a státuszt publikáltra!)
        // Ha már publikált, ne változtassuk vissza draft-ra
        $stmt = $db->prepare('SELECT status, title, content FROM posts WHERE id = ?');
        $stmt->execute([$postId]);
        $currentPost = $stmt->fetch();

        if ($currentPost) {
            // Csak akkor írjuk felül a státuszt, ha jelenleg draft vagy scheduled
            $saveStatus = ($currentPost['status'] === 'draft' || $currentPost['status'] === 'scheduled')
                ? $status
                : $currentPost['status'];

            $db->prepare('UPDATE posts SET title=?, slug=?, content=?, status=?, post_type=?, meta_title=?, meta_description=?, featured_image=?, publish_at=? WHERE id=?')
                ->execute([$title, $slug, $content, $saveStatus, $postType, $metaTitle, $metaDesc, $featured ?: null, $publishAt, $postId]);

            // Revízió mentése, ha változott a cím vagy a tartalom
            if ($currentPost['title'] !== $title || $currentPost['content'] !== $content) {
                $db->prepare('INSERT INTO post_revisions (post_id, admin_id, title, content) VALUES (?, ?, ?, ?)')
                    ->execute([$postId, $_SESSION['admin_id'], $title, $content]);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Auto-saved',
                'action' => 'updated',
                'post_id' => $postId
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Post not found']);
        }
    } else {
        // Új bejegyzés létrehozása (vázlatként)
        $stmt = $db->prepare('INSERT INTO posts (title, slug, content, status, post_type, meta_title, meta_description, featured_image, publish_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$title, $slug, $content, 'draft', $postType, $metaTitle, $metaDesc, $featured ?: null, $publishAt]);
        $newId = $db->lastInsertId();

        // Revízió mentése
        $db->prepare('INSERT INTO post_revisions (post_id, admin_id, title, content) VALUES (?, ?, ?, ?)')
            ->execute([$newId, $_SESSION['admin_id'], $title, $content]);

        echo json_encode([
            'success' => true,
            'message' => 'Auto-saved as draft',
            'action' => 'created',
            'post_id' => $newId
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
