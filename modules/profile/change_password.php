<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$page_title = 'Change Password';
$userId = (int) user()['id'];

$columns = [];
foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $columns[$column['Field']] = true;
}
$passwordColumn = isset($columns['password']) ? 'password' : (isset($columns['password_hash']) ? 'password_hash' : null);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!$passwordColumn) {
        $errors[] = 'No password column was found in the users table.';
    } else {
        $stmt = $pdo->prepare("SELECT `$passwordColumn` FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $stored = (string)$stmt->fetchColumn();

        $validCurrent = password_verify($current, $stored) || hash_equals($stored, $current);
        if (!$validCurrent) $errors[] = 'The current password is incorrect.';
        if (strlen($new) < 8) $errors[] = 'The new password must contain at least 8 characters.';
        if ($new !== $confirm) $errors[] = 'The new password confirmation does not match.';
        if ($current === $new) $errors[] = 'The new password must be different from the current password.';

        if (!$errors) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET `$passwordColumn` = ? WHERE id = ?")->execute([$hash, $userId]);
            audit($pdo, 'Update', 'Profile', 'Changed account password');
            flash('success', 'Password changed successfully.');
            header('Location: ' . BASE_URL . '/modules/profile/index.php');
            exit;
        }
    }
}

require '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="mb-1">Change Password</h2>
                <p class="text-muted mb-0">Use a strong password with at least 8 characters.</p>
            </div>
            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/profile/index.php">Back</a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input class="form-control" type="password" name="new_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input class="form-control" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary w-100"><i class="bi bi-shield-check me-1"></i>Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>
