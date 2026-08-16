<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$currentUser = user();
$isRegularUser = in_array($currentUser['role'] ?? '', ['User', 'Student'], true);

if (!$isRegularUser) {
    flash('danger', 'Only regular users can submit storage space reservation requests.');
    header('Location: index.php');
    exit;
}


function storage_unit_to_samples(string $unit): int
{
    return match ($unit) {
        'Sample', 'Sample(s)' => 1,
        'Box', 'Box(es)' => 100,
        'Rack', 'Rack(s)' => 1200,
        'Shelf', 'Shelf/Shelves' => 4800,

        // Legacy values kept for older reservations created before standardization.
        'Space(s)', 'Container(s)' => 1,
        default => 0,
    };
}

function storage_unit_label(string $unit, int $quantity): string
{
    return match ($unit) {
        'Sample' => $quantity === 1 ? 'Sample' : 'Samples',
        'Box' => $quantity === 1 ? 'Box' : 'Boxes',
        'Rack' => $quantity === 1 ? 'Rack' : 'Racks',
        'Shelf' => $quantity === 1 ? 'Shelf' : 'Shelves',
        default => $unit,
    };
}

$storageId = (int)($_GET['storage_id'] ?? $_POST['storage_id'] ?? 0);

$statement = $pdo->prepare(
    "SELECT ss.*, l.name AS laboratory_name
     FROM storage_spaces ss
     LEFT JOIN laboratories l ON l.id = ss.lab_id
     WHERE ss.id = ?
     LIMIT 1"
);
$statement->execute([$storageId]);
$space = $statement->fetch();

if (!$space) {
    flash('danger', 'Storage space not found.');
    header('Location: index.php');
    exit;
}

$availableCapacity = max(0, (int)$space['capacity'] - (int)$space['used_capacity']);
$canReserve = in_array($space['status'], ['Available', 'Partially Available'], true)
    && $availableCapacity > 0;

if (!$canReserve) {
    flash('danger', 'This storage space is not currently available.');
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $sampleType = trim((string)($_POST['sample_type'] ?? ''));
    $quantity = (int)($_POST['quantity'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? 'Sample'));
    $dateNeeded = trim((string)($_POST['date_needed'] ?? ''));
    $purpose = trim((string)($_POST['purpose'] ?? ''));

    $allowedUnits = ['Sample', 'Box', 'Rack', 'Shelf'];

    if ($sampleType === '') $errors[] = 'Please enter the sample type.';
    if ($quantity <= 0) $errors[] = 'Please enter a quantity greater than zero.';
    if (!in_array($unit, $allowedUnits, true)) $errors[] = 'Please select a valid unit.';

    $samplesPerUnit = storage_unit_to_samples($unit);
    $requestedSamples = $quantity > 0 && $samplesPerUnit > 0
        ? $quantity * $samplesPerUnit
        : 0;

    if ($requestedSamples > $availableCapacity) {
        $errors[] = 'The requested amount is equivalent to '
            . number_format($requestedSamples)
            . ' samples, which is greater than the available capacity of '
            . number_format($availableCapacity)
            . ' samples.';
    }

    $dateObject = DateTime::createFromFormat('Y-m-d', $dateNeeded);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $dateNeeded || $dateNeeded < date('Y-m-d')) {
        $errors[] = 'Please select a valid required date.';
    }

    if ($purpose === '') $errors[] = 'Please enter the purpose of use.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare(
                'SELECT capacity,used_capacity,status
                 FROM storage_spaces
                 WHERE id=?
                 FOR UPDATE'
            );
            $lock->execute([$storageId]);
            $currentSpace = $lock->fetch();

            $currentAvailable = $currentSpace
                ? max(0, (int)$currentSpace['capacity'] - (int)$currentSpace['used_capacity'])
                : 0;

            if (
                !$currentSpace
                || !in_array($currentSpace['status'], ['Available', 'Partially Available'], true)
                || $requestedSamples > $currentAvailable
            ) {
                throw new RuntimeException('The requested storage capacity is no longer available.');
            }

            // Prevent an accidental duplicate pending request for the same user, space and date.
            $duplicateRequest = $pdo->prepare(
                "SELECT id
                 FROM reservations
                 WHERE user_id = ?
                   AND type = 'Storage Space'
                   AND item_id = ?
                   AND date_needed = ?
                   AND status = 'Pending'
                 LIMIT 1"
            );
            $duplicateRequest->execute([(int)$currentUser['id'], $storageId, $dateNeeded]);

            if ($duplicateRequest->fetch()) {
                throw new RuntimeException(
                    'You already have a pending request for this storage space on the selected date.'
                );
            }

            $reservationGroup = 'GRP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $insert = $pdo->prepare(
                'INSERT INTO reservations
                    (reservation_group,user_id,type,item_id,laboratory_id,sample_type,quantity,unit,time_slot,date_needed,purpose,status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            $insert->execute([
                $reservationGroup,
                (int)$currentUser['id'],
                'Storage Space',
                $storageId,
                null,
                $sampleType,
                $quantity,
                $unit,
                null,
                $dateNeeded,
                $purpose,
                'Pending'
            ]);

            $reservationId = (int)$pdo->lastInsertId();
            $requestCode = 'RQ-' . str_pad((string)$reservationId, 4, '0', STR_PAD_LEFT);

            $recipients = $pdo->query(
                "SELECT id FROM users WHERE role IN ('Admin','Supervisor')"
            )->fetchAll(PDO::FETCH_COLUMN);

            $notify = $pdo->prepare(
                'INSERT INTO notifications (user_id,title,message,type,is_read)
                 VALUES (?,?,?,?,0)'
            );

            foreach ($recipients as $recipientId) {
                $notify->execute([
                    (int)$recipientId,
                    'New Storage Space Reservation',
                    $currentUser['name'] . ' submitted ' . $requestCode . ' for ' . $space['name'] . '.',
                    'reservation'
                ]);
            }

            audit($pdo, 'CREATE', 'Reservations', $requestCode . ' - Storage Space #' . $storageId);
            $pdo->commit();

            flash('success', 'Storage space reservation submitted successfully.');
            header('Location: ../reservations/index.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $exception->getMessage();
        }
    }
}

$page_title = 'Reserve Storage Space';
require '../../includes/header.php';
?>

<style>
.storage-reserve-wrap{max-width:780px;margin:0 auto}.storage-reserve-card{border:1px solid #dfe8e3;border-radius:16px;overflow:hidden}.storage-summary{padding:1rem;border:1px solid #dce8e2;border-radius:12px;background:#f8fbf9}.capacity-pill{display:inline-flex;padding:.38rem .7rem;border-radius:999px;background:#e7f7ef;color:#078c4f;font-size:.82rem;font-weight:700}
</style>

<div class="storage-reserve-wrap">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h2 class="mb-1">Reserve Storage Space</h2>
            <p class="text-muted mb-0">Submit a storage request for samples or laboratory materials.</p>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-danger"><?php foreach($errors as $error): ?><div><?= e($error) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card storage-reserve-card">
        <div class="card-body p-4">
            <div class="storage-summary mb-4">
                <div class="small text-muted">Selected Storage Space</div>
                <div class="fw-bold fs-5"><?= e($space['name']) ?></div>
                <div class="small text-muted mt-1"><?= e($space['laboratory_name'] ?: 'Not assigned') ?> · <?= e($space['type']) ?></div>
                <div class="capacity-pill mt-2"><?= number_format($availableCapacity) ?> samples available</div>
            </div>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="storage_id" value="<?= (int)$storageId ?>">

                <div class="mb-3">
                    <label class="form-label">Sample Type</label>
                    <input class="form-control" name="sample_type" value="<?= e($_POST['sample_type'] ?? '') ?>" placeholder="e.g. Blood serum, soil sample" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Quantity</label>
                        <input
                            class="form-control"
                            type="number"
                            name="quantity"
                            id="storageQuantity"
                            min="1"
                            value="<?= e($_POST['quantity'] ?? 1) ?>"
                            required
                        >
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit" id="storageUnit" required>
                            <?php foreach([
                                'Sample' => 'Sample',
                                'Box' => 'Box (100 samples)',
                                'Rack' => 'Rack (1,200 samples / 12 boxes)',
                                'Shelf' => 'Shelf (4,800 samples / 4 racks)',
                            ] as $unitValue => $unitLabel): ?>
                                <option
                                    value="<?= e($unitValue) ?>"
                                    <?= ($_POST['unit'] ?? 'Sample') === $unitValue ? 'selected' : '' ?>
                                >
                                    <?= e($unitLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-light border mb-0 py-2">
                            <div class="small text-muted">Standard conversion</div>
                            <div class="small">
                                1 Box = 100 Samples · 1 Rack = 12 Boxes = 1,200 Samples · 1 Shelf = 4 Racks = 4,800 Samples
                            </div>
                            <div class="fw-semibold mt-1" id="storageEquivalent"></div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Date Needed</label>
                    <input class="form-control" type="date" name="date_needed" min="<?= date('Y-m-d') ?>" value="<?= e($_POST['date_needed'] ?? '') ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label">Purpose of Use</label>
                    <textarea class="form-control" name="purpose" rows="4" placeholder="e.g. Storing enzyme samples for an ongoing experiment" required><?= e($_POST['purpose'] ?? '') ?></textarea>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="index.php" class="btn btn-light">Cancel</a>
                    <button class="btn btn-success"><i class="bi bi-send me-1"></i>Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const quantityInput = document.getElementById('storageQuantity');
    const unitSelect = document.getElementById('storageUnit');
    const equivalent = document.getElementById('storageEquivalent');

    if (!quantityInput || !unitSelect || !equivalent) return;

    const samplesPerUnit = {
        Sample: 1,
        Box: 100,
        Rack: 1200,
        Shelf: 4800
    };

    function updateEquivalent() {
        const quantity = Math.max(0, parseInt(quantityInput.value || '0', 10));
        const factor = samplesPerUnit[unitSelect.value] || 0;
        const samples = quantity * factor;
        equivalent.textContent = samples.toLocaleString() + ' sample' + (samples === 1 ? '' : 's') + ' of storage capacity';
    }

    quantityInput.addEventListener('input', updateEquivalent);
    unitSelect.addEventListener('change', updateEquivalent);
    updateEquivalent();
});
</script>

<?php require '../../includes/footer.php'; ?>