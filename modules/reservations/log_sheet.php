<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();


function storage_log_quantity_in_samples(float $quantity, ?string $unit): int
{
    $factor = match ((string)$unit) {
        'Sample', 'Sample(s)' => 1,
        'Box', 'Box(es)' => 100,
        'Rack', 'Rack(s)' => 1200,
        'Shelf', 'Shelf/Shelves' => 4800,

        // Legacy values from requests created before the standardized unit model.
        'Space(s)', 'Container(s)', '' => 1,
        default => 0,
    };

    return $factor > 0
        ? max(0, (int)round($quantity * $factor))
        : 0;
}

$reservationId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($reservationId <= 0) {
    flash('danger', 'Reservation not found.');
    header('Location: index.php');
    exit;
}

$sql = "
    SELECT
        r.*,
        u.name AS user_name,
        CASE
            WHEN r.type = 'Equipment' THEN e.name
            WHEN r.type = 'Material' THEN m.name
            WHEN r.type = 'Laboratory' THEN l.name
            WHEN r.type = 'Storage Space' THEN ss.name
            ELSE NULL
        END AS item_name,
        CASE
            WHEN r.type = 'Equipment' THEN e.serial_number
            ELSE NULL
        END AS serial_number,
        CASE
            WHEN r.type = 'Equipment' THEN el.name
            WHEN r.type = 'Material' THEN ml.name
            WHEN r.type = 'Laboratory' THEN l.name
            WHEN r.type = 'Storage Space' THEN sl.name
            ELSE NULL
        END AS laboratory_name
    FROM reservations r
    JOIN users u
        ON u.id = r.user_id
    LEFT JOIN equipment e
        ON r.type = 'Equipment'
       AND e.id = r.item_id
    LEFT JOIN laboratories el
        ON el.id = e.lab_id
    LEFT JOIN materials m
        ON r.type = 'Material'
       AND m.id = r.item_id
    LEFT JOIN laboratories ml
        ON ml.id = m.lab_id
    LEFT JOIN laboratories l
        ON r.type = 'Laboratory'
       AND l.id = r.laboratory_id
    LEFT JOIN storage_spaces ss
        ON r.type = 'Storage Space'
       AND ss.id = r.item_id
    LEFT JOIN laboratories sl
        ON sl.id = ss.lab_id
    WHERE r.id = ?
    LIMIT 1
";

$statement = $pdo->prepare($sql);
$statement->execute([$reservationId]);
$reservation = $statement->fetch();

if (!$reservation) {
    flash('danger', 'Reservation not found.');
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get Item-Specific Instructions
|--------------------------------------------------------------------------
*/

$instructionTitle = 'Usage instructions';
$instructionText = '';
$safetyText = '';

$itemTable = null;
$itemId = null;

if ($reservation['type'] === 'Equipment') {
    $itemTable = 'equipment';
    $itemId = (int) $reservation['item_id'];
    $instructionTitle = 'How to use this equipment';
} elseif ($reservation['type'] === 'Material') {
    $itemTable = 'materials';
    $itemId = (int) $reservation['item_id'];
    $instructionTitle = 'How to handle this material';
} elseif ($reservation['type'] === 'Laboratory') {
    $itemTable = 'laboratories';
    $itemId = (int) $reservation['laboratory_id'];
    $instructionTitle = 'Laboratory usage instructions';
}

if ($itemTable && $itemId > 0) {

    $columnStatement = $pdo->query(
        "SHOW COLUMNS FROM {$itemTable}"
    );

    $availableColumns = array_column(
        $columnStatement->fetchAll(),
        'Field'
    );

    $selectColumns = [];

    if (in_array('usage_instructions', $availableColumns, true)) {
        $selectColumns[] = 'usage_instructions';
    }

    if (in_array('safety_guidelines', $availableColumns, true)) {
        $selectColumns[] = 'safety_guidelines';
    }

    if (!empty($selectColumns)) {

        $guideStatement = $pdo->prepare(
            'SELECT ' . implode(', ', $selectColumns) . '
             FROM ' . $itemTable . '
             WHERE id = ?
             LIMIT 1'
        );

        $guideStatement->execute([$itemId]);
        $guide = $guideStatement->fetch() ?: [];

        $instructionText = trim(
            (string) ($guide['usage_instructions'] ?? '')
        );

        $safetyText = trim(
            (string) ($guide['safety_guidelines'] ?? '')
        );
    }
}

$isOwner = (int) $reservation['user_id'] === (int) user()['id'];
$isManager = in_array(
    user()['role'],
    ['Supervisor', 'Admin'],
    true
);

if (!$isOwner && !$isManager) {
    flash('danger', 'You do not have permission to view this log sheet.');
    header('Location: index.php');
    exit;
}

if (!in_array(
    $reservation['status'],
    ['Approved', 'In Use', 'Completed'],
    true
)) {
    flash(
        'danger',
        'The log sheet is available only after approval.'
    );
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = trim($_POST['action'] ?? '');

    if (!$isOwner && !$isManager) {
        flash('danger', 'You do not have permission for this action.');
        header('Location: index.php');
        exit;
    }

    if ($action === 'sign') {

        $signatureName = trim($_POST['signature_name'] ?? '');
        $accepted = isset($_POST['accepted']);

        if ($reservation['signed_at']) {
            flash('danger', 'This log sheet has already been signed.');
        } elseif ($signatureName === '') {
            flash('danger', 'Please enter your full name.');
        } elseif (!$accepted) {
            flash(
                'danger',
                'Please confirm that you read the safety rules.'
            );
        } else {

            $statement = $pdo->prepare(
                'UPDATE reservations
                 SET signed_by = ?,
                     signed_at = NOW()
                 WHERE id = ?'
            );

            $statement->execute([
                $signatureName,
                $reservationId
            ]);

            audit(
                $pdo,
                'SIGN',
                'Reservation Log Sheet',
                'Reservation ID: ' . $reservationId
            );

            flash('success', 'Log sheet signed successfully.');
        }

    } elseif ($action === 'check_in') {

        if (!$reservation['signed_at']) {
            flash('danger', 'Sign the log sheet before check-in.');
        } elseif ($reservation['checked_in_at']) {
            flash('danger', 'This reservation is already checked in.');
        } else {

            $statement = $pdo->prepare(
                "UPDATE reservations
                 SET checked_in_at = NOW(),
                     status = 'In Use'
                 WHERE id = ?"
            );

            $statement->execute([$reservationId]);

            audit(
                $pdo,
                'CHECK_IN',
                'Reservations',
                'Reservation ID: ' . $reservationId
            );

            flash('success', 'Check-in recorded successfully.');
        }

    } elseif ($action === 'check_out') {

        if (!$reservation['checked_in_at']) {
            flash('danger', 'Check in before checking out.');
        } elseif ($reservation['checked_out_at']) {
            flash('danger', 'This reservation is already completed.');
        } else {

            try {
                $pdo->beginTransaction();

                // Lock the reservation so checkout/capacity release can happen only once.
                $lockStatement = $pdo->prepare(
                    'SELECT id, type, item_id, quantity, unit, checked_in_at, checked_out_at
                     FROM reservations
                     WHERE id = ?
                     FOR UPDATE'
                );
                $lockStatement->execute([$reservationId]);
                $lockedReservation = $lockStatement->fetch();

                if (!$lockedReservation) {
                    throw new RuntimeException('Reservation not found.');
                }

                if (!$lockedReservation['checked_in_at']) {
                    throw new RuntimeException('Check in before checking out.');
                }

                if ($lockedReservation['checked_out_at']) {
                    throw new RuntimeException('This reservation is already completed.');
                }

                // Storage capacity is occupied when the request is approved.
                // Release the same quantity when actual use is completed.
                if ($lockedReservation['type'] === 'Storage Space') {
                    $storageStatement = $pdo->prepare(
                        'SELECT id, capacity, used_capacity, status
                         FROM storage_spaces
                         WHERE id = ?
                         FOR UPDATE'
                    );
                    $storageStatement->execute([(int) $lockedReservation['item_id']]);
                    $storage = $storageStatement->fetch();

                    if (!$storage) {
                        throw new RuntimeException('The reserved storage space no longer exists.');
                    }

                    $releasedQuantity = storage_log_quantity_in_samples(
                        (float)$lockedReservation['quantity'],
                        $lockedReservation['unit'] ?? null
                    );
                    $newUsed = max(0, (int) $storage['used_capacity'] - $releasedQuantity);

                    // Keep maintenance status until a manager changes it manually.
                    if ($storage['status'] === 'Under Maintenance') {
                        $newStatus = 'Under Maintenance';
                    } elseif ($newUsed <= 0) {
                        $newStatus = 'Available';
                    } elseif ($newUsed >= (int) $storage['capacity']) {
                        $newStatus = 'Full';
                    } else {
                        $newStatus = 'Partially Available';
                    }

                    $storageUpdate = $pdo->prepare(
                        'UPDATE storage_spaces
                         SET used_capacity = ?, status = ?
                         WHERE id = ?'
                    );
                    $storageUpdate->execute([
                        $newUsed,
                        $newStatus,
                        (int) $lockedReservation['item_id']
                    ]);
                }

                $statement = $pdo->prepare(
                    "UPDATE reservations
                     SET checked_out_at = NOW(),
                         status = 'Completed'
                     WHERE id = ?
                       AND checked_out_at IS NULL"
                );
                $statement->execute([$reservationId]);

                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('This reservation is already completed.');
                }

                audit(
                    $pdo,
                    'CHECK_OUT',
                    'Reservations',
                    'Reservation ID: ' . $reservationId
                );

                $pdo->commit();
                flash('success', 'Check-out recorded successfully.');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                flash('danger', $exception->getMessage());
            }
        }
    }

    header(
        'Location: log_sheet.php?id=' . $reservationId
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Fallback Instructions
|--------------------------------------------------------------------------
*/

if ($instructionText === '') {

    if ($reservation['type'] === 'Equipment') {
        $instructionText =
            'Inspect the equipment before use and follow the approved '
            . 'operating procedure.';
    } elseif ($reservation['type'] === 'Material') {
        $instructionText =
            'Use only the approved quantity and follow the correct handling '
            . 'and storage procedure.';
    } elseif ($reservation['type'] === 'Laboratory') {
        $instructionText =
            'Use the laboratory only during the approved period and keep '
            . 'the work area organized.';
    } elseif ($reservation['type'] === 'Storage Space') {
        $instructionText =
            'Use only the approved storage capacity and keep samples properly '
            . 'labeled and within the assigned storage area.';
    } else {
        $instructionText =
            'Follow all approved usage instructions for this reservation.';
    }
}

if ($safetyText === '') {

    if ($reservation['type'] === 'Equipment') {
        $safetyText =
            'Use the required personal protective equipment and report any '
            . 'malfunction immediately.';
    } elseif ($reservation['type'] === 'Material') {
        $safetyText =
            'Wear the required personal protective equipment and follow the '
            . 'laboratory disposal procedure.';
    } elseif ($reservation['type'] === 'Laboratory') {
        $safetyText =
            'Follow emergency procedures and report spills, injuries or '
            . 'damaged equipment immediately.';
    } elseif ($reservation['type'] === 'Storage Space') {
        $safetyText =
            'Follow storage temperature and labeling requirements, use required '
            . 'PPE, and report spills or temperature problems immediately.';
    } else {
        $safetyText =
            'Follow all laboratory safety procedures during use.';
    }
}

$page_title = 'Log Sheet';

require '../../includes/header.php';

?>

<style>
    .log-sheet-wrapper {
        max-width: 760px;
        margin: 0 auto;
    }

    .log-sheet-card {
        border: 1px solid #e2e7ee;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.1);
    }

    .log-sheet-header,
    .log-sheet-footer {
        padding: 18px 22px;
        border-color: #e6ebf1;
    }

    .log-sheet-body {
        padding: 22px;
    }

    .detail-label {
        color: #64748b;
        font-size: 0.76rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 3px;
    }

    .detail-value {
        color: #172033;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .info-panel {
        display: flex;
        align-items: flex-start;
        gap: 11px;
        padding: 14px 16px;
        border-radius: 13px;
        line-height: 1.55;
    }

    .instruction-panel {
        color: #075985;
        background: #e7f5ff;
    }

    .safety-panel,
    .responsibility-panel {
        color: #9a4b00;
        background: #fff4dd;
    }

    .signed-panel {
        color: #075985;
        background: #e7f5ff;
    }

    .timeline {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 8px;
    }

    .timeline-step {
        text-align: center;
        font-size: 0.74rem;
        color: #94a3b8;
    }

    .timeline-dot {
        width: 24px;
        height: 24px;
        margin: 0 auto 6px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #e8edf3;
        color: #94a3b8;
    }

    .timeline-step.done {
        color: #087a55;
        font-weight: 700;
    }

    .timeline-step.done .timeline-dot {
        color: #fff;
        background: #16a36a;
    }

    @media (max-width: 575.98px) {
        .timeline {
            grid-template-columns: 1fr;
        }

        .timeline-step {
            display: flex;
            align-items: center;
            gap: 8px;
            text-align: left;
        }

        .timeline-dot {
            margin: 0;
            flex: 0 0 24px;
        }
    }
</style>

<div class="log-sheet-wrapper">

    <div class="d-flex align-items-center gap-3 mb-3">

        <a
            href="index.php"
            class="btn btn-outline-secondary"
            aria-label="Back"
        >
            <i class="bi bi-arrow-left"></i>
        </a>

        <div>
            <h2 class="mb-1">Reservation Log Sheet</h2>

            <p class="text-muted mb-0">
                Review safety instructions, sign and record actual usage.
            </p>
        </div>

    </div>

    <div class="card log-sheet-card">

        <div class="log-sheet-header d-flex justify-content-between align-items-start">

            <div>
                <h5 class="mb-1">
                    <?= e(
                        $reservation['item_name']
                        ?? 'Reservation Item'
                    ) ?>
                </h5>

                <div class="small text-muted">
                    RQ-<?= str_pad(
                        (string) $reservation['id'],
                        4,
                        '0',
                        STR_PAD_LEFT
                    ) ?>
                </div>
            </div>

            <span class="badge text-bg-primary">
                <?= e($reservation['status']) ?>
            </span>

        </div>

        <div class="log-sheet-body">

            <div class="row g-3 mb-4">

                <div class="col-sm-6">
                    <div class="detail-label">Reserved By</div>
                    <div class="detail-value">
                        <?= e($reservation['user_name']) ?>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="detail-label">Type</div>
                    <div class="detail-value">
                        <?= e($reservation['type']) ?>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="detail-label">Date Needed</div>
                    <div class="detail-value">
                        <?= e($reservation['date_needed']) ?>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="detail-label">Time Slot</div>
                    <div class="detail-value">
                        <?= e(
                            $reservation['time_slot']
                            ?: 'Not specified'
                        ) ?>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="detail-label">Quantity</div>
                    <div class="detail-value">
                        <?= e($reservation['quantity']) ?>
                        <?= e($reservation['unit']) ?>
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="detail-label">Laboratory</div>
                    <div class="detail-value">
                        <?= e(
                            $reservation['laboratory_name']
                            ?: 'Not assigned'
                        ) ?>
                    </div>
                </div>

            </div>

            <div class="info-panel instruction-panel mb-3">
                <i class="bi bi-info-circle-fill mt-1"></i>

                <div>
                    <strong><?= e($instructionTitle) ?>.</strong>
                    <?= e($instructionText) ?>
                </div>
            </div>

            <div class="info-panel safety-panel mb-3">
                <i class="bi bi-shield-fill-exclamation mt-1"></i>

                <div>
                    <strong>Safety guidelines.</strong>
                    <?= e($safetyText) ?>
                </div>
            </div>

            <div class="info-panel responsibility-panel mb-4">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>

                <div>
                    Read the safety rules and usage instructions before
                    signing. By signing, you accept responsibility for
                    correct and safe use.
                </div>
            </div>

            <?php if (!$reservation['signed_at']): ?>

                <form method="post">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?= csrf_token() ?>"
                    >

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int) $reservation['id'] ?>"
                    >

                    <div class="mb-3">

                        <label
                            for="signature_name"
                            class="form-label fw-semibold"
                        >
                            Type your full name to sign
                        </label>

                        <input
                            type="text"
                            id="signature_name"
                            name="signature_name"
                            class="form-control"
                            value="<?= e(user()['name']) ?>"
                            required
                        >

                    </div>

                    <div class="form-check mb-3">

                        <input
                            type="checkbox"
                            id="accepted"
                            name="accepted"
                            class="form-check-input"
                            required
                        >

                        <label
                            for="accepted"
                            class="form-check-label"
                        >
                            I have read the safety rules and usage instructions.
                        </label>

                    </div>

                    <button
                        type="submit"
                        name="action"
                        value="sign"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-pen me-1"></i>
                        Sign Log Sheet
                    </button>

                </form>

            <?php else: ?>

                <div class="info-panel signed-panel mb-4">
                    <i class="bi bi-signature mt-1"></i>

                    <div>
                        Signed by
                        <strong>
                            <?= e($reservation['signed_by']) ?>
                        </strong>
                        on
                        <?= e($reservation['signed_at']) ?>.
                    </div>
                </div>

                <div class="mb-4">

                    <div class="fw-semibold mb-2">
                        Actual usage
                    </div>

                    <?php if (!$reservation['checked_in_at']): ?>

                        <form method="post">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= csrf_token() ?>"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int) $reservation['id'] ?>"
                            >

                            <button
                                type="submit"
                                name="action"
                                value="check_in"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-box-arrow-in-right me-1"></i>
                                Check-in
                            </button>

                        </form>

                    <?php elseif (!$reservation['checked_out_at']): ?>

                        <div class="small text-muted mb-2">
                            Checked in at
                            <?= e($reservation['checked_in_at']) ?>
                        </div>

                        <form method="post">

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?= csrf_token() ?>"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= (int) $reservation['id'] ?>"
                            >

                            <button
                                type="submit"
                                name="action"
                                value="check_out"
                                class="btn btn-success"
                            >
                                <i class="bi bi-box-arrow-right me-1"></i>
                                Check-out
                            </button>

                        </form>

                    <?php else: ?>

                        <span class="badge text-bg-success">
                            <i class="bi bi-check-circle me-1"></i>
                            Completed — usage logged
                        </span>

                        <div class="small text-muted mt-2">
                            Checked in:
                            <?= e($reservation['checked_in_at']) ?>
                            <br>
                            Checked out:
                            <?= e($reservation['checked_out_at']) ?>
                        </div>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

            <?php
            $steps = [
                [
                    'label' => 'Approved',
                    'done' => true
                ],
                [
                    'label' => 'Signed',
                    'done' => !empty($reservation['signed_at'])
                ],
                [
                    'label' => 'Checked In',
                    'done' => !empty($reservation['checked_in_at'])
                ],
                [
                    'label' => 'Checked Out',
                    'done' => !empty($reservation['checked_out_at'])
                ],
                [
                    'label' => 'Completed',
                    'done' => $reservation['status'] === 'Completed'
                ]
            ];
            ?>

            <div class="border-top pt-4">

                <div class="timeline">

                    <?php foreach ($steps as $step): ?>

                        <div class="timeline-step <?= $step['done'] ? 'done' : '' ?>">

                            <div class="timeline-dot">
                                <i class="bi bi-check-lg"></i>
                            </div>

                            <?= e($step['label']) ?>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>

        <div class="log-sheet-footer d-flex justify-content-end">

            <a href="index.php" class="btn btn-light">
                Close
            </a>

        </div>

    </div>

</div>

<?php require '../../includes/footer.php'; ?>