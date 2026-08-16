<?php

require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$storageId = (int)($_GET['id'] ?? 0);

if ($storageId <= 0) {
    flash('danger', 'Invalid storage space.');
    header('Location: index.php');
    exit;
}

$statement = $pdo->prepare(
    'SELECT
        ss.*,
        l.name AS laboratory_name,
        tl.temperature AS last_temperature,
        tl.note AS last_temperature_note,
        tl.signed_by AS last_temperature_signed_by,
        tl.logged_at AS last_temperature_at
     FROM storage_spaces ss
     LEFT JOIN laboratories l
        ON l.id = ss.lab_id
     LEFT JOIN temperature_logs tl
        ON tl.id = (
            SELECT MAX(t2.id)
            FROM temperature_logs t2
            WHERE t2.storage_name = ss.name
        )
     WHERE ss.id = ?
     LIMIT 1'
);

$statement->execute([$storageId]);
$space = $statement->fetch();

if (!$space) {
    flash('danger', 'Storage space not found.');
    header('Location: index.php');
    exit;
}

function storage_view_status_class(string $status): string
{
    return match ($status) {
        'Available' => 'storage-detail-status-available',
        'Partially Available' => 'storage-detail-status-partial',
        'Full' => 'storage-detail-status-full',
        default => 'storage-detail-status-maintenance',
    };
}

function storage_view_icon(string $type): string
{
    return match ($type) {
        'Refrigerator' => 'bi-snow',
        'Freezer' => 'bi-snow2',
        'Cabinet' => 'bi-archive',
        'Shelf' => 'bi-layers',
        'Storage Room' => 'bi-building',
        'Drawer' => 'bi-inbox',
        default => 'bi-box-seam',
    };
}

function storage_view_code(int $id): string
{
    return 'ST-' . str_pad((string)(400 + $id), 3, '0', STR_PAD_LEFT);
}

$capacity = (int)$space['capacity'];
$usedCapacity = (int)$space['used_capacity'];
$availableCapacity = max(0, $capacity - $usedCapacity);
$usagePercent = $capacity > 0 ? min(100, round(($usedCapacity / $capacity) * 100)) : 0;

$isRegularUser = in_array(user()['role'] ?? '', ['User', 'Student'], true);

$canReserve = $isRegularUser
    && in_array($space['status'], ['Available', 'Partially Available'], true)
    && $availableCapacity > 0;

if ($space['temp_min'] !== null && $space['temp_max'] !== null) {
    $temperatureRange = ((float)$space['temp_min'] === (float)$space['temp_max'])
        ? $space['temp_min'] . '°C'
        : $space['temp_min'] . '–' . $space['temp_max'] . '°C';
} else {
    $temperatureRange = 'Room temperature';
}

$isColdStorage = in_array($space['type'], ['Refrigerator', 'Freezer'], true);
$latestTemperatureOutOfRange = false;
if ($isColdStorage && $space['last_temperature'] !== null) {
    $latest = (float)$space['last_temperature'];
    $latestTemperatureOutOfRange = (
        $space['temp_min'] !== null && $latest < (float)$space['temp_min']
    ) || (
        $space['temp_max'] !== null && $latest > (float)$space['temp_max']
    );
}

$temperatureHistoryStatement = $pdo->prepare(
    'SELECT temperature, note, signed_by, logged_at
     FROM temperature_logs
     WHERE storage_name = ?
     ORDER BY id DESC
     LIMIT 20'
);
$temperatureHistoryStatement->execute([$space['name']]);
$temperatureHistory = $temperatureHistoryStatement->fetchAll();

$page_title = 'Storage Space Details';
require '../../includes/header.php';
?>

<style>
.storage-details-wrap{max-width:1050px;margin:0 auto}.storage-details-card{border:1px solid #dfe8e3;border-radius:18px;overflow:hidden}.storage-details-hero{padding:1.6rem;background:linear-gradient(135deg,#0d9b61,#087c4e);color:#fff}.storage-details-icon{width:70px;height:70px;border-radius:18px;display:inline-flex;align-items:center;justify-content:center;background:rgba(255,255,255,.16);font-size:2.2rem}.storage-detail-status{display:inline-flex;align-items:center;gap:.4rem;padding:.44rem .75rem;border-radius:999px;font-size:.8rem;font-weight:700;white-space:nowrap}.storage-detail-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.storage-detail-status-available{color:#078c4f;background:#e7f7ef}.storage-detail-status-partial{color:#0b76a8;background:#e8f5fb}.storage-detail-status-full{color:#d92d20;background:#feeceb}.storage-detail-status-maintenance{color:#b46900;background:#fff3d8}.storage-info-box{height:100%;padding:1rem;border:1px solid #e2eae6;border-radius:13px;background:#f9fbfa}.storage-info-label{color:#65758b;font-size:.75rem;font-weight:700;letter-spacing:.035em;text-transform:uppercase}.storage-info-value{margin-top:.35rem;color:#172033;font-weight:700}.storage-capacity-card{padding:1rem;border:1px solid #e2eae6;border-radius:13px}.storage-progress{height:10px;border-radius:999px;background:#e8efeb;overflow:hidden}.storage-progress-bar{height:100%;border-radius:999px;background:#159455}.storage-note{padding:1rem;border-radius:13px;background:#f8fafc;border:1px solid #e5ebef}
</style>

<div class="storage-details-wrap">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>

            <div>
                <h2 class="mb-1">Storage Space Details</h2>
                <p class="text-muted mb-0">Review capacity, location, temperature and availability.</p>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if (in_array(user()['role'], ['Admin','Supervisor'], true)): ?>
                <a href="index.php?edit=<?= (int)$space['id'] ?>" class="btn btn-outline-success"><i class="bi bi-pencil me-1"></i>Edit</a>
                <?php if (in_array($space['type'], ['Refrigerator','Freezer'], true)): ?>
                    <a href="index.php?temperature=<?= (int)$space['id'] ?>" class="btn btn-outline-primary"><i class="bi bi-thermometer-half me-1"></i>Log Temperature</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canReserve): ?>
            <a href="reserve.php?storage_id=<?= (int)$space['id'] ?>" class="btn btn-success">
                <i class="bi bi-calendar-plus me-1"></i>
                Reserve Storage Space
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card storage-details-card">
        <div class="storage-details-hero">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="storage-details-icon">
                        <i class="bi <?= e(storage_view_icon($space['type'])) ?>"></i>
                    </div>

                    <div>
                        <div class="small opacity-75"><?= e(storage_view_code((int)$space['id'])) ?> · <?= e($space['type']) ?></div>
                        <h3 class="mb-1 mt-1"><?= e($space['name']) ?></h3>
                        <div class="opacity-75">
                            <i class="bi bi-geo-alt me-1"></i>
                            <?= e($space['laboratory_name'] ?: 'Not assigned') ?>
                        </div>
                    </div>
                </div>

                <span class="storage-detail-status <?= e(storage_view_status_class($space['status'])) ?>">
                    <?= e($space['status']) ?>
                </span>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="row g-3 mb-4">
                <div class="col-md-6 col-xl-3">
                    <div class="storage-info-box">
                        <div class="storage-info-label">Total Capacity</div>
                        <div class="storage-info-value"><?= number_format($capacity) ?> samples</div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="storage-info-box">
                        <div class="storage-info-label">Used Capacity</div>
                        <div class="storage-info-value"><?= number_format($usedCapacity) ?> samples</div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="storage-info-box">
                        <div class="storage-info-label">Available Capacity</div>
                        <div class="storage-info-value"><?= number_format($availableCapacity) ?> samples</div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="storage-info-box">
                        <div class="storage-info-label">Temperature Range</div>
                        <div class="storage-info-value"><?= e($temperatureRange) ?></div>
                    </div>
                </div>
            </div>

            <div class="storage-capacity-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-semibold">Capacity Usage</div>
                    <div class="text-muted small"><?= $usagePercent ?>%</div>
                </div>

                <div class="storage-progress">
                    <div class="storage-progress-bar" style="width:<?= $usagePercent ?>%"></div>
                </div>

                <div class="small text-muted mt-2">
                    <?= number_format($usedCapacity) ?> of <?= number_format($capacity) ?> samples of storage capacity are currently in use.
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <h5 class="mb-3">Description</h5>
                    <div class="storage-note">
                        <?= nl2br(e($space['description'] ?: 'No description has been added for this storage space.')) ?>
                    </div>
                </div>

                <div class="col-lg-5">
                    <h5 class="mb-3">Latest Temperature Reading</h5>

                    <div class="storage-note">
                        <?php if ($space['last_temperature'] !== null): ?>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <div class="fs-4 fw-bold"><?= e($space['last_temperature']) ?>°C</div>
                                <?php if ($isColdStorage): ?>
                                    <span class="badge <?= $latestTemperatureOutOfRange ? 'text-bg-danger' : 'text-bg-success' ?>">
                                        <?= $latestTemperatureOutOfRange ? 'Out of Range' : 'Within Range' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-muted mb-2">
                                <?= e(date('Y-m-d H:i', strtotime($space['last_temperature_at']))) ?>
                            </div>

                            <?php if (!empty($space['last_temperature_note'])): ?>
                                <div><?= e($space['last_temperature_note']) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($space['last_temperature_signed_by'])): ?>
                                <div class="small text-muted mt-2">
                                    Signed by <?= e($space['last_temperature_signed_by']) ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted">No temperature reading has been recorded yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($isColdStorage): ?>
            <div class="mt-4 pt-4 border-top">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Temperature History</h5>
                    <span class="small text-muted">Latest 20 readings</span>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Date</th><th>Temperature</th><th>Note</th><th>Signed by</th></tr></thead>
                        <tbody>
                            <?php if (!$temperatureHistory): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No temperature readings found.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($temperatureHistory as $reading): ?>
                                <tr>
                                    <td><?= e(date('Y-m-d H:i', strtotime($reading['logged_at']))) ?></td>
                                    <td class="fw-bold"><?= e($reading['temperature']) ?>°C</td>
                                    <td><?= e($reading['note'] ?: '—') ?></td>
                                    <td><?= e($reading['signed_by'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap justify-content-end gap-2 mt-4 pt-4 border-top">
                <a href="index.php" class="btn btn-light">Back</a>

                <?php if ($canReserve): ?>
                    <a href="reserve.php?storage_id=<?= (int)$space['id'] ?>" class="btn btn-success">
                        <i class="bi bi-calendar-plus me-1"></i>
                        Reserve
                    </a>
                <?php elseif ($isRegularUser): ?>
                    <button type="button" class="btn btn-secondary" disabled>Not Available</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>