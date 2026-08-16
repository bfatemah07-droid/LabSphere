<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$page_title = 'My Profile';
$userId = (int) user()['id'];

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile) {
    http_response_code(404);
    exit('User profile not found.');
}

function profile_value(array $row, array $keys, string $fallback = 'Not provided'): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }
    return $fallback;
}

require '../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h2 class="mb-1">My Profile</h2>
        <p class="text-muted mb-0">View and manage your LabSphere account information.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= BASE_URL ?>/modules/profile/edit.php">
            <i class="bi bi-pencil-square me-1"></i>Edit Profile
        </a>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/modules/profile/change_password.php">
            <i class="bi bi-shield-lock me-1"></i>Change Password
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-body text-center p-4">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                     style="width:96px;height:96px;background:#E7F6EE;color:#0A8A4B;font-size:42px;">
                    <i class="bi bi-person"></i>
                </div>
                <h4 class="mb-1"><?= e(profile_value($profile, ['name', 'full_name'])) ?></h4>
                <span class="badge bg-primary px-3 py-2"><?= e(profile_value($profile, ['role'])) ?></span>
                <hr>
                <div class="text-start small">
                    <div class="mb-2"><i class="bi bi-envelope me-2 text-primary"></i><?= e(profile_value($profile, ['email'])) ?></div>
                    <div><i class="bi bi-telephone me-2 text-primary"></i><?= e(profile_value($profile, ['phone', 'contact_number', 'mobile'])) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-vcard me-2"></i>Account Details</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <tbody>
                        <tr><th style="width:34%">Full Name</th><td><?= e(profile_value($profile, ['name', 'full_name'])) ?></td></tr>
                        <tr><th>Email Address</th><td><?= e(profile_value($profile, ['email'])) ?></td></tr>
                        <tr><th>Phone Number</th><td><?= e(profile_value($profile, ['phone', 'contact_number', 'mobile'])) ?></td></tr>
                        <tr><th>Role</th><td><?= e(profile_value($profile, ['role'])) ?></td></tr>
                        <tr><th>College</th><td><?= e(profile_value($profile, ['college'])) ?></td></tr>
                        <tr><th>Department</th><td><?= e(profile_value($profile, ['department'])) ?></td></tr>
                        <tr><th>Specialization</th><td><?= e(profile_value($profile, ['specialization', 'major'])) ?></td></tr>
                        <tr><th>Research Title</th><td><?= e(profile_value($profile, ['research_title', 'research'])) ?></td></tr>
                        <tr><th>Account Status</th><td><?= e(profile_value($profile, ['status'], 'Active')) ?></td></tr>
                        <tr><th>Member Since</th><td><?= e(profile_value($profile, ['created_at', 'created_on', 'date_created'])) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>
