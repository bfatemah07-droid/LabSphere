<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$page_title = 'Edit Profile';
$userId = (int) user()['id'];

$columns = [];
foreach ($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC) as $column) {
    $columns[$column['Field']] = true;
}

$editableCandidates = [
    'name' => 'Full Name',
    'full_name' => 'Full Name',
    'email' => 'Email Address',
    'phone' => 'Phone Number',
    'contact_number' => 'Phone Number',
    'mobile' => 'Phone Number',
    'college' => 'College',
    'department' => 'Department',
    'specialization' => 'Specialization',
    'major' => 'Specialization',
    'research_title' => 'Research Title',
    'research' => 'Research Title',
];

$fields = [];
$usedLabels = [];
foreach ($editableCandidates as $field => $label) {
    if (isset($columns[$field]) && !isset($usedLabels[$label])) {
        $fields[$field] = $label;
        $usedLabels[$label] = true;
    }
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    http_response_code(404);
    exit('User profile not found.');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $updates = [];
    $values = [];

    foreach ($fields as $field => $label) {
        $value = trim((string)($_POST[$field] ?? ''));
        if (in_array($field, ['name', 'full_name', 'email'], true) && $value === '') {
            $errors[] = $label . ' is required.';
        }
        if ($field === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        $updates[] = "`$field` = ?";
        $values[] = $value;
    }

    if (!$errors && $updates) {
        if (isset($fields['email'])) {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $check->execute([trim((string)$_POST['email']), $userId]);
            if ($check->fetch()) {
                $errors[] = 'This email address is already used by another account.';
            }
        }
    }

    if (!$errors && $updates) {
        $values[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute($values);

        $refresh = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $refresh->execute([$userId]);
        $updated = $refresh->fetch(PDO::FETCH_ASSOC);
        if ($updated) {
            $_SESSION['user'] = array_merge($_SESSION['user'], $updated);
        }

        audit($pdo, 'Update', 'Profile', 'Updated personal profile information');
        flash('success', 'Profile updated successfully.');
        header('Location: ' . BASE_URL . '/modules/profile/index.php');
        exit;
    }
}

require '../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-xl-8">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="mb-1">Edit Profile</h2>
                <p class="text-muted mb-0">Update your personal and academic information.</p>
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
                <?php if (!$fields): ?>
                    <div class="alert alert-warning mb-0">No editable profile columns were found in the users table.</div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <div class="row g-3">
                            <?php foreach ($fields as $field => $label): ?>
                                <div class="col-md-6">
                                    <label class="form-label"><?= e($label) ?></label>
                                    <?php if (in_array($field, ['research_title', 'research'], true)): ?>
                                        <textarea class="form-control" name="<?= e($field) ?>" rows="3"><?= e($_POST[$field] ?? $profile[$field] ?? '') ?></textarea>
                                    <?php else: ?>
                                        <input class="form-control" type="<?= $field === 'email' ? 'email' : 'text' ?>"
                                               name="<?= e($field) ?>" value="<?= e($_POST[$field] ?? $profile[$field] ?? '') ?>">
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/modules/profile/index.php">Cancel</a>
                            <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save Changes</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>
