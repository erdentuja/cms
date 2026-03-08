<?php
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_admin()) {
    header('Location: index.php');
    exit;
}

$message = '';
$messageType = '';
$editPost = null;

// ---------- Mentés ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $message = 'Érvénytelen kérés.';
        $messageType = 'error';
    } else {
        $title = trim($_POST['title'] ?? '');
        $baseSlug = $_POST['slug'] ?: $title;
        $content = $_POST['content'] ?? '';
        $status = $_POST['status'] ?? 'published';
        $postType = $_POST['post_type'] ?? 'page';
        $metaTitle = trim($_POST['meta_title'] ?? '');
        $metaDesc = trim($_POST['meta_description'] ?? '');
        $featured = trim($_POST['featured_image'] ?? '');
        $publishAt = !empty($_POST['publish_at']) ? $_POST['publish_at'] : null;
        $postId = $_POST['post_id'] ?? '';
        $catIds = $_POST['categories'] ?? [];

        if ($status === 'scheduled' && !$publishAt) {
            $message = 'Ütemezett publikáláshoz dátum megadása kötelező.';
            $messageType = 'error';
        } elseif ($title === '') {
            $message = 'A cím megadása kötelező.';
            $messageType = 'error';
        } else {
            $slug = UrlHelper::generateUniqueSlug($db, $baseSlug, $postId ?: null);

            if ($postId) {
                // UPDATE
                $stmtOld = $db->prepare('SELECT title, content FROM posts WHERE id = ?');
                $stmtOld->execute([$postId]);
                $oldPost = $stmtOld->fetch();

                $db->prepare('UPDATE posts SET title=?, slug=?, content=?, status=?, post_type=?, meta_title=?, meta_description=?, featured_image=?, publish_at=? WHERE id=?')
                    ->execute([$title, $slug, $content, $status, $postType, $metaTitle, $metaDesc, $featured ?: null, $publishAt, $postId]);

                if ($oldPost && ($oldPost['title'] !== $title || $oldPost['content'] !== $content)) {
                    $db->prepare('INSERT INTO post_revisions (post_id, admin_id, title, content) VALUES (?, ?, ?, ?)')
                        ->execute([$postId, $_SESSION['admin_id'], $title, $content]);
                }

                log_activity($db, 'update', $postType, (int) $postId, $title);
                $_SESSION['flash_message'] = ($postType === 'post' ? 'Bejegyzés' : 'Oldal') . ' frissítve!';
                $_SESSION['flash_type'] = 'success';
            } else {
                // INSERT
                $db->prepare('INSERT INTO posts (title, slug, content, status, post_type, meta_title, meta_description, featured_image, publish_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$title, $slug, $content, $status, $postType, $metaTitle, $metaDesc, $featured ?: null, $publishAt]);
                $postId = $db->lastInsertId();

                $db->prepare('INSERT INTO post_revisions (post_id, admin_id, title, content) VALUES (?, ?, ?, ?)')
                    ->execute([$postId, $_SESSION['admin_id'], $title, $content]);

                log_activity($db, 'create', $postType, (int) $postId, $title);
                $_SESSION['flash_message'] = ($postType === 'post' ? 'Bejegyzés' : 'Oldal') . ' létrehozva!';
                $_SESSION['flash_type'] = 'success';
            }

            // Kategóriák mentése
            $db->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$postId]);
            if (!empty($catIds)) {
                $stmt = $db->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)');
                foreach ($catIds as $cid) {
                    $stmt->execute([$postId, $cid]);
                }
            }

            header('Location: posts.php?type=' . $postType);
            exit;
        }
    }
}

// ---------- Szerkesztés betöltése ----------
if (isset($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$_GET['id']]);
    $editPost = $stmt->fetch();
    if ($editPost) {
        $editPost['_categories'] = $db->prepare('SELECT category_id FROM post_categories WHERE post_id = ?');
        $editPost['_categories']->execute([$editPost['id']]);
        $editPost['_categories'] = $editPost['_categories']->fetchAll(PDO::FETCH_COLUMN);
    }
}

// ---------- Adatok a formhoz ----------
try {
    $allCategories = $db->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
} catch (PDOException $e) {
    $allCategories = [];
}
$allMedia = $db->query("SELECT * FROM media ORDER BY id DESC")->fetchAll();
$allGalleries = $db->query("SELECT * FROM galleries ORDER BY title ASC")->fetchAll();

$pageTitle = $editPost ? '✏️ Szerkesztés' : '➕ Új tartalom';

$topBar = [];
ob_start();
?>
<button type="button" onclick="openAiModal()" class="btn"
    style="background:#8b5cf6; color:#fff; display:flex; align-items:center; gap:5px; font-weight:bold; margin-right: 15px;">
    ✨ AI Generálás
</button>
<?php if ($editPost): ?>
    <button type="button" onclick="openRevisionsModal()" class="btn"
        style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; display:flex; align-items:center; gap:5px; font-weight:500; margin-right: 15px;">
        🕒 Verziótörténet
    </button>
<?php endif; ?>
<a href="posts.php" class="btn"
    style="text-decoration: none; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1;">Mégse</a>
<button type="submit" name="save" form="post-form" class="btn btn-success"
    style="font-weight: bold; padding: 8px 20px;">💾 Mentés</button>
<?php
$topBar['actions_html'] = ob_get_clean();

if ($editPost) {
    $topBar['view_link'] = UrlHelper::link($editPost['slug']);
}

admin_header($pageTitle, $topBar);
?>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<div style="background: #fff; padding: 25px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 30px;">
    <form method="post" id="post-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="post_id" value="<?php echo $editPost['id'] ?? ''; ?>">

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label>Cím *</label>
                <input type="text" name="title" value="<?php echo htmlspecialchars($editPost['title'] ?? ''); ?>"
                    required style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Slug (URL)</label>
                <input type="text" name="slug" value="<?php echo htmlspecialchars($editPost['slug'] ?? ''); ?>"
                    placeholder="automatikus" style="width: 100%;">
            </div>
            <div class="form-group">
                <label>Típus</label>
                <select name="post_type" id="post-type-select" style="width: 100%;" onchange="toggleCategorySection()">
                    <option value="page" <?php echo ($editPost['post_type'] ?? 'page') === 'page' ? 'selected' : ''; ?>
                        >📄 Oldal</option>
                    <option value="post" <?php echo ($editPost['post_type'] ?? '') === 'post' ? 'selected' : ''; ?>>📰
                        Bejegyzés</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                <label style="margin: 0;">Tartalom</label>
                <div id="save-status" style="font-size: 0.8rem; color: #94a3b8; transition: opacity 0.3s;"></div>
            </div>
            <textarea name="content" id="editor"><?php echo htmlspecialchars($editPost['content'] ?? ''); ?></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            <!-- Bal oldal: SEO + állapot -->
            <div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Állapot</label>
                        <select name="status" id="status-select" style="width: 100%;" onchange="togglePublishAt()">
                            <option value="published" <?php echo ($editPost['status'] ?? 'published') === 'published' ? 'selected' : ''; ?>>🟢 Publikált</option>
                            <option value="draft" <?php echo ($editPost['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>⚪ Vázlat</option>
                            <option value="scheduled" <?php echo ($editPost['status'] ?? '') === 'scheduled' ? 'selected' : ''; ?>>⏰ Ütemezett</option>
                        </select>
                    </div>
                    <div class="form-group" id="publish-at-group"
                        style="<?php echo ($editPost['status'] ?? '') !== 'scheduled' ? 'display:none;' : ''; ?>">
                        <label>Publikálás ideje</label>
                        <input type="datetime-local" name="publish_at"
                            value="<?php echo $editPost['publish_at'] ? date('Y-m-d\TH:i', strtotime($editPost['publish_at'])) : ''; ?>"
                            style="width: 100%;">
                    </div>
                </div>

                <div
                    style="background: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 10px;">
                    <div
                        style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                        <h4 style="margin: 0; color: #475569;">🔍 SEO Beállítások</h4>
                        <button type="button" onclick="generateAiSeo()" class="btn"
                            style="background: #8b5cf6; color: #fff; font-size: 0.8rem; padding: 4px 10px;">🤖 AI SEO
                            javaslat</button>
                    </div>
                    <div class="form-group">
                        <label>Meta cím (SEO)</label>
                        <input type="text" name="meta_title" id="meta-title-input"
                            value="<?php echo htmlspecialchars($editPost['meta_title'] ?? ''); ?>" style="width: 100%;">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Meta leírás (SEO)</label>
                        <input type="text" name="meta_description" id="meta-desc-input"
                            value="<?php echo htmlspecialchars($editPost['meta_description'] ?? ''); ?>"
                            style="width: 100%;">
                    </div>
                </div>
            </div>

            <!-- Jobb oldal: kiemelt kép + kategóriák -->
            <div>
                <div class="form-group">
                    <label>Kiemelt kép</label>
                    <input type="hidden" name="featured_image" id="featured-image-input"
                        value="<?php echo htmlspecialchars($editPost['featured_image'] ?? ''); ?>">
                    <div id="featured-preview" style="margin-bottom: 10px;">
                        <?php if (!empty($editPost['featured_image'])): ?>
                            <img src="../uploads/<?php echo htmlspecialchars($editPost['featured_image']); ?>"
                                style="max-width: 100%; max-height: 150px; border-radius: 6px; border: 1px solid #e5e7eb;">
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn" onclick="openFeaturedModal()"
                            style="font-size: 0.85rem; padding: 6px 14px;">🖼️ Választás</button>
                        <button type="button" class="btn btn-danger" onclick="removeFeatured()"
                            style="font-size: 0.85rem; padding: 6px 14px;">✕ Eltávolítás</button>
                    </div>
                </div>

                <div class="form-group" id="categories-section"
                    style="<?php echo ($editPost['post_type'] ?? 'page') !== 'post' ? 'display:none;' : ''; ?>">
                    <label>Kategóriák</label>
                    <?php if (empty($allCategories)): ?>
                        <p style="color: #94a3b8; font-size: 0.85rem;">Nincs kategória. <a href="categories.php">Hozd létre
                                itt.</a></p>
                    <?php else: ?>
                        <div
                            style="max-height: 150px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px;">
                            <?php foreach ($allCategories as $cat): ?>
                                <label
                                    style="display: flex; align-items: center; gap: 8px; padding: 4px 0; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="categories[]" value="<?php echo $cat['id']; ?>" <?php echo in_array($cat['id'], $editPost['_categories'] ?? []) ? 'checked' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display: flex; gap: 10px; margin-top: 15px;">
            <button type="submit" name="save" class="btn btn-success">💾 Mentés</button>
            <a href="posts.php" class="btn" style="text-decoration: none;">Mégse</a>
        </div>
    </form>
</div>

<!-- Modals & Scripts -->
<div id="featured-modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div
        style="background:#fff; border-radius:12px; width:90%; max-width:800px; max-height:80vh; display:flex; flex-direction:column; overflow:hidden;">
        <div
            style="padding:15px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">🖼️ Kiemelt kép választása</h3>
            <button onclick="closeFeaturedModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div
            style="padding:20px; overflow-y:auto; display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px;">
            <?php foreach ($allMedia as $m):
                $ext = strtolower(pathinfo($m['filepath'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']))
                    continue;
                ?>
                <div onclick="selectFeatured('<?php echo htmlspecialchars($m['filepath']); ?>')"
                    style="cursor:pointer; border:2px solid transparent; border-radius:8px; overflow:hidden; transition:all 0.2s;"
                    onmouseenter="this.style.borderColor='#2563eb'" onmouseleave="this.style.borderColor='transparent'">
                    <img src="../uploads/<?php echo htmlspecialchars($m['filepath']); ?>"
                        style="width:100%;height:100px;object-fit:cover;display:block;">
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div id="media-modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div
        style="background:#fff; border-radius:12px; width:90%; max-width:800px; max-height:80vh; display:flex; flex-direction:column; overflow:hidden;">
        <div
            style="padding:15px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">🖼️ Média könyvtár</h3>
            <button onclick="closeMediaModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div id="media-grid"
            style="padding:20px; overflow-y:auto; display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:12px;">
        </div>
    </div>
</div>

<div id="gallery-select-modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
    <div
        style="background:#fff; border-radius:12px; width:90%; max-width:500px; max-height:80vh; display:flex; flex-direction:column; overflow:hidden;">
        <div
            style="padding:15px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">🖼️ Galéria beillesztése</h3>
            <button onclick="closeGallerySelectModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div style="padding:20px; overflow-y:auto;">
            <?php if (empty($allGalleries)): ?>
                <p style="text-align:center; color:#64748b;">Nincs még létrehozott galéria. <a href="galleries.php">Hozz
                        létre egyet!</a></p>
            <?php else: ?>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach ($allGalleries as $g):
                        $count = $db->prepare("SELECT COUNT(*) FROM gallery_items WHERE gallery_id = ?");
                        $count->execute([$g['id']]);
                        $imgCount = $count->fetchColumn();
                        ?>
                        <div onclick="insertGalleryShortcode(<?php echo $g['id']; ?>)"
                            style="cursor:pointer; padding:12px; border:1px solid #e5e7eb; border-radius:8px; display:flex; justify-content:space-between; align-items:center; transition:all 0.2s;"
                            onmouseenter="this.style.borderColor='#2563eb'; this.style.background='#f0f7ff';"
                            onmouseleave="this.style.borderColor='#e5e7eb'; this.style.background='#fff';">
                            <span style="font-weight:600;">
                                <?php echo htmlspecialchars($g['title']); ?>
                            </span>
                            <span style="font-size:0.85rem; color:#64748b;">
                                <?php echo $imgCount; ?> kép
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="ai-modal"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; justify-content:center; align-items:center;">
    <div
        style="background:#fff; border-radius:12px; width:90%; max-width:500px; display:flex; flex-direction:column; overflow:hidden;">
        <div
            style="padding:15px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0; color:#8b5cf6;">✨ Tartalom generálása AI-val</h3>
            <button onclick="closeAiModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div style="padding:20px;">
            <div id="ai-form">
                <label style="display:block; margin-bottom:8px; font-weight:600;">Miről szóljon a cikk/oldal?</label>
                <textarea id="ai-topic"
                    style="width:100%; height:100px; padding:10px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:15px; resize:vertical;"></textarea>
                <button type="button" onclick="generateAiContent()" class="btn"
                    style="width:100%; background:#8b5cf6; color:#fff; font-weight:bold; height:44px;">Generálás
                    elindítása</button>
            </div>
            <div id="ai-loading" style="display:none; text-align:center; padding:20px 0;">
                <div
                    style="font-size:2rem; animation: spin 2s linear infinite; display:inline-block; margin-bottom:10px;">
                    ⏳</div>
                <h4 style="margin:0; color:#1e293b;">Az AI dolgozik...</h4>
                <p style="color:#64748b; font-size:0.9rem; margin-top:5px;">Ez eltarthat 15-30 másodpercig. Ne zárd be
                    az ablakot!</p>
            </div>
        </div>
    </div>
</div>

<div id="revisions-modal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10001; justify-content:center; align-items:center; backdrop-filter: blur(4px);">
    <div
        style="background:#fff; width:90%; max-width:800px; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); overflow:hidden; display:flex; flex-direction:column; max-height:85vh;">
        <div
            style="padding:20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
            <h3 style="margin:0; color:#1e293b; display:flex; align-items:center; gap:10px;">🕒 Verziótörténet</h3>
            <button type="button" onclick="closeRevisionsModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        <div id="revisions-list" style="padding:0; overflow-y:auto; flex:1;">
            <div style="padding:40px; text-align:center; color:#64748b;">Betöltés...</div>
        </div>
        <div
            style="padding:15px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
            <button type="button" onclick="deleteRevisionsHistory()" class="btn"
                style="background:#fff; border:1px solid #fecaca; color:#dc2626; font-size:0.85rem;">🗑️ Történet
                törlése</button>
            <button type="button" onclick="closeRevisionsModal()" class="btn"
                style="background:#fff; border:1px solid #cbd5e1; color:#475569;">Bezárás</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.tiny.cloud/1/mreq42swlega29mwpkjagy86dm94cdud6s39ocbnufrv2ok4/tinymce/7/tinymce.min.js"
    referrerpolicy="origin"></script>
<script>
    let tinymceInstance = null;
    const postId = '<?php echo $editPost['id'] ?? ''; ?>';

    tinymce.init({
        selector: '#editor',
        height: 500,
        menubar: true,
        plugins: ['advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen', 'insertdatetime', 'media', 'table', 'help', 'wordcount'],
        toolbar: ['undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent', 'medialib gallerylib link image media | ai_improve ai_tldr voice_to_text | removeformat code fullscreen help'],
        setup: function (editor) {
            tinymceInstance = editor;
            editor.ui.registry.addButton('medialib', { text: '🖼️ Média', onAction: openMediaModal });
            editor.ui.registry.addButton('gallerylib', { text: '🖼️ Galéria', onAction: openGallerySelectModal });
            editor.ui.registry.addMenuButton('ai_improve', {
                text: '🧠 Okosítás',
                fetch: function (callback) {
                    callback([
                        { type: 'menuitem', text: '🧑‍💼 Alapértelmezett', onAction: () => improveSelectedText('default') },
                        { type: 'menuitem', text: '😂 Vicces', onAction: () => improveSelectedText('funny') },
                        { type: 'menuitem', text: '🧐 Tudálékos', onAction: () => improveSelectedText('academic') },
                        { type: 'menuitem', text: '👔 Hivatalos', onAction: () => improveSelectedText('formal') },
                        { type: 'menuitem', text: '🍻 Laza', onAction: () => improveSelectedText('casual') },
                        { type: 'menuitem', text: '✂️ Rövid', onAction: () => improveSelectedText('short') }
                    ]);
                }
            });
            editor.ui.registry.addButton('ai_tldr', { text: '⚡ TL;DR', onAction: generateTLDR });

            // Hangalapú
            let recognition = null;
            if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
                const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
                recognition = new SpeechRec();
                recognition.lang = 'hu-HU';
                recognition.continuous = true;
                recognition.interimResults = true;
                recognition.onresult = (e) => {
                    let trans = '';
                    for (let i = e.resultIndex; i < e.results.length; ++i) if (e.results[i].isFinal) trans += e.results[i][0].transcript;
                    if (trans) editor.insertContent(trans + ' ');
                };
            }

            editor.ui.registry.addToggleButton('voice_to_text', {
                text: '🎤 Dikktálás',
                onAction: function (api) {
                    if (!recognition) return alert('Nem támogatott böngésző.');
                    const active = !api.isActive();
                    api.setActive(active);
                    if (active) { recognition.start(); api.setText('🛑 Állj'); }
                    else { recognition.stop(); api.setText('🎤 Dikktálás'); }
                }
            });

            editor.on('change keyup', () => { if (typeof markDirty === 'function') markDirty(); });
        },
        content_style: 'body { font-family: Arial, sans-serif; font-size: 14px; }',
        relative_urls: false,
        remove_script_host: false
    });

    function openMediaModal() {
        document.getElementById('media-modal').style.display = 'flex';
        fetch('media_api.php').then(r => r.json()).then(items => {
            const grid = document.getElementById('media-grid');
            grid.innerHTML = '';
            items.filter(i => i.is_image).forEach(item => {
                const div = document.createElement('div');
                div.style.cssText = 'cursor:pointer;border:2px solid transparent;border-radius:8px;overflow:hidden;';
                div.innerHTML = `<img src="${item.url}" style="width:100%;height:100px;object-fit:cover;">`;
                div.onclick = () => { if (tinymceInstance) tinymceInstance.insertContent(`<img src="${item.url}" style="max-width:100%;height:auto;">`); closeMediaModal(); };
                grid.appendChild(div);
            });
        });
    }
    function closeMediaModal() { document.getElementById('media-modal').style.display = 'none'; }
    function openGallerySelectModal() { document.getElementById('gallery-select-modal').style.display = 'flex'; }
    function closeGallerySelectModal() { document.getElementById('gallery-select-modal').style.display = 'none'; }
    function insertGalleryShortcode(id) { if (tinymceInstance) tinymceInstance.insertContent(`[gallery id="${id}"]`); closeGallerySelectModal(); }
    function openFeaturedModal() { document.getElementById('featured-modal').style.display = 'flex'; }
    function closeFeaturedModal() { document.getElementById('featured-modal').style.display = 'none'; }
    function selectFeatured(path) {
        document.getElementById('featured-image-input').value = path;
        document.getElementById('featured-preview').innerHTML = `<img src="../uploads/${path}" style="max-width:100%;max-height:150px;border-radius:6px;border:1px solid #e5e7eb;">`;
        closeFeaturedModal();
    }
    function removeFeatured() { document.getElementById('featured-image-input').value = ''; document.getElementById('featured-preview').innerHTML = ''; }
    function toggleCategorySection() { document.getElementById('categories-section').style.display = document.getElementById('post-type-select').value === 'post' ? '' : 'none'; }
    function togglePublishAt() { document.getElementById('publish-at-group').style.display = document.getElementById('status-select').value === 'scheduled' ? '' : 'none'; }
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeMediaModal(); closeFeaturedModal(); closeAiModal(); closeRevisionsModal(); } });

    // AI
    function openAiModal() { document.getElementById('ai-modal').style.display = 'flex'; document.getElementById('ai-topic').focus(); }
    function closeAiModal() { document.getElementById('ai-modal').style.display = 'none'; }
    function generateAiContent() {
        const topic = document.getElementById('ai-topic').value;
        if (!topic) return alert('Írj be egy témát!');
        document.getElementById('ai-form').style.display = 'none';
        document.getElementById('ai-loading').style.display = 'block';
        fetch('ajax_ai_generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `topic=${encodeURIComponent(topic)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        }).then(r => r.json()).then(data => {
            if (data.success) {
                document.querySelector('input[name="title"]').value = data.title;
                if (tinymceInstance) tinymceInstance.setContent(data.content);
                closeAiModal();
            } else alert('Hiba: ' + data.error);
        }).catch(() => alert('Szerver hiba.')).finally(() => {
            document.getElementById('ai-form').style.display = 'block';
            document.getElementById('ai-loading').style.display = 'none';
        });
    }

    function improveSelectedText(style) {
        if (!tinymceInstance) return;
        const selectedText = tinymceInstance.selection.getContent({ format: 'text' });
        if (!selectedText) return alert('Kérlek jelölj ki szöveget az okosításhoz!');
        tinymceInstance.setProgressState(true);
        fetch('ajax_ai_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=improve_text&style=${style}&text=${encodeURIComponent(selectedText)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        }).then(r => r.json()).then(data => {
            if (data.success) tinymceInstance.selection.setContent(data.improved_text);
            else alert('Hiba: ' + data.error);
        }).finally(() => tinymceInstance.setProgressState(false));
    }

    function generateTLDR() {
        if (!tinymceInstance) return;
        const content = tinymceInstance.getContent({ format: 'text' });
        if (content.length < 100) return alert('Túl rövid a szöveg az összefoglaláshoz!');
        tinymceInstance.setProgressState(true);
        fetch('ajax_ai_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=generate_tldr&text=${encodeURIComponent(content)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        }).then(r => r.json()).then(data => {
            if (data.success) tinymceInstance.setContent(`<p><em><strong>Röviden:</strong> ${data.tldr}</em></p><hr>` + tinymceInstance.getContent());
            else alert('Hiba: ' + data.error);
        }).finally(() => tinymceInstance.setProgressState(false));
    }

    function generateAiSeo() {
        const title = document.querySelector('input[name="title"]').value;
        const content = tinymceInstance ? tinymceInstance.getContent({ format: 'text' }) : '';
        if (!title && !content) return alert('Kell egy cím vagy tartalom a SEO javaslathoz!');
        fetch('ajax_ai_assistant.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=generate_seo&title=${encodeURIComponent(title)}&content=${encodeURIComponent(content)}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        }).then(r => r.json()).then(data => {
            if (data.success) {
                document.getElementById('meta-title-input').value = data.meta_title;
                document.getElementById('meta-desc-input').value = data.meta_description;
            } else alert('Hiba: ' + data.error);
        });
    }

    // REV
    function openRevisionsModal() {
        if (!postId) return;
        document.getElementById('revisions-modal').style.display = 'flex';
        fetch(`ajax_get_revisions.php?post_id=${postId}`).then(r => r.json()).then(data => {
            const list = document.getElementById('revisions-list');
            if (data.length === 0) list.innerHTML = '<div style="padding:40px; text-align:center;">Nincs korábbi verzió.</div>';
            else {
                let html = '<table style="width:100%; border-collapse:collapse;">';
                data.forEach(rev => {
                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:15px;"><strong>${rev.created_at}</strong><br><small>${rev.admin_user}</small></td>
                        <td style="padding:15px; text-align:right;">
                            <button onclick="restoreRevision(${rev.id})" class="btn" style="font-size:0.8rem; padding:5px 10px;">Visszaállítás</button>
                        </td>
                    </tr>`;
                });
                html += '</table>';
                list.innerHTML = html;
            }
        });
    }
    function closeRevisionsModal() { document.getElementById('revisions-modal').style.display = 'none'; }
    function restoreRevision(id) {
        if (!confirm('Biztosan visszatöltöd ezt a verziót?')) return;
        fetch(`ajax_get_revisions.php?revision_id=${id}`).then(r => r.json()).then(data => {
            document.querySelector('input[name="title"]').value = data.title;
            if (tinymceInstance) tinymceInstance.setContent(data.content);
            closeRevisionsModal();
            alert('Verzió visszatöltve! (Ne felejtsd el elmenteni)');
        });
    }
    function deleteRevisionsHistory() {
        if (!confirm('Biztosan törlöd az ÖSSZES korábbi verziót ehhez a bejegyzéshez?')) return;
        fetch('ajax_delete_revisions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `post_id=${postId}&csrf_token=<?php echo $_SESSION['csrf_token']; ?>`
        }).then(r => r.json()).then(data => {
            if (data.success) { alert('Sikeresen törölve!'); openRevisionsModal(); }
            else alert('Hiba: ' + data.error);
        });
    }

    // Auto-save logic (LocalStorage only for now, can add API later if needed)
    (function () {
        const LOCALSTORAGE_KEY = 'cms_post_autosave_' + (postId || 'new');
        const AUTO_SAVE_INTERVAL = 30000;
        let isDirty = false;

        function autoSave() {
            if (!isDirty) return;
            const data = {
                title: document.querySelector('input[name="title"]').value,
                slug: document.querySelector('input[name="slug"]').value,
                content: tinymceInstance ? tinymceInstance.getContent() : '',
                post_type: document.getElementById('post-type-select').value,
                status: document.getElementById('status-select').value,
                meta_title: document.getElementById('meta-title-input').value,
                meta_description: document.getElementById('meta-desc-input').value,
                featured_image: document.getElementById('featured-image-input').value,
                timestamp: Date.now()
            };
            localStorage.setItem(LOCALSTORAGE_KEY, JSON.stringify({ data, timestamp: Date.now() }));
            isDirty = false;
            const statusDiv = document.getElementById('save-status');
            statusDiv.textContent = '✓ Automatikusan mentve (helyi)';
            setTimeout(() => { statusDiv.textContent = ''; }, 3000);
        }

        setInterval(autoSave, AUTO_SAVE_INTERVAL);
        window.addEventListener('beforeunload', (e) => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
        document.getElementById('post-form').addEventListener('input', () => isDirty = true);

        // Helyreállítás
        const saved = localStorage.getItem(LOCALSTORAGE_KEY);
        if (saved) {
            const parsed = JSON.parse(saved);
            if (Date.now() - parsed.timestamp < 24 * 3600 * 1000) {
                if (confirm('Találtam egy mentetlen változatot. Visszaállítod?')) {
                    const d = parsed.data;
                    document.querySelector('input[name="title"]').value = d.title;
                    document.querySelector('input[name="slug"]').value = d.slug;
                    if (tinymceInstance) tinymceInstance.on('init', () => tinymceInstance.setContent(d.content));
                    document.getElementById('post-type-select').value = d.post_type;
                    document.getElementById('status-select').value = d.status;
                    document.getElementById('meta-title-input').value = d.meta_title;
                    document.getElementById('meta-desc-input').value = d.meta_description;
                    if (d.featured_image) {
                        document.getElementById('featured-image-input').value = d.featured_image;
                        document.getElementById('featured-preview').innerHTML = `<img src="../uploads/${d.featured_image}" style="max-width:100%;max-height:150px;border-radius:6px;border:1px solid #e5e7eb;">`;
                    }
                }
            }
        }
    })();
</script>

<?php
admin_footer();
