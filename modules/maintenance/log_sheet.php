<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_role(['Supervisor', 'Admin']);

$currentUser = user();
$equipmentId = (int) ($_GET['equipment_id'] ?? $_POST['equipment_id'] ?? 0);

if ($equipmentId <= 0) {
    flash('danger', 'Invalid equipment.');
    header('Location: index.php?tab=acknowledgement');
    exit;
}

/*
|--------------------------------------------------------------------------
| Equipment Information
|--------------------------------------------------------------------------
*/

$statement = $pdo->prepare(
    'SELECT
        equipment.*,
        laboratories.name AS laboratory_name
     FROM equipment
     LEFT JOIN laboratories
        ON laboratories.id = equipment.lab_id
     WHERE equipment.id = ?
     LIMIT 1'
);

$statement->execute([$equipmentId]);
$equipment = $statement->fetch();

if (!$equipment) {
    flash('danger', 'Equipment not found.');
    header('Location: index.php?tab=acknowledgement');
    exit;
}

/*
|--------------------------------------------------------------------------
| Save Six-Month Acknowledgement
|--------------------------------------------------------------------------
*/

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $confirmed = ($_POST['confirmed'] ?? '') === '1';
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!$confirmed) {
        $errors[] = 'Please confirm that the equipment has been inspected.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $confirmedAt = new DateTimeImmutable();
            $nextDueDate = $confirmedAt->modify('+6 months');

            /*
             * Prevent accidental duplicate confirmation on the same day.
             */
            $duplicateCheck = $pdo->prepare(
                'SELECT id
                 FROM maintenance_acknowledgments
                 WHERE equipment_id = ?
                   AND confirmed_by = ?
                   AND DATE(confirmed_at) = CURDATE()
                 LIMIT 1'
            );

            $duplicateCheck->execute([
                $equipmentId,
                (int) $currentUser['id']
            ]);

            if ($duplicateCheck->fetch()) {
                throw new RuntimeException(
                    'You have already confirmed this equipment today.'
                );
            }

            $statement = $pdo->prepare(
                'INSERT INTO maintenance_acknowledgments
                    (
                        equipment_id,
                        confirmed_by,
                        confirmed_at,
                        next_due_date,
                        notes
                    )
                 VALUES (?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $equipmentId,
                (int) $currentUser['id'],
                $confirmedAt->format('Y-m-d H:i:s'),
                $nextDueDate->format('Y-m-d'),
                $notes !== '' ? $notes : null
            ]);

            $statement = $pdo->prepare(
                'UPDATE equipment
                 SET last_maintenance = ?,
                     next_maintenance = ?
                 WHERE id = ?'
            );

            $statement->execute([
                $confirmedAt->format('Y-m-d'),
                $nextDueDate->format('Y-m-d'),
                $equipmentId
            ]);

            audit(
                $pdo,
                'ACKNOWLEDGE',
                'Maintenance',
                'Six-month inspection confirmed for equipment ID: ' . $equipmentId
            );

            $pdo->commit();

            flash(
                'success',
                'Maintenance acknowledgement submitted successfully. Next inspection is due on '
                . $nextDueDate->format('Y-m-d')
                . '.'
            );

            header('Location: index.php?tab=acknowledgement');
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'An error occurred while saving the maintenance acknowledgement.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Acknowledgement History
|--------------------------------------------------------------------------
*/

$statement = $pdo->prepare(
    'SELECT
        maintenance_acknowledgments.*,
        users.name AS confirmed_by_name
     FROM maintenance_acknowledgments
     INNER JOIN users
        ON users.id = maintenance_acknowledgments.confirmed_by
     WHERE maintenance_acknowledgments.equipment_id = ?
     ORDER BY maintenance_acknowledgments.confirmed_at DESC,
              maintenance_acknowledgments.id DESC'
);

$statement->execute([$equipmentId]);
$acknowledgementHistory = $statement->fetchAll();

/*
|--------------------------------------------------------------------------
| Maintenance History
|--------------------------------------------------------------------------
*/

$statement = $pdo->prepare(
    'SELECT *
     FROM maintenance_records
     WHERE equipment_id = ?
     ORDER BY start_date DESC, id DESC'
);

$statement->execute([$equipmentId]);
$maintenanceHistory = $statement->fetchAll();

/*
|--------------------------------------------------------------------------
| Current Acknowledgement Status
|--------------------------------------------------------------------------
*/

$latestAcknowledgement = $acknowledgementHistory[0] ?? null;
$today = new DateTimeImmutable('today');

$lastConfirmedDate = $latestAcknowledgement
    ? new DateTimeImmutable($latestAcknowledgement['confirmed_at'])
    : (!empty($equipment['last_maintenance'])
        ? new DateTimeImmutable($equipment['last_maintenance'])
        : null);

$nextDueDate = $latestAcknowledgement && !empty($latestAcknowledgement['next_due_date'])
    ? new DateTimeImmutable($latestAcknowledgement['next_due_date'])
    : (!empty($equipment['next_maintenance'])
        ? new DateTimeImmutable($equipment['next_maintenance'])
        : null);

if ($nextDueDate === null && $lastConfirmedDate !== null) {
    $nextDueDate = $lastConfirmedDate->modify('+6 months');
}

if ($nextDueDate === null) {
    $statusText = 'Not Confirmed';
    $statusClass = 'status-overdue';
} elseif ($nextDueDate < $today) {
    $daysOverdue = $nextDueDate->diff($today)->days;
    $statusText = 'Overdue by ' . $daysOverdue . ' day(s)';
    $statusClass = 'status-overdue';
} else {
    $daysLeft = $today->diff($nextDueDate)->days;

    if ($daysLeft <= 14) {
        $statusText = 'Due Soon — ' . $daysLeft . ' day(s) left';
        $statusClass = 'status-due';
    } else {
        $statusText = 'On Track — ' . $daysLeft . ' days left';
        $statusClass = 'status-track';
    }
}

$page_title = 'Maintenance Log Sheet';

require '../../includes/header.php';

?>

<style>
    .maintenance-sheet-wrapper {
        max-width: 1180px;
        margin: 0 auto 2rem;
    }

    .maintenance-sheet-card {
        border: 1px solid #e1e8ee;
        border-radius: 16px;
        overflow: hidden;
    }

    .maintenance-sheet-header {
        padding: 1.25rem 1.4rem;
        border-bottom: 1px solid #e7edf2;
        background: #ffffff;
    }

    .maintenance-sheet-body {
        padding: 1.4rem;
    }

    .equipment-summary-card {
        height: 100%;
        padding: 1.1rem;
        border: 1px solid #e1e8ee;
        border-radius: 14px;
        background: #f8fafc;
    }

    .equipment-summary-label {
        margin-bottom: 0.25rem;
        color: #718096;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
    }

    .equipment-summary-value {
        color: #172033;
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .maintenance-status {
        display: inline-flex;
        align-items: center;
        gap: 0.42rem;
        padding: 0.48rem 0.78rem;
        border-radius: 999px;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .maintenance-status::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .status-track {
        color: #078c4f;
        background: #e7f7ef;
    }

    .status-due {
        color: #a96800;
        background: #fff3d8;
    }

    .status-overdue {
        color: #d92d20;
        background: #feeceb;
    }

    .recurring-alert {
        border: 1px solid #f1d28a;
        border-radius: 12px;
        background: #fff8e8;
        color: #72521b;
    }

    .confirmation-box {
        padding: 1rem;
        border: 1px solid #d7e2eb;
        border-radius: 12px;
        background: #ffffff;
    }

    .signature-box {
        padding: 0.9rem 1rem;
        border: 1px solid #d7e2eb;
        border-radius: 11px;
        background: #f8fafc;
        color: #172033;
        font-weight: 700;
    }

    .history-card {
        border: 1px solid #e1e8ee;
        border-radius: 16px;
        overflow: hidden;
    }

    .history-card-header {
        padding: 1rem 1.2rem;
        border-bottom: 1px solid #e7edf2;
        background: #f8fafc;
    }

    .history-table {
        margin-bottom: 0;
    }

    .history-table thead th {
        padding: 0.9rem 1rem;
        color: #556987;
        background: #f8fafc;
        border-bottom: 1px solid #159455;
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .history-table tbody td {
        padding: 0.9rem 1rem;
        vertical-align: top;
        border-color: #e8edf2;
    }

    .history-empty {
        padding: 2.5rem 1rem;
        color: #718096;
        text-align: center;
    }

    .sheet-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        padding-top: 1.25rem;
        margin-top: 1.25rem;
        border-top: 1px solid #e7edf2;
    }

    .maintenance-sheet-card .form-control {
        border-radius: 10px;
    }

    .maintenance-sheet-card textarea.form-control {
        min-height: 115px;
    }

    @media (max-width: 767.98px) {
        .sheet-actions {
            flex-direction: column-reverse;
        }

        .sheet-actions .btn {
            width: 100%;
        }
    }
</style>

<div class="maintenance-sheet-wrapper">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">

        <div class="d-flex align-items-center gap-3">

            <a
                href="index.php?tab=acknowledgement"
                class="btn btn-outline-secondary"
                title="Back to maintenance"
            >
                <i class="bi bi-arrow-left"></i>
            </a>

            <div>
                <h2 class="mb-1">Maintenance Log Sheet</h2>

                <p class="text-muted mb-0">
                    Six-month inspection acknowledgement and complete equipment maintenance history.
                </p>
            </div>

        </div>

        <span class="maintenance-status <?= e($statusClass) ?>">
            <?= e($statusText) ?>
        </span>

    </div>

    <?php if (!empty($errors)): ?>

        <div class="alert alert-danger">

            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>

        </div>

    <?php endif; ?>

    <div class="card maintenance-sheet-card mb-4">

        <div class="maintenance-sheet-header">

            <h5 class="mb-1"><?= e($equipment['name']) ?></h5>

            <div class="small text-muted">
                Review the equipment information, confirm the inspection and sign the acknowledgement.
            </div>

        </div>

        <div class="maintenance-sheet-body">

            <div class="row g-3 mb-4">

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Equipment</div>
                        <div class="equipment-summary-value"><?= e($equipment['name']) ?></div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Laboratory</div>
                        <div class="equipment-summary-value">
                            <?= e($equipment['laboratory_name'] ?: 'Not assigned') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Last Confirmed</div>
                        <div class="equipment-summary-value">
                            <?= e($lastConfirmedDate ? $lastConfirmedDate->format('Y-m-d') : 'Not confirmed') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Next Due</div>
                        <div class="equipment-summary-value">
                            <?= e($nextDueDate ? $nextDueDate->format('Y-m-d') : 'Not scheduled') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Current Equipment Status</div>
                        <div class="equipment-summary-value">
                            <?= e($equipment['status'] ?: 'Not specified') ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Inspection Cycle</div>
                        <div class="equipment-summary-value">Every 6 months</div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Acknowledgement Status</div>
                        <div class="equipment-summary-value">
                            <span class="maintenance-status <?= e($statusClass) ?>">
                                <?= e($statusText) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="equipment-summary-card">
                        <div class="equipment-summary-label">Signed By</div>
                        <div class="equipment-summary-value">
                            <?= e($currentUser['name'] ?? 'Current user') ?>
                        </div>
                    </div>
                </div>

            </div>

            <div class="alert recurring-alert mb-4">

                <div class="d-flex gap-3">

                    <i class="bi bi-arrow-repeat fs-4"></i>

                    <div>
                        <div class="fw-bold mb-1">Recurring six-month acknowledgement</div>

                        <div>
                            This is not a one-time task. Confirming the inspection today automatically schedules the next acknowledgement six months from today.
                        </div>
                    </div>

                </div>

            </div>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= e(csrf_token()) ?>"
                >

                <input
                    type="hidden"
                    name="equipment_id"
                    value="<?= (int) $equipmentId ?>"
                >

                <div class="confirmation-box mb-3">

                    <div class="form-check">

                        <input
                            class="form-check-input"
                            type="checkbox"
                            value="1"
                            id="confirmed"
                            name="confirmed"
                            required
                        >

                        <label
                            class="form-check-label fw-semibold"
                            for="confirmed"
                        >
                            I confirm that this equipment has been physically inspected and serviced as required, and is safe for continued use.
                        </label>

                    </div>

                </div>

                <div class="mb-3">

                    <label
                        for="notes"
                        class="form-label fw-semibold"
                    >
                        Inspection Notes
                    </label>

                    <textarea
                        id="notes"
                        name="notes"
                        class="form-control"
                        placeholder="Add inspection findings, service notes, parts replaced, calibration information or follow-up instructions."
                    ><?= e($_POST['notes'] ?? '') ?></textarea>

                </div>

                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Confirmed & Signed By
                    </label>

                    <div class="signature-box">

                        <i class="bi bi-person-check me-2 text-success"></i>

                        <?= e($currentUser['name'] ?? 'Current user') ?>

                        <?php if (!empty($currentUser['role'])): ?>
                            <span class="text-muted fw-normal">
                                — <?= e($currentUser['role']) ?>
                            </span>
                        <?php endif; ?>

                    </div>

                </div>

                <div class="sheet-actions">

                    <a
                        href="index.php?tab=acknowledgement"
                        class="btn btn-light px-4"
                    >
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn btn-success px-4"
                    >
                        <i class="bi bi-shield-check me-1"></i>
                        Sign & Confirm Inspection
                    </button>

                </div>

            </form>

        </div>

    </div>

    <div class="card history-card mb-4">

        <div class="history-card-header">

            <h5 class="mb-1">Acknowledgement History</h5>

            <div class="small text-muted">
                Every six-month inspection confirmation and signature for this equipment.
            </div>

        </div>

        <div class="table-responsive">

            <table class="table history-table">

                <thead>
                    <tr>
                        <th>Confirmed Date</th>
                        <th>Next Due</th>
                        <th>Notes</th>
                        <th>Signed By</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($acknowledgementHistory)): ?>

                        <tr>
                            <td colspan="4">
                                <div class="history-empty">
                                    <i class="bi bi-clipboard2-x display-6"></i>

                                    <div class="mt-3 fw-semibold">
                                        No acknowledgements recorded
                                    </div>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($acknowledgementHistory as $history): ?>

                            <tr>

                                <td class="text-nowrap">
                                    <?= e(date('Y-m-d H:i', strtotime($history['confirmed_at']))) ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e($history['next_due_date'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= e($history['notes'] ?: 'No notes entered.') ?>
                                </td>

                                <td>
                                    <?= e($history['confirmed_by_name']) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <div class="card history-card">

        <div class="history-card-header">

            <h5 class="mb-1">Full Maintenance History</h5>

            <div class="small text-muted">
                Repair, calibration, preventive maintenance and inspection records.
            </div>

        </div>

        <div class="table-responsive">

            <table class="table history-table">

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Problem / Notes</th>
                        <th>Action Taken</th>
                        <th>Technician</th>
                        <th>Next Due</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($maintenanceHistory)): ?>

                        <tr>
                            <td colspan="7">
                                <div class="history-empty">
                                    <i class="bi bi-tools display-6"></i>

                                    <div class="mt-3 fw-semibold">
                                        No maintenance records found
                                    </div>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($maintenanceHistory as $history): ?>

                            <tr>

                                <td class="text-nowrap">
                                    <?= e($history['start_date'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= e($history['type'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= e($history['problem'] ?: 'No notes entered.') ?>
                                </td>

                                <td>
                                    <?= e($history['action_taken'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= e($history['technician'] ?: '—') ?>
                                </td>

                                <td class="text-nowrap">
                                    <?= e($history['end_date'] ?: '—') ?>
                                </td>

                                <td>
                                    <?= e($history['status'] ?: 'Open') ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php require '../../includes/footer.php'; ?>