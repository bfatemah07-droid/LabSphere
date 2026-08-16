<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_role(['Supervisor','Admin']);

$currentUser = user();

function maintenance_code(int $id): string {
    return 'MX-' . str_pad((string)(500 + $id), 3, '0', STR_PAD_LEFT);
}

function go_to_maintenance(array $params = []): void {
    $url = 'index.php';
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

/* Delete */
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];

    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('SELECT equipment_id FROM maintenance_records WHERE id = ?');
            $stmt->execute([$id]);
            $record = $stmt->fetch();

            if ($record) {
                $pdo->prepare('DELETE FROM maintenance_records WHERE id = ?')->execute([$id]);

                $stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_records WHERE equipment_id = ? AND status <> 'Completed'");
                $stmt->execute([(int)$record['equipment_id']]);

                if ((int)$stmt->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE equipment SET status='Available' WHERE id=? AND status='Under maintenance'")
                        ->execute([(int)$record['equipment_id']]);
                }

                audit($pdo, 'DELETE', 'Maintenance', 'Maintenance record ID: ' . $id);
                flash('success', 'Maintenance record deleted successfully.');
            }
        } catch (PDOException $e) {
            flash('danger', 'This maintenance record could not be deleted.');
        }
    }

    go_to_maintenance();
}

/* Add / edit */
$editRecord = null;

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM maintenance_records WHERE id = ?');
    $stmt->execute([$editId]);
    $editRecord = $stmt->fetch();

    if (!$editRecord) {
        flash('danger', 'Maintenance record not found.');
        go_to_maintenance();
    }
}

$showForm = isset($_GET['add']) || $editRecord !== null;

$form = [
    'id' => $editRecord['id'] ?? '',
    'equipment_id' => $editRecord['equipment_id'] ?? '',
    'type' => $editRecord['type'] ?? 'Preventive',
    'technician' => $editRecord['technician'] ?? '',
    'problem' => $editRecord['problem'] ?? '',
    'action_taken' => $editRecord['action_taken'] ?? '',
    'start_date' => $editRecord['start_date'] ?? date('Y-m-d'),
    'end_date' => $editRecord['end_date'] ?? '',
    'status' => $editRecord['status'] ?? 'Open'
];

$errors = [];
$types = ['Preventive','Corrective','Calibration','Periodic','Inspection','Repair'];
$statuses = ['Open','In Progress','Completed'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    foreach ($form as $key => $default) {
        $form[$key] = trim((string)($_POST[$key] ?? $default));
    }

    $id = (int)$form['id'];
    $equipmentId = (int)$form['equipment_id'];
    $isEditing = $id > 0;

    if ($equipmentId <= 0) {
        $errors[] = 'Please select equipment.';
    }

    if (!in_array($form['type'], $types, true)) {
        $errors[] = 'Please select a valid maintenance type.';
    }

    if (!in_array($form['status'], $statuses, true)) {
        $errors[] = 'Please select a valid status.';
    }

    if ($form['start_date'] === '') {
        $errors[] = 'Maintenance date is required.';
    }

    if ($form['end_date'] !== '' && $form['start_date'] !== '' && $form['end_date'] < $form['start_date']) {
        $errors[] = 'Next due date cannot be earlier than the maintenance date.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $values = [
                $equipmentId,
                $form['type'],
                $form['technician'] !== '' ? $form['technician'] : null,
                $form['problem'] !== '' ? $form['problem'] : null,
                $form['action_taken'] !== '' ? $form['action_taken'] : null,
                $form['start_date'] !== '' ? $form['start_date'] : null,
                $form['end_date'] !== '' ? $form['end_date'] : null,
                $form['status']
            ];

            if ($isEditing) {
                $values[] = $id;

                $pdo->prepare(
                    'UPDATE maintenance_records
                     SET equipment_id=?, type=?, technician=?, problem=?, action_taken=?, start_date=?, end_date=?, status=?
                     WHERE id=?'
                )->execute($values);

                audit($pdo, 'UPDATE', 'Maintenance', 'Maintenance record ID: ' . $id);
                $message = 'Maintenance record updated successfully.';
            } else {
                $pdo->prepare(
                    'INSERT INTO maintenance_records
                     (equipment_id,type,technician,problem,action_taken,start_date,end_date,status)
                     VALUES(?,?,?,?,?,?,?,?)'
                )->execute($values);

                $id = (int)$pdo->lastInsertId();
                audit($pdo, 'CREATE', 'Maintenance', 'Maintenance record ID: ' . $id);
                $message = 'Maintenance record added successfully.';
            }

            if ($form['status'] === 'Completed') {
                $pdo->prepare(
                    "UPDATE equipment
                     SET status='Available', last_maintenance=?, next_maintenance=?
                     WHERE id=?"
                )->execute([
                    $form['start_date'] ?: null,
                    $form['end_date'] ?: null,
                    $equipmentId
                ]);
            } else {
                $pdo->prepare(
                    "UPDATE equipment
                     SET status='Under maintenance', maintenance_start_date=?
                     WHERE id=?"
                )->execute([
                    $form['start_date'] ?: null,
                    $equipmentId
                ]);
            }

            $pdo->commit();
            flash('success', $message);
            go_to_maintenance();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'An error occurred while saving the maintenance record.';
            $showForm = true;
        }
    } else {
        $showForm = true;
    }
}

/* Equipment */
$equipmentOptions = $pdo->query(
    'SELECT equipment.id, equipment.name, laboratories.name AS laboratory_name
     FROM equipment
     LEFT JOIN laboratories ON laboratories.id = equipment.lab_id
     ORDER BY equipment.name'
)->fetchAll();

/* Tabs and filters */
$activeTab = ($_GET['tab'] ?? 'log') === 'acknowledgement' ? 'acknowledgement' : 'log';
$search = trim($_GET['q'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dateFrom = trim($_GET['from'] ?? '');
$dateTo = trim($_GET['to'] ?? '');

/* Maintenance log */
$sql = '
    SELECT
        mr.*,
        equipment.name AS equipment_name,
        equipment.next_maintenance,
        laboratories.name AS laboratory_name
    FROM maintenance_records mr
    INNER JOIN equipment ON equipment.id = mr.equipment_id
    LEFT JOIN laboratories ON laboratories.id = equipment.lab_id
    WHERE 1=1
';

$params = [];

if ($search !== '') {
    $sql .= ' AND (
        equipment.name LIKE ?
        OR laboratories.name LIKE ?
        OR mr.type LIKE ?
        OR mr.technician LIKE ?
        OR mr.problem LIKE ?
        OR mr.action_taken LIKE ?
        OR CAST(mr.id AS CHAR) LIKE ?
    )';

    $value = '%' . $search . '%';
    $params = array_fill(0, 7, $value);
}

if ($typeFilter !== '' && in_array($typeFilter, $types, true)) {
    $sql .= ' AND mr.type = ?';
    $params[] = $typeFilter;
}

if ($statusFilter !== '' && in_array($statusFilter, $statuses, true)) {
    $sql .= ' AND mr.status = ?';
    $params[] = $statusFilter;
}

if ($dateFrom !== '') {
    $sql .= ' AND mr.start_date >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $sql .= ' AND mr.start_date <= ?';
    $params[] = $dateTo;
}

$sql .= ' ORDER BY mr.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/* Six-month acknowledgement */
$ackRows = $pdo->query(
    'SELECT
        equipment.id AS equipment_id,
        equipment.name AS equipment_name,
        laboratories.name AS laboratory_name,
        latest_ack.confirmed_at,
        latest_ack.next_due_date,
        latest_ack.notes,
        latest_ack.confirmed_by
     FROM equipment
     LEFT JOIN laboratories ON laboratories.id = equipment.lab_id
     LEFT JOIN (
        SELECT ma.*
        FROM maintenance_acknowledgments ma
        INNER JOIN (
            SELECT equipment_id, MAX(id) AS latest_id
            FROM maintenance_acknowledgments
            GROUP BY equipment_id
        ) latest ON latest.latest_id = ma.id
     ) latest_ack ON latest_ack.equipment_id = equipment.id
     ORDER BY equipment.name'
)->fetchAll();

$page_title = 'Maintenance';
require '../../includes/header.php';
?>

<style>
.maintenance-tabs{display:inline-flex;flex-wrap:wrap;gap:.35rem;padding:.35rem;margin-bottom:1.25rem;background:#eef3f7;border-radius:12px}
.maintenance-tab{padding:.65rem 1rem;color:#52637a;border-radius:9px;font-weight:600;text-decoration:none}
.maintenance-tab:hover{color:#0b7d43}
.maintenance-tab.active{color:#172033;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.08)}
.maintenance-filter-card,.maintenance-table-card,.maintenance-form-card{border:1px solid #e1e8ee;border-radius:16px;overflow:hidden}
.maintenance-filter-card{padding:1rem;margin-bottom:1.5rem}
.maintenance-table-card{background:#fff}
.maintenance-table{min-width:1380px;margin-bottom:0}
.maintenance-table thead th{padding:1rem;color:#556987;background:#f8fafc;border-bottom:1px solid #159455;font-size:.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap}
.maintenance-table tbody td{padding:1rem;color:#172033;vertical-align:middle;border-color:#e8edf2}
.maintenance-table tbody tr:hover{background:#fbfdfc}
.maintenance-id,.maintenance-equipment,.ack-equipment{font-weight:700}
.maintenance-equipment{min-width:190px}
.maintenance-lab{min-width:160px}
.maintenance-notes{min-width:230px;max-width:280px;color:#5d6f89;line-height:1.4}
.maintenance-action-note{margin-top:.35rem;color:#8390a4;font-size:.82rem}
.maintenance-date{white-space:nowrap}
.maintenance-status{display:inline-flex;align-items:center;gap:.4rem;padding:.42rem .7rem;border-radius:999px;font-size:.8rem;font-weight:700;white-space:nowrap}
.maintenance-status::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.status-open,.status-overdue{color:#d92d20;background:#feeceb}
.status-progress{color:#b46900;background:#fff3d8}
.status-completed,.status-track{color:#078c4f;background:#e7f7ef}
.maintenance-actions{display:flex;align-items:center;gap:.45rem;white-space:nowrap}
.maintenance-action-btn{width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;padding:0;border-radius:9px}
.empty-maintenance{padding:4rem 1.5rem;color:#718096;text-align:center}
.maintenance-form-card{margin-bottom:1.5rem}
.maintenance-form-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1.1rem 1.25rem;border-bottom:1px solid #e7edf2}
.maintenance-form-body{padding:1.25rem}
.maintenance-form-footer{display:flex;justify-content:flex-end;gap:.75rem;padding-top:1.25rem;margin-top:1.25rem;border-top:1px solid #e7edf2}
.maintenance-form-card .form-control,.maintenance-form-card .form-select,.maintenance-filter-card .form-control,.maintenance-filter-card .form-select{min-height:44px;border-radius:10px}
.maintenance-form-card textarea.form-control{min-height:105px}
@media(max-width:767.98px){
    .maintenance-tabs{display:flex}
    .maintenance-tab{flex:1;text-align:center}
    .maintenance-form-footer{flex-direction:column-reverse}
    .maintenance-form-footer .btn{width:100%}
}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h2 class="mb-1">Maintenance</h2>
        <p class="text-muted mb-0">Log inspections, repairs and calibrations, and track every equipment maintenance cycle.</p>
    </div>

    <?php if ($activeTab === 'log'): ?>
        <a href="?add=1" class="btn btn-success">
            <i class="bi bi-plus-lg me-1"></i>
            New Maintenance Record
        </a>
    <?php endif; ?>
</div>

<div class="maintenance-tabs">
    <a href="index.php?tab=log" class="maintenance-tab <?=$activeTab==='log'?'active':''?>">Maintenance Log</a>
    <a href="index.php?tab=acknowledgement" class="maintenance-tab <?=$activeTab==='acknowledgement'?'active':''?>">6-Month Acknowledgement</a>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?=e($error)?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($showForm && $activeTab === 'log'): ?>
<div class="card maintenance-form-card">
    <div class="maintenance-form-header">
        <div>
            <h5 class="mb-1"><?=$editRecord?'Edit Maintenance Record':'New Maintenance Record'?></h5>
            <div class="small text-muted">Record the equipment, maintenance details, dates and current status.</div>
        </div>
        <a href="index.php" class="btn-close" aria-label="Close"></a>
    </div>

    <div class="maintenance-form-body">
        <form method="post">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="id" value="<?=e($form['id'])?>">

            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="equipment_id">Equipment</label>
                    <select class="form-select" id="equipment_id" name="equipment_id" required>
                        <option value="">Select equipment</option>
                        <?php foreach ($equipmentOptions as $equipment): ?>
                            <option value="<?=(int)$equipment['id']?>" <?=(int)($form['equipment_id']?:0)===(int)$equipment['id']?'selected':''?>>
                                <?=e($equipment['name'])?>
                                <?php if (!empty($equipment['laboratory_name'])): ?>
                                    — <?=e($equipment['laboratory_name'])?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type" required>
                        <?php foreach ($types as $type): ?>
                            <option value="<?=e($type)?>" <?=$form['type']===$type?'selected':''?>><?=e($type)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" required>
                        <?php foreach ($statuses as $recordStatus): ?>
                            <option value="<?=e($recordStatus)?>" <?=$form['status']===$recordStatus?'selected':''?>><?=e($recordStatus)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-6">
                    <label class="form-label" for="technician">Technician</label>
                    <input class="form-control" id="technician" name="technician" value="<?=e($form['technician'])?>" placeholder="Technician or service provider">
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="start_date">Date</label>
                    <input class="form-control" type="date" id="start_date" name="start_date" value="<?=e($form['start_date'])?>" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="end_date">Next Due</label>
                    <input class="form-control" type="date" id="end_date" name="end_date" value="<?=e($form['end_date'])?>">
                </div>

                <div class="col-lg-6">
                    <label class="form-label" for="problem">Problem / Notes</label>
                    <textarea class="form-control" id="problem" name="problem" placeholder="Describe the problem, inspection notes or maintenance reason."><?=e($form['problem'])?></textarea>
                </div>

                <div class="col-lg-6">
                    <label class="form-label" for="action_taken">Action Taken</label>
                    <textarea class="form-control" id="action_taken" name="action_taken" placeholder="Describe the repair, service, calibration or action completed."><?=e($form['action_taken'])?></textarea>
                </div>
            </div>

            <div class="maintenance-form-footer">
                <a href="index.php" class="btn btn-light px-4">Cancel</a>
                <button class="btn btn-success px-4">
                    <i class="bi bi-check-lg me-1"></i>
                    <?=$editRecord?'Update Record':'Save Record'?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'log'): ?>
<div class="card maintenance-filter-card">
    <form method="get">
        <input type="hidden" name="tab" value="log">

        <div class="row g-2 align-items-end">
            <div class="col-xl-4 col-lg-6">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" type="search" id="q" name="q" value="<?=e($search)?>" placeholder="ID, equipment, laboratory, technician or notes...">
            </div>

            <div class="col-xl-2 col-md-4">
                <label class="form-label" for="filter_type">Type</label>
                <select class="form-select" id="filter_type" name="type">
                    <option value="">All types</option>
                    <?php foreach ($types as $filterType): ?>
                        <option value="<?=e($filterType)?>" <?=$typeFilter===$filterType?'selected':''?>><?=e($filterType)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-xl-2 col-md-4">
                <label class="form-label" for="filter_status">Status</label>
                <select class="form-select" id="filter_status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($statuses as $filterStatus): ?>
                        <option value="<?=e($filterStatus)?>" <?=$statusFilter===$filterStatus?'selected':''?>><?=e($filterStatus)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-xl-1 col-md-4">
                <label class="form-label" for="from">From</label>
                <input class="form-control" type="date" id="from" name="from" value="<?=e($dateFrom)?>">
            </div>

            <div class="col-xl-1 col-md-4">
                <label class="form-label" for="to">To</label>
                <input class="form-control" type="date" id="to" name="to" value="<?=e($dateTo)?>">
            </div>

            <div class="col-xl-2 col-md-4">
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-success flex-fill" title="Search"><i class="bi bi-search"></i></button>
                    <a href="index.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="card maintenance-table-card">
    <div class="table-responsive">
        <table class="table maintenance-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Equipment</th>
                    <th>Laboratory</th>
                    <th>Type</th>
                    <th>Problem / Notes</th>
                    <th>Technician</th>
                    <th>Date</th>
                    <th>Next Due</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="10">
                            <div class="empty-maintenance">
                                <i class="bi bi-tools display-6"></i>
                                <div class="mt-3 fw-semibold">No maintenance records found</div>
                                <div class="small mt-1">Add a maintenance record or change the filters.</div>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $record): ?>
                        <?php
                        $statusClass = match ($record['status']) {
                            'Completed' => 'status-completed',
                            'In Progress' => 'status-progress',
                            default => 'status-open'
                        };
                        $nextDue = $record['end_date'] ?: $record['next_maintenance'];
                        ?>
                        <tr>
                            <td><span class="maintenance-id"><?=e(maintenance_code((int)$record['id']))?></span></td>
                            <td class="maintenance-equipment"><?=e($record['equipment_name'])?></td>
                            <td class="maintenance-lab"><?=e($record['laboratory_name'] ?: 'Not assigned')?></td>
                            <td><?=e($record['type'])?></td>
                            <td>
                                <div class="maintenance-notes">
                                    <?=e($record['problem'] ?: 'No problem or notes entered.')?>
                                    <?php if (!empty($record['action_taken'])): ?>
                                        <div class="maintenance-action-note"><strong>Action:</strong> <?=e($record['action_taken'])?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?=e($record['technician'] ?: '—')?></td>
                            <td class="maintenance-date"><?=e($record['start_date'] ?: '—')?></td>
                            <td class="maintenance-date"><?=e($nextDue ?: '—')?></td>
                            <td><span class="maintenance-status <?=e($statusClass)?>"><?=e($record['status'] ?: 'Open')?></span></td>
                            <td>
                                <div class="maintenance-actions">
                                    <a href="?edit=<?=(int)$record['id']?>" class="btn btn-outline-success maintenance-action-btn" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <a href="?delete=<?=(int)$record['id']?>" class="btn btn-outline-danger maintenance-action-btn" title="Delete" onclick="return confirm('Are you sure you want to delete this maintenance record?');"><i class="bi bi-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>

<div class="card maintenance-table-card">
    <div class="table-responsive">
        <table class="table maintenance-table" style="min-width:1000px">
            <thead>
                <tr>
                    <th>Equipment</th>
                    <th>Laboratory</th>
                    <th>Last Confirmed</th>
                    <th>Next Due</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($ackRows as $row): ?>
                    <?php
                    $today = new DateTimeImmutable('today');
                    $lastConfirmed = !empty($row['confirmed_at']) ? new DateTimeImmutable($row['confirmed_at']) : null;
                    $nextDueDate = !empty($row['next_due_date']) ? new DateTimeImmutable($row['next_due_date']) : null;

                    if ($nextDueDate === null && $lastConfirmed !== null) {
                        $nextDueDate = $lastConfirmed->modify('+6 months');
                    }

                    if ($nextDueDate === null) {
                        $ackText = 'Not confirmed';
                        $ackClass = 'status-overdue';
                    } elseif ($nextDueDate < $today) {
                        $days = $nextDueDate->diff($today)->days;
                        $ackText = 'Overdue by ' . $days . ' day(s)';
                        $ackClass = 'status-overdue';
                    } else {
                        $days = $today->diff($nextDueDate)->days;
                        $ackText = 'On track — ' . $days . ' days left';
                        $ackClass = 'status-track';
                    }
                    ?>
                    <tr>
                        <td class="ack-equipment"><?=e($row['equipment_name'])?></td>
                        <td><?=e($row['laboratory_name'] ?: 'Not assigned')?></td>
                        <td class="maintenance-date"><?=e($lastConfirmed ? $lastConfirmed->format('Y-m-d') : '—')?></td>
                        <td class="maintenance-date"><?=e($nextDueDate ? $nextDueDate->format('Y-m-d') : '—')?></td>
                        <td><span class="maintenance-status <?=e($ackClass)?>"><?=e($ackText)?></span></td>
                        <td>
                            <a href="log_sheet.php?equipment_id=<?=(int)$row['equipment_id']?>" class="btn btn-outline-success">
                                <i class="bi bi-pen me-1"></i>
                                View Log Sheet
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require '../../includes/footer.php'; ?>