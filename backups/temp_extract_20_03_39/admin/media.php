<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once '../core/ImageProcessor.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

// ---------- Engedélyezett fájltípusok ----------
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf'];
$allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf'];
$maxSize = 5 * 1024 * 1024; // 5 MB

$message = '';
$messageType = '';

// ---------- Feltöltés (hagyományos form) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $file = $_FILES['file'];
        $result = processUpload($file, $db);

        $message = $result['message'];
        $messageType = $result['type'];
    }
}

// ---------- Alt text és Caption frissítés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_media_id'])) {
    if (csrf_verify()) {
        $mediaId = $_POST['update_media_id'];
        $altText = trim($_POST['alt_text'] ?? '');
        $caption = trim($_POST['caption'] ?? '');

        $stmt = $db->prepare('UPDATE media SET alt_text = ?, caption = ? WHERE id = ?');
        $stmt->execute([$altText, $caption, $mediaId]);

        $message = 'Média információk frissítve!';
        $messageType = 'success';
    }
}

// ---------- Tömeges Törlés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_ids'])) {
    if (csrf_verify()) {
        $ids = $_POST['bulk_delete_ids']; // Array of IDs
        if (is_array($ids)) {
            $count = 0;
            foreach ($ids as $id) {
                if (deleteMediaItem($db, $id)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $message = "$count fájl sikeresen törölve.";
                $messageType = 'success';
            }
        }
    }
}

// ---------- Törlés (Egyenkénti) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        if (deleteMediaItem($db, $_POST['delete_id'])) {
            $message = 'Fájl törölve.';
            $messageType = 'success';
        }
    }
}

/**
 * Média elem és fájljai törlése
 */
function deleteMediaItem($db, $id)
{
    $stmt = $db->prepare('SELECT * FROM media WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();
    if ($item) {
        $filepath = '../uploads/' . $item['filepath'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }

        if (!empty($item['sizes'])) {
            $sizes = json_decode($item['sizes'], true);
            if ($sizes && is_array($sizes)) {
                $dir = dirname($filepath);
                foreach ($sizes as $sizeData) {
                    if (is_array($sizeData)) {
                        foreach ($sizeData as $sizePath) {
                            $fullPath = $dir . '/' . $sizePath;
                            if (file_exists($fullPath)) {
                                unlink($fullPath);
                            }
                        }
                    }
                }
            }
        }

        $db->prepare('DELETE FROM media WHERE id = ?')->execute([(int) $id]);
        log_activity($db, 'delete', 'media', (int) $id, $item['filename']);
        return true;
    }
    return false;
}

/**
 * Feltöltés feldolgozása (közös függvény)
 */
function processUpload($file, $db)
{
    global $allowedExts, $allowedTypes, $maxSize;

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $mime = mime_content_type($file['tmp_name']);
    $fileSize = $file['size'];

    if (!in_array($ext, $allowedExts)) {
        return ['message' => 'Nem engedélyezett fájltípus: .' . htmlspecialchars($ext), 'type' => 'error'];
    }

    if (!in_array($mime, $allowedTypes)) {
        return ['message' => 'Nem engedélyezett MIME típus: ' . htmlspecialchars($mime), 'type' => 'error'];
    }

    if ($fileSize > $maxSize) {
        return ['message' => 'A fájl túl nagy! Maximum: 5 MB.', 'type' => 'error'];
    }

    // Feltöltési könyvtár
    $sub = date('Y/m');
    $dir = '../uploads/' . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Egyedi fájlnév generálás
    $baseName = time() . '_' . bin2hex(random_bytes(4));
    $fileName = $baseName . '.' . $ext;
    $filePath = $dir . '/' . $fileName;

    // Fájl feltöltése
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['message' => 'Feltöltési hiba történt.', 'type' => 'error'];
    }

    // Kép esetén: átméretezés és WebP generálás
    $width = null;
    $height = null;
    $sizes = null;

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $imageInfo = ImageProcessor::getImageInfo($filePath);
        if ($imageInfo) {
            $width = $imageInfo['width'];
            $height = $imageInfo['height'];

            // Különböző méretek generálása
            $generatedSizes = ImageProcessor::generateSizes($filePath, $dir, $baseName);

            // WebP verziók generálása
            $webpVersions = ImageProcessor::generateWebPVersions($filePath, $generatedSizes, $dir, $baseName);

            // Összefűzzük a méreteket és WebP verziókat
            $generatedSizes['webp'] = $webpVersions;
            $sizes = json_encode($generatedSizes);
        }
    }

    // Adatbázisba mentés
    $stmt = $db->prepare('INSERT INTO media (filename, filepath, file_size, mime_type, width, height, sizes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $file['name'],
        $sub . '/' . $fileName,
        $fileSize,
        $mime,
        $width,
        $height,
        $sizes
    ]);

    $mediaId = $db->lastInsertId();
    log_activity($db, 'create', 'media', (int) $mediaId, $file['name']);

    return ['message' => 'Fájl sikeresen feltöltve és feldolgozva!', 'type' => 'success'];
}

$items = $db->query('SELECT * FROM media ORDER BY id DESC')->fetchAll();

admin_header('Média kezelés');
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<!-- Drag & Drop feltöltés -->
<div id="drop-zone"
    style="border: 2px dashed #cbd5e1; border-radius: 12px; padding: 40px; text-align: center; margin-bottom: 25px; background: #f8fafc; cursor: pointer; transition: all 0.3s;">
    <div style="font-size: 3rem; margin-bottom: 10px;">📤</div>
    <h3 style="margin-bottom: 10px; color: #1e293b;">Húzd ide a fájlokat vagy kattints a tallózáshoz</h3>
    <p style="color: #64748b; font-size: 0.9rem;">Engedélyezett: JPG, PNG, GIF, WebP, SVG, PDF – Max: 5 MB</p>
    <input type="file" id="file-input" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf" style="display: none;">
</div>

<!-- Feltöltési progress -->
<div id="upload-progress" style="display: none; margin-bottom: 20px;">
    <div style="background: #e0e7ff; border-radius: 8px; padding: 15px;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span id="upload-status">Feltöltés...</span>
            <span id="upload-percent">0%</span>
        </div>
        <div style="background: #cbd5e1; height: 8px; border-radius: 4px; overflow: hidden;">
            <div id="progress-bar" style="background: #6366f1; height: 100%; width: 0%; transition: width 0.3s;"></div>
        </div>
    </div>
</div>

<!-- Keresés és Tömeges műveletek -->
<div
    style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #e5e7eb;">
    <div style="flex: 1; min-width: 250px; position: relative;">
        <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8;">🔍</span>
        <input type="text" id="media-search" placeholder="Keresés név alapján..."
            style="width: 100%; padding: 10px 10px 10px 40px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 0.95rem;">
    </div>

    <div id="bulk-actions" style="display: flex; align-items: center; gap: 15px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #475569; font-weight: 600;">
            <input type="checkbox" id="select-all" style="width: 18px; height: 18px;"> Összes kijelölése
        </label>
        <button type="button" id="bulk-delete-btn" class="btn btn-danger" style="display: none; padding: 10px 20px;"
            onclick="bulkDeleteSelected()">
            🗑️ Kijelöltek törlése (<span id="selected-count">0</span>)
        </button>
        <button type="button" id="bulk-gallery-btn" class="btn btn-success" style="display: none; padding: 10px 20px;"
            onclick="openGalleryModal()">
            🖼️ Galériához adás
        </button>
    </div>
</div>

<!-- Média lista -->
<?php if (empty($items)): ?>
    <p style="color: #94a3b8;">Nincs még feltöltött média.</p>
<?php else: ?>
    <div id="media-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
        <?php foreach ($items as $i): ?>
            <div class="media-item" data-filename="<?php echo htmlspecialchars(strtolower($i['filename'])); ?>"
                style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #fff; position: relative; transition: all 0.2s;">

                <!-- Checkbox -->
                <div style="position: absolute; top: 10px; left: 10px; z-index: 2;">
                    <input type="checkbox" class="media-checkbox" value="<?php echo $i['id']; ?>"
                        style="width: 20px; height: 20px; cursor: pointer; box-shadow: 0 0 5px rgba(0,0,0,0.2);">
                </div>
                <?php
                $ext = strtolower(pathinfo($i['filepath'], PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
                ?>

                <?php if ($isImage): ?>
                    <div style="position: relative; cursor: pointer;" onclick="toggleMediaSelection(<?php echo $i['id']; ?>)">
                        <img src="../uploads/<?php echo htmlspecialchars($i['filepath']); ?>"
                            style="width: 100%; height: 180px; object-fit: cover; display: block;"
                            alt="<?php echo htmlspecialchars($i['alt_text'] ?: $i['filename']); ?>">
                        <?php if (!empty($i['width']) && !empty($i['height'])): ?>
                            <div
                                style="position: absolute; bottom: 5px; right: 5px; background: rgba(0,0,0,0.7); color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 0.7rem;">
                                <?php echo $i['width']; ?> × <?php echo $i['height']; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="width: 100%; height: 180px; display: flex; align-items: center; justify-content: center; background: #f1f5f9; font-size: 3rem; cursor: pointer;"
                        onclick="toggleMediaSelection(<?php echo $i['id']; ?>)">
                        📄
                    </div>
                <?php endif; ?>

                <div style="padding: 12px;">
                    <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 600; margin-bottom: 4px;"
                        title="<?php echo htmlspecialchars($i['filename']); ?>">
                        <?php echo htmlspecialchars($i['filename']); ?>
                    </div>

                    <?php if (!empty($i['file_size'])): ?>
                        <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 8px;">
                            <?php echo number_format($i['file_size'] / 1024, 1); ?> KB
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 5px;">
                        <button type="button" class="btn"
                            onclick="openMediaDetailModal(<?php echo htmlspecialchars(json_encode($i)); ?>)"
                            style="flex: 1; padding: 4px 8px; font-size: 0.75rem;">
                            ✏️ Részletek
                        </button>
                        <form method="post" style="display: inline; flex: 1;" onsubmit="return confirm('Biztosan törlöd?');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo $i['id']; ?>">
                            <button type="submit" class="btn btn-danger"
                                style="width: 100%; padding: 4px 8px; font-size: 0.75rem;">🗑️</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Média részletek modal -->
<div id="media-detail-modal"
    style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
    <div
        style="background: #fff; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <div
            style="padding: 15px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; z-index: 1;">
            <h3 style="margin: 0;">🖼️ Média részletek</h3>
            <button onclick="closeMediaDetailModal()"
                style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b;">✕</button>
        </div>
        <div id="media-detail-content" style="padding: 20px;">
            <!-- Dinamikusan töltődik JavaScript-tel -->
        </div>
    </div>
</div>

<!-- Galéria modal -->
<div id="gallery-modal"
    style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: #fff; border-radius: 12px; width: 90%; max-width: 450px; padding: 25px;">
        <h3 style="margin-top: 0; margin-bottom: 20px;">🖼️ Hozzáadás galériához</h3>

        <?php
        $galleries = $db->query("SELECT * FROM galleries ORDER BY title ASC")->fetchAll();
        ?>

        <div class="form-group" style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Válassz galériát:</label>
            <select id="gallery-select"
                style="width: 100%; height: 40px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 10px;"
                onchange="toggleNewGalleryField()">
                <option value="new">-- Új galéria létrehozása --</option>
                <?php foreach ($galleries as $g): ?>
                    <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['title']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="new-gallery-field" class="form-group" style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: 600;">Új galéria neve:</label>
            <input type="text" id="new-gallery-title" placeholder="Pl. Nyári képek"
                style="width: 100%; height: 40px; border: 1px solid #d1d5db; border-radius: 8px; padding: 0 10px;">
        </div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button onclick="closeGalleryModal()" class="btn"
                style="background: #f1f5f9; color: #475569;">Mégse</button>
            <button onclick="addToGallery()" class="btn btn-success">✅ Mentés</button>
        </div>
    </div>
</div>

<script>
    function toggleNewGalleryField() {
        const select = document.getElementById('gallery-select');
        const field = document.getElementById('new-gallery-field');
        field.style.display = select.value === 'new' ? 'block' : 'none';
    }

    function openGalleryModal() {
        document.getElementById('gallery-modal').style.display = 'flex';
        toggleNewGalleryField();
    }

    function closeGalleryModal() {
        document.getElementById('gallery-modal').style.display = 'none';
    }

    function toggleMediaSelection(id) {
        const cb = document.querySelector(`.media-checkbox[value="${id}"]`);
        if (cb) {
            cb.checked = !cb.checked;
            updateBulkDeleteButton();
        }
    }

    function addToGallery() {
        const galleryId = document.getElementById('gallery-select').value;
        const newTitle = document.getElementById('new-gallery-title').value.trim();
        const checkedBoxes = document.querySelectorAll('.media-checkbox:checked');
        const mediaIds = Array.from(checkedBoxes).map(cb => cb.value);

        if (galleryId === 'new' && !newTitle) {
            alert('Kérlek adj meg egy nevet az új galériának!');
            return;
        }

        if (mediaIds.length === 0) {
            alert('Nincs kijelölt kép!');
            return;
        }

        const formData = new FormData();
        formData.append('action', 'add_to_gallery');
        formData.append('gallery_id', galleryId);
        formData.append('new_title', newTitle);
        formData.append('media_ids', JSON.stringify(mediaIds));
        formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

        fetch('ajax_gallery.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Sikeresen hozzáadva a galériához!');
                    closeGalleryModal();
                    // Opcionálisan frissíthetjük a listát vagy átirányíthatunk a galéria szerkesztőbe
                } else {
                    alert('Hiba: ' + (data.error || 'Ismeretlen hiba'));
                }
            })
            .catch(err => {
                alert('Hálózati hiba történt.');
                console.error(err);
            });
    }
    // ========================================
    // DRAG & DROP FELTÖLTÉS
    // ========================================
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const uploadProgress = document.getElementById('upload-progress');
    const progressBar = document.getElementById('progress-bar');
    const uploadStatus = document.getElementById('upload-status');
    const uploadPercent = document.getElementById('upload-percent');

    // Kattintásra file input megnyitása
    dropZone.addEventListener('click', () => fileInput.click());

    // Drag & Drop események
    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#6366f1';
        dropZone.style.background = '#e0e7ff';
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#f8fafc';
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = '#cbd5e1';
        dropZone.style.background = '#f8fafc';

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            uploadFiles(files);
        }
    });

    // File input változás
    fileInput.addEventListener('change', (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            uploadFiles(files);
        }
    });

    // Fájlok feltöltése
    function uploadFiles(files) {
        const formData = new FormData();
        let totalFiles = files.length;
        let uploadedFiles = 0;

        uploadProgress.style.display = 'block';
        progressBar.style.width = '0%';
        uploadStatus.textContent = `Feltöltés: 0 / ${totalFiles}`;
        uploadPercent.textContent = '0%';

        // Minden fájl egyesével
        Array.from(files).forEach((file, index) => {
            const xhr = new XMLHttpRequest();
            const fileFormData = new FormData();
            fileFormData.append('file', file);

            // CSRF token hozzáadása
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) {
                fileFormData.append('csrf_token', csrfInput.value);
            }

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                    const totalPercent = Math.round(((uploadedFiles + (percentComplete / 100)) / totalFiles) * 100);
                    progressBar.style.width = totalPercent + '%';
                    uploadPercent.textContent = totalPercent + '%';
                }
            });

            xhr.addEventListener('load', () => {
                uploadedFiles++;
                uploadStatus.textContent = `Feltöltés: ${uploadedFiles} / ${totalFiles}`;

                if (uploadedFiles === totalFiles) {
                    uploadStatus.textContent = '✅ Feltöltés kész!';
                    progressBar.style.width = '100%';
                    uploadPercent.textContent = '100%';

                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                }
            });

            xhr.addEventListener('error', () => {
                uploadStatus.textContent = '❌ Hiba történt a feltöltés közben!';
            });

            xhr.open('POST', 'media.php');
            xhr.send(fileFormData);
        });
    }

    // ========================================
    // MÉDIA RÉSZLETEK MODAL
    // ========================================
    function openMediaDetailModal(media) {
        const modal = document.getElementById('media-detail-modal');
        const content = document.getElementById('media-detail-content');

        const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(media.filepath.split('.').pop().toLowerCase());

        let html = '';

        if (isImage) {
            html += `<img src="../uploads/${media.filepath}" style="width: 100%; max-height: 300px; object-fit: contain; border-radius: 8px; margin-bottom: 20px;">`;
        }

        html += `
        <form method="post">
            <input type="hidden" name="csrf_token" value="${document.querySelector('input[name="csrf_token"]').value}">
            <input type="hidden" name="update_media_id" value="${media.id}">

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Fájlnév:</label>
                <div style="color: #64748b;">${media.filename}</div>
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Útvonal:</label>
                <code style="background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.85rem;">/uploads/${media.filepath}</code>
            </div>

            ${media.width && media.height ? `
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Méret:</label>
                <div style="color: #64748b;">${media.width} × ${media.height} px</div>
            </div>
            ` : ''}

            ${media.file_size ? `
            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Fájlméret:</label>
                <div style="color: #64748b;">${(media.file_size / 1024).toFixed(1)} KB</div>
            </div>
            ` : ''}

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Alt text (SEO):</label>
                <input type="text" name="alt_text" value="${media.alt_text || ''}" style="width: 100%;" placeholder="pl.: Gyönyörű táj naplementében">
            </div>

            <div class="form-group" style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: 600;">Képleírás:</label>
                <textarea name="caption" style="width: 100%; height: 80px;" placeholder="Opcionális leírás a képről...">${media.caption || ''}</textarea>
            </div>

            <button type="submit" class="btn btn-success" style="width: 100%;">💾 Mentés</button>
        </form>
    `;

        content.innerHTML = html;
        modal.style.display = 'flex';
    }

    function closeMediaDetailModal() {
        document.getElementById('media-detail-modal').style.display = 'none';
    }

    // ========================================
    // KERESÉS ÉS KIJELÖLÉS
    // ========================================
    const mediaSearch = document.getElementById('media-search');
    const selectAll = document.getElementById('select-all');
    const mediaCheckboxes = document.querySelectorAll('.media-checkbox');
    const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
    const bulkGalleryBtn = document.getElementById('bulk-gallery-btn');
    const selectedCount = document.getElementById('selected-count');
    const mediaItems = document.querySelectorAll('.media-item');

    // Keresés szűrés
    if (mediaSearch) {
        mediaSearch.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            mediaItems.forEach(item => {
                const filename = item.getAttribute('data-filename');
                if (filename.includes(query)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                    // Uncheck if hidden
                    const cb = item.querySelector('.media-checkbox');
                    if (cb) cb.checked = false;
                }
            });
            updateBulkDeleteButton();
        });
    }

    // Összes kijelölése
    if (selectAll) {
        selectAll.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            document.querySelectorAll('.media-item').forEach(item => {
                if (item.style.display !== 'none') {
                    const cb = item.querySelector('.media-checkbox');
                    if (cb) cb.checked = isChecked;
                }
            });
            updateBulkDeleteButton();
        });
    }

    // Egyenkénti kijelölés váltás
    mediaCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkDeleteButton);
    });

    function updateBulkDeleteButton() {
        const checkedBoxes = document.querySelectorAll('.media-checkbox:checked');
        const count = checkedBoxes.length;

        if (selectedCount) selectedCount.textContent = count;

        if (count > 0) {
            if (bulkDeleteBtn) bulkDeleteBtn.style.display = 'block';
            if (bulkGalleryBtn) bulkGalleryBtn.style.display = 'block';
        } else {
            if (bulkDeleteBtn) bulkDeleteBtn.style.display = 'none';
            if (bulkGalleryBtn) bulkGalleryBtn.style.display = 'none';
            if (selectAll) selectAll.checked = false;
        }
    }

    // Tömeges törlés végrehajtása
    function bulkDeleteSelected() {
        const checkedBoxes = document.querySelectorAll('.media-checkbox:checked');
        const ids = Array.from(checkedBoxes).map(cb => cb.value);

        if (ids.length === 0) return;

        if (!confirm(`Biztosan törölni szeretnéd a kijelölt ${ids.length} fájlt? Ez a művelet nem vonható vissza!`)) {
            return;
        }

        // Form létrehozása és elküldése
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'media.php';

        // CSRF token
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = document.querySelector('input[name="csrf_token"]').value;
        form.appendChild(csrfInput);

        // ID-k hozzáadása tömbként
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'bulk_delete_ids[]';
            input.value = id;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }

    // ESC = modal bezárása
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeMediaDetailModal();
        }
    });
</script>

<?php
admin_footer();
