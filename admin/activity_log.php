<?php
/**
 * Műveletnapló – Activity Log
 */
require_once '../config.php';
require_once '../core/UrlHelper.php';
require_once 'layout.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

if (!is_super_admin()) {
    header('Location: index.php');
    exit;
}

// ---------- Szűrők ----------
$filterAction = $_GET['action_filter'] ?? '';
$filterUser = $_GET['user_filter'] ?? '';

// ---------- Lapozó ----------
$perPage = 25;
$currentPage = max(1, (int) ($_GET['p'] ?? 1));
$offset = ($currentPage - 1) * $perPage;

// ---------- Lekérdezés ----------
$where = [];
$params = [];

if ($filterAction) {
    $where[] = 'action = ?';
    $params[] = $filterAction;
}
if ($filterUser) {
    $where[] = 'username LIKE ?';
    $params[] = '%' . $filterUser . '%';
}

$whereSQL = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$totalLogs = $db->prepare("SELECT COUNT(*) FROM activity_log" . $whereSQL);
$totalLogs->execute($params);
$total = $totalLogs->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("SELECT * FROM activity_log" . $whereSQL . " ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Felhasználók listája szűrőhöz
$users = $db->query("SELECT DISTINCT username FROM activity_log ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

admin_header('Műveletnapló');

$actionLabels = [
    'login' => ['🔑 Bejelentkezés', '#16a34a'],
    'logout' => ['🚪 Kijelentkezés', '#64748b'],
    'create' => ['➕ Létrehozás', '#2563eb'],
    'update' => ['✏️ Módosítás', '#ca8a04'],
    'delete' => ['🗑️ Lomtárba', '#dc2626'],
    'restore' => ['♻️ Visszaállítás', '#16a34a'],
    'purge' => ['❌ Végleges törlés', '#991b1b'],
];
?>

<!-- Szűrők -->
<div
    style="background: #fff; padding: 15px 20px; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
    <form method="get" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <select name="action_filter" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            <option value="">Összes művelet</option>
            <?php foreach ($actionLabels as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $filterAction === $key ? 'selected' : ''; ?>>
                    <?php echo $label[0]; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="user_filter" style="padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
            <option value="">Összes felhasználó</option>
            <?php foreach ($users as $u): ?>
                <option value="<?php echo htmlspecialchars($u); ?>" <?php echo $filterUser === $u ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($u); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="btn" style="padding: 6px 16px; font-size: 0.9rem;">🔍 Szűrés</button>
        <?php if ($filterAction || $filterUser): ?>
            <a href="activity_log.php" style="color: #dc2626; text-decoration: none; font-size: 0.85rem;">✕ Szűrők
                törlése</a>
        <?php endif; ?>
    </form>
    <div style="margin-left: auto; color: #94a3b8; font-size: 0.85rem;">
        Összesen: <strong>
            <?php echo $total; ?>
        </strong> bejegyzés
    </div>
</div>

<!-- Napló lista -->
<?php if (empty($logs)): ?>
    <p style="color: #94a3b8;">Nincs bejegyzett tevékenység.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th style="width: 160px;">Időpont</th>
                <th>Felhasználó</th>
                <th>Művelet</th>
                <th>Cél</th>
                <th style="width: 120px;">IP cím</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): ?>
                <?php $label = $actionLabels[$log['action']] ?? ['❓ ' . $log['action'], '#64748b']; ?>
                <tr>
                    <td style="font-size: 0.85rem; color: #64748b; white-space: nowrap;">
                        <?php echo date('Y.m.d H:i:s', strtotime($log['created_at'])); ?>
                    </td>
                    <td>
                        <strong>
                            <?php echo htmlspecialchars($log['username']); ?>
                        </strong>
                    </td>
                    <td>
                        <span style="color: <?php echo $label[1]; ?>; font-weight: 600;">
                            <?php echo $label[0]; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($log['target_type']): ?>
                            <span
                                style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; color: #64748b;">
                                <?php echo htmlspecialchars($log['target_type']); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($log['target_title']): ?>
                            <?php echo htmlspecialchars($log['target_title']); ?>
                        <?php endif; ?>
                        <?php if ($log['details']): ?>
                            <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 2px;">
                                <?php echo htmlspecialchars($log['details']); ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.8rem; color: #94a3b8;">
                        <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Lapozó -->
    <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 6px; margin-top: 25px;">
            <?php
            $qStr = '';
            if ($filterAction)
                $qStr .= '&action_filter=' . urlencode($filterAction);
            if ($filterUser)
                $qStr .= '&user_filter=' . urlencode($filterUser);
            ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="activity_log.php?p=<?php echo $i . $qStr; ?>" style="padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 600;
                          <?php echo $i === $currentPage
                              ? 'background: #2563eb; color: #fff;'
                              : 'background: #f1f5f9; color: #64748b;'; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
admin_footer();
