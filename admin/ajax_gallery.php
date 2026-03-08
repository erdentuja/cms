<?php
require_once '../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'add_to_gallery') {
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $galleryId = $_POST['gallery_id'] ?? '';
    $newTitle = $_POST['new_title'] ?? '';
    $mediaIds = json_decode($_POST['media_ids'] ?? '[]', true);

    if (empty($mediaIds)) {
        echo json_encode(['success' => false, 'error' => 'No media selected']);
        exit;
    }

    try {
        $db->beginTransaction();

        // 1. Get or Create Gallery
        if ($galleryId === 'new') {
            if (empty($newTitle)) {
                echo json_encode(['success' => false, 'error' => 'Gallery title is required']);
                exit;
            }
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $newTitle)));
            $stmt = $db->prepare("INSERT INTO galleries (title, slug) VALUES (?, ?)");
            $stmt->execute([$newTitle, $slug]);
            $galleryId = $db->lastInsertId();
            log_activity($db, 'create', 'gallery', (int) $galleryId, $newTitle);
        }

        // 2. Add Media to Gallery
        // Find the current max sort_order
        $stmt = $db->prepare("SELECT MAX(sort_order) FROM gallery_items WHERE gallery_id = ?");
        $stmt->execute([$galleryId]);
        $maxOrder = (int) $stmt->fetchColumn();

        $stmt = $db->prepare("INSERT INTO gallery_items (gallery_id, media_id, sort_order) VALUES (?, ?, ?)");
        foreach ($mediaIds as $mId) {
            // Check if already in gallery (optional, preventing duplicates)
            $check = $db->prepare("SELECT COUNT(*) FROM gallery_items WHERE gallery_id = ? AND media_id = ?");
            $check->execute([$galleryId, $mId]);
            if ($check->fetchColumn() == 0) {
                $maxOrder++;
                $stmt->execute([$galleryId, $mId, $maxOrder]);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'gallery_id' => $galleryId]);

    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'update_order') {
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $galleryId = $_POST['gallery_id'] ?? '';
    $order = json_decode($_POST['order'] ?? '[]', true); // Array of media IDs in order

    if (empty($galleryId) || empty($order)) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    try {
        $db->beginTransaction();
        $stmt = $db->prepare("UPDATE gallery_items SET sort_order = ? WHERE gallery_id = ? AND media_id = ?");
        foreach ($order as $index => $mId) {
            $stmt->execute([$index + 1, $galleryId, $mId]);
        }
        $db->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} elseif ($action === 'remove_from_gallery') {
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }
    $galleryId = $_POST['gallery_id'] ?? '';
    $mediaId = $_POST['media_id'] ?? '';

    if (empty($galleryId) || empty($mediaId)) {
        echo json_encode(['success' => false, 'error' => 'Missing data']);
        exit;
    }

    try {
        $stmt = $db->prepare("DELETE FROM gallery_items WHERE gallery_id = ? AND media_id = ?");
        $stmt->execute([$galleryId, $mediaId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
