<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$page_title = 'Notifications';
$userId = (int) user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$userId]);
        flash('success', 'All notifications marked as read.');
    } elseif ($action === 'mark_read') {
        $id = (int)($_POST['notification_id'] ?? 0);
        $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    } elseif ($action === 'delete') {
        $id = (int)($_POST['notification_id'] ?? 0);
        $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        flash('success', 'Notification deleted.');
    }

    header('Location: ' . BASE_URL . '/modules/notifications/index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$unreadCount = 0;
foreach ($notifications as $notification) {
    if (!(int)$notification['is_read']) $unreadCount++;
}

function notification_icon(string $type): string {
    return match (strtolower($type)) {
        'success', 'approved' => 'bi-check-circle-fill text-success',
        'danger', 'rejected', 'alert' => 'bi-exclamation-triangle-fill text-danger',
        'warning', 'pending' => 'bi-clock-fill text-warning',
        'maintenance' => 'bi-tools text-primary',
        'stock' => 'bi-box-seam text-warning',
        default => 'bi-bell-fill text-primary',
    };
}

require '../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="mb-1">Notifications</h2>
        <p class="text-muted mb-0">You have <?= e($unreadCount) ?> unread notification<?= $unreadCount === 1 ? '' : 's' ?>.</p>
    </div>
    <?php if ($unreadCount > 0): ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-outline-primary"><i class="bi bi-check2-all me-1"></i>Mark All as Read</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (!$notifications): ?>
            <div class="text-center p-5">
                <i class="bi bi-bell-slash display-4 text-muted"></i>
                <h5 class="mt-3">No notifications yet</h5>
                <p class="text-muted mb-0">New reservation and system updates will appear here.</p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($notifications as $notification): ?>
                    <div class="list-group-item p-3 <?= !(int)$notification['is_read'] ? 'bg-light' : '' ?>">
                        <div class="d-flex gap-3">
                            <div class="fs-4"><i class="bi <?= e(notification_icon((string)$notification['type'])) ?>"></i></div>
                            <div class="flex-grow-1">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div>
                                        <h6 class="mb-1">
                                            <?= e($notification['title']) ?>
                                            <?php if (!(int)$notification['is_read']): ?><span class="badge bg-danger ms-1">New</span><?php endif; ?>
                                        </h6>
                                        <p class="mb-1 text-muted"><?= e($notification['message']) ?></p>
                                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?= e($notification['created_at']) ?></small>
                                    </div>
                                    <div class="d-flex gap-2 align-self-start">
                                        <?php if (!(int)$notification['is_read']): ?>
                                            <form method="post">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?= e($notification['id']) ?>">
                                                <button class="btn btn-sm btn-outline-primary" title="Mark as read"><i class="bi bi-check2"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('Delete this notification?')">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="notification_id" value="<?= e($notification['id']) ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>
