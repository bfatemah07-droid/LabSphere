<?php

require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$currentUser = user();
$isManager = in_array($currentUser['role'] ?? '', ['Admin', 'Supervisor'], true);
$isRegularUser = in_array($currentUser['role'] ?? '', ['User', 'Student'], true);
$page_title = 'Storage Spaces';

function storage_code(int $id): string
{
    return 'ST-' . str_pad((string)(400 + $id), 3, '0', STR_PAD_LEFT);
}

function storage_redirect(string $suffix = ''): void
{
    header('Location: index.php' . $suffix);
    exit;
}

function storage_status_class(string $status): string
{
    return match ($status) {
        'Available' => 'storage-status-available',
        'Partially Available' => 'storage-status-partial',
        'Full' => 'storage-status-full',
        default => 'storage-status-maintenance',
    };
}

function storage_icon(string $type): string
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

$errors = [];
$editSpace = null;
$temperatureSpace = null;

/*
|--------------------------------------------------------------------------
| Manager Actions
|--------------------------------------------------------------------------
*/

if ($isManager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_space') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $type = trim((string)($_POST['type'] ?? ''));
        $labId = (int)($_POST['lab_id'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        $capacity = max(0, (int)($_POST['capacity'] ?? 0));
        $usedCapacity = max(0, (int)($_POST['used_capacity'] ?? 0));
        $tempMin = ($_POST['temp_min'] ?? '') !== '' ? (float)$_POST['temp_min'] : null;
        $tempMax = ($_POST['temp_max'] ?? '') !== '' ? (float)$_POST['temp_max'] : null;
        $status = trim((string)($_POST['status'] ?? 'Available'));

        $allowedTypes = [
            'Refrigerator',
            'Freezer',
            'Cabinet',
            'Shelf',
            'Storage Room',
            'Drawer'
        ];

        $allowedStatuses = [
            'Available',
            'Partially Available',
            'Full',
            'Under Maintenance'
        ];

        if ($name === '') {
            $errors[] = 'Storage space name is required.';
        }

        if (!in_array($type, $allowedTypes, true)) {
            $errors[] = 'Please select a valid storage type.';
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Please select a valid storage status.';
        }

        if ($capacity <= 0) {
            $errors[] = 'Capacity must be greater than zero.';
        }

        if ($usedCapacity > $capacity) {
            $errors[] = 'Used capacity cannot be greater than total capacity.';
        }

        if ($tempMin !== null && $tempMax !== null && $tempMin > $tempMax) {
            $errors[] = 'Minimum temperature cannot be greater than maximum temperature.';
        }

        // Storage names are used by the current temperature log schema, so keep them unique.
        if ($name !== '') {
            $duplicateStatement = $pdo->prepare(
                'SELECT id FROM storage_spaces WHERE name = ? AND id <> ? LIMIT 1'
            );
            $duplicateStatement->execute([$name, $id]);
            if ($duplicateStatement->fetch()) {
                $errors[] = 'A storage space with this name already exists.';
            }
        }

        if ($labId > 0) {
            $labStatement = $pdo->prepare('SELECT id FROM laboratories WHERE id = ? LIMIT 1');
            $labStatement->execute([$labId]);
            if (!$labStatement->fetch()) {
                $errors[] = 'Please select a valid laboratory.';
            }
        }

        // Temperature ranges are only meaningful for refrigerators and freezers.
        if (in_array($type, ['Refrigerator', 'Freezer'], true)) {
            if (($tempMin === null) !== ($tempMax === null)) {
                $errors[] = 'Please enter both minimum and maximum temperatures, or leave both empty.';
            }
        } else {
            $tempMin = null;
            $tempMax = null;
        }

        if (!$errors) {
            // Capacity drives the normal availability status. Under Maintenance stays manual.
            if ($status !== 'Under Maintenance') {
                if ($usedCapacity >= $capacity) {
                    $status = 'Full';
                } elseif ($usedCapacity > 0) {
                    $status = 'Partially Available';
                } else {
                    $status = 'Available';
                }
            }

            if ($id > 0) {
                $oldNameStatement = $pdo->prepare('SELECT name FROM storage_spaces WHERE id = ? LIMIT 1');
                $oldNameStatement->execute([$id]);
                $oldName = (string)($oldNameStatement->fetchColumn() ?: '');

                $pdo->beginTransaction();

                $statement = $pdo->prepare(
                    'UPDATE storage_spaces
                     SET name = ?,
                         type = ?,
                         lab_id = ?,
                         description = ?,
                         capacity = ?,
                         used_capacity = ?,
                         temp_min = ?,
                         temp_max = ?,
                         status = ?
                     WHERE id = ?'
                );

                $statement->execute([
                    $name,
                    $type,
                    $labId > 0 ? $labId : null,
                    $description !== '' ? $description : null,
                    $capacity,
                    $usedCapacity,
                    $tempMin,
                    $tempMax,
                    $status,
                    $id
                ]);

                // The current temperature_logs table links by storage name. Keep history attached after a rename.
                if ($oldName !== '' && $oldName !== $name) {
                    $renameLogs = $pdo->prepare(
                        'UPDATE temperature_logs SET storage_name = ? WHERE storage_name = ?'
                    );
                    $renameLogs->execute([$name, $oldName]);
                }

                $pdo->commit();

                audit($pdo, 'UPDATE', 'Storage Space', 'Storage space ID: ' . $id);
                flash('success', 'Storage space updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO storage_spaces
                        (name, type, lab_id, description, capacity, used_capacity, temp_min, temp_max, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $statement->execute([
                    $name,
                    $type,
                    $labId > 0 ? $labId : null,
                    $description !== '' ? $description : null,
                    $capacity,
                    $usedCapacity,
                    $tempMin,
                    $tempMax,
                    $status
                ]);

                $newId = (int)$pdo->lastInsertId();
                audit($pdo, 'CREATE', 'Storage Space', 'Storage space ID: ' . $newId);
                flash('success', 'Storage space added successfully.');
            }

            storage_redirect();
        }
    }

    if ($action === 'delete_space') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            flash('danger', 'Invalid storage space.');
            storage_redirect();
        }

        try {
            $statement = $pdo->prepare('DELETE FROM storage_spaces WHERE id = ?');
            $statement->execute([$id]);

            audit($pdo, 'DELETE', 'Storage Space', 'Storage space ID: ' . $id);
            flash('success', 'Storage space deleted successfully.');
        } catch (PDOException $exception) {
            flash('danger', 'This storage space cannot be deleted because it has related records.');
        }

        storage_redirect();
    }

    if ($action === 'log_temperature') {
        $storageId = (int)($_POST['storage_id'] ?? 0);
        $temperature = trim((string)($_POST['temperature'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));

        $statement = $pdo->prepare(
            "SELECT id, name, type, temp_min, temp_max, status
             FROM storage_spaces
             WHERE id = ?
               AND type IN ('Refrigerator', 'Freezer')
             LIMIT 1"
        );

        $statement->execute([$storageId]);
        $space = $statement->fetch();

        if (!$space) {
            $errors[] = 'This storage space does not support temperature readings.';
        } elseif ($temperature === '' || !is_numeric($temperature)) {
            $errors[] = 'Please enter a valid temperature.';
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO temperature_logs
                    (storage_name, temperature, note, signed_by)
                 VALUES (?, ?, ?, ?)'
            );

            $statement->execute([
                $space['name'],
                (float)$temperature,
                $note !== '' ? $note : null,
                $currentUser['name']
            ]);

            audit($pdo, 'CREATE', 'Temperature', $space['name']);

            $temperatureValue = (float)$temperature;
            $isOutOfRange = (
                $space['temp_min'] !== null
                && $temperatureValue < (float)$space['temp_min']
            ) || (
                $space['temp_max'] !== null
                && $temperatureValue > (float)$space['temp_max']
            );

            if ($isOutOfRange) {
                flash('warning', 'Temperature reading saved, but it is outside the configured safe range.');
            } else {
                flash('success', 'Temperature reading saved successfully.');
            }

            storage_redirect();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Edit and Temperature Forms
|--------------------------------------------------------------------------
*/

if ($isManager && isset($_GET['edit'])) {
    $statement = $pdo->prepare('SELECT * FROM storage_spaces WHERE id = ? LIMIT 1');
    $statement->execute([(int)$_GET['edit']]);
    $editSpace = $statement->fetch();

    if (!$editSpace) {
        flash('danger', 'Storage space not found.');
        storage_redirect();
    }
}

if ($isManager && isset($_GET['temperature'])) {
    $statement = $pdo->prepare(
        "SELECT *
         FROM storage_spaces
         WHERE id = ?
           AND type IN ('Refrigerator', 'Freezer')
         LIMIT 1"
    );

    $statement->execute([(int)$_GET['temperature']]);
    $temperatureSpace = $statement->fetch();

    if (!$temperatureSpace) {
        flash('danger', 'Temperature logging is not available for this storage space.');
        storage_redirect();
    }
}

/*
|--------------------------------------------------------------------------
| Data
|--------------------------------------------------------------------------
*/

$laboratories = $pdo->query(
    'SELECT id, name
     FROM laboratories
     ORDER BY name'
)->fetchAll();

$search = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$laboratoryFilter = (int)($_GET['lab_id'] ?? 0);
$sort = trim((string)($_GET['sort'] ?? 'newest'));

$allowedFilterTypes = ['Refrigerator', 'Freezer', 'Cabinet', 'Shelf', 'Storage Room', 'Drawer'];
$allowedFilterStatuses = ['Available', 'Partially Available', 'Full', 'Under Maintenance'];
$allowedSorts = ['newest', 'name', 'available', 'used', 'type'];

if (!in_array($typeFilter, $allowedFilterTypes, true)) $typeFilter = '';
if (!in_array($statusFilter, $allowedFilterStatuses, true)) $statusFilter = '';
if (!in_array($sort, $allowedSorts, true)) $sort = 'newest';

$sql = 'SELECT
            ss.*,
            l.name AS laboratory_name,
            tl.temperature AS last_temperature,
            tl.logged_at AS last_logged_at
        FROM storage_spaces ss
        LEFT JOIN laboratories l ON l.id = ss.lab_id
        LEFT JOIN temperature_logs tl ON tl.id = (
            SELECT MAX(t2.id)
            FROM temperature_logs t2
            WHERE t2.storage_name = ss.name
        )
        WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (ss.name LIKE ? OR ss.description LIKE ? OR ss.type LIKE ? OR l.name LIKE ?)';
    $value = '%' . $search . '%';
    array_push($params, $value, $value, $value, $value);
}
if ($typeFilter !== '') { $sql .= ' AND ss.type = ?'; $params[] = $typeFilter; }
if ($statusFilter !== '') { $sql .= ' AND ss.status = ?'; $params[] = $statusFilter; }
if ($laboratoryFilter > 0) { $sql .= ' AND ss.lab_id = ?'; $params[] = $laboratoryFilter; }

$sql .= match ($sort) {
    'name' => ' ORDER BY ss.name ASC',
    'available' => ' ORDER BY (ss.capacity - ss.used_capacity) DESC, ss.name ASC',
    'used' => ' ORDER BY ss.used_capacity DESC, ss.name ASC',
    'type' => ' ORDER BY ss.type ASC, ss.name ASC',
    default => ' ORDER BY ss.id DESC',
};

$statement = $pdo->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status='Available') AS available,
        SUM(status='Partially Available') AS partial,
        SUM(status='Full') AS full_count,
        SUM(status='Under Maintenance') AS maintenance
     FROM storage_spaces"
)->fetch() ?: ['total'=>0,'available'=>0,'partial'=>0,'full_count'=>0,'maintenance'=>0];

$formValues = [
    'id' => $_POST['id'] ?? ($editSpace['id'] ?? ''),
    'name' => $_POST['name'] ?? ($editSpace['name'] ?? ''),
    'type' => $_POST['type'] ?? ($editSpace['type'] ?? 'Refrigerator'),
    'lab_id' => $_POST['lab_id'] ?? ($editSpace['lab_id'] ?? ''),
    'description' => $_POST['description'] ?? ($editSpace['description'] ?? ''),
    'capacity' => $_POST['capacity'] ?? ($editSpace['capacity'] ?? ''),
    'used_capacity' => $_POST['used_capacity'] ?? ($editSpace['used_capacity'] ?? 0),
    'temp_min' => $_POST['temp_min'] ?? ($editSpace['temp_min'] ?? ''),
    'temp_max' => $_POST['temp_max'] ?? ($editSpace['temp_max'] ?? ''),
    'status' => $_POST['status'] ?? ($editSpace['status'] ?? 'Available'),
];

$showSpaceForm = $isManager && (isset($_GET['add']) || $editSpace || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_space'));
$showTemperatureForm = $isManager && ($temperatureSpace || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_temperature'));

require '../../includes/header.php';

?>

<style>
.storage-page-head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.storage-panel,
.storage-table-card,
.storage-card {
    border: 1px solid #dfe8e3;
    border-radius: 16px;
    overflow: hidden;
}

.storage-panel {
    margin-bottom: 1.5rem;
}

.storage-panel .card-header {
    padding: 1rem 1.25rem;
    background: #f8fafc;
    border-bottom: 1px solid #e6ece9;
    font-weight: 700;
}

.storage-table {
    min-width: 1200px;
    margin: 0;
}

.storage-table thead th {
    padding: .95rem 1rem;
    color: #5f7088;
    background: #f8fafc;
    border-bottom: 1px solid #159455;
    font-size: .76rem;
    font-weight: 700;
    letter-spacing: .035em;
    text-transform: uppercase;
    white-space: nowrap;
}

.storage-table tbody td {
    padding: 1rem;
    vertical-align: middle;
    border-color: #e9efec;
}

.storage-id,
.storage-name {
    font-weight: 700;
}

.storage-status {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .42rem .72rem;
    border-radius: 999px;
    font-size: .79rem;
    font-weight: 700;
    white-space: nowrap;
}

.storage-status::before {
    content: "";
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: currentColor;
}

.storage-status-available {
    color: #078c4f;
    background: #e7f7ef;
}

.storage-status-partial {
    color: #0b76a8;
    background: #e8f5fb;
}

.storage-status-full {
    color: #d92d20;
    background: #feeceb;
}

.storage-status-maintenance {
    color: #b46900;
    background: #fff3d8;
}

.storage-action {
    width: 38px;
    height: 38px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 9px;
}

.student-storage-card {
    height: 100%;
    border: 1px solid #dfe8e3;
    border-radius: 16px;
    overflow: hidden;
}

.student-storage-top {
    min-height: 115px;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #0d9b61, #087c4e);
    color: #fff;
}

.student-storage-icon {
    font-size: 2.7rem;
}

.student-storage-badge {
    position: absolute;
    top: 14px;
    right: 14px;
}

.student-storage-body {
    padding: 1rem;
}

.student-storage-type {
    color: #098454;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.student-storage-meta {
    color: #66758a;
    font-size: .88rem;
}

.student-storage-actions {
    display: grid;
    gap: .5rem;
    margin-top: 1rem;
}

.storage-summary-card{border:1px solid #dfe8e3;border-radius:14px;padding:1rem;background:#fff;height:100%}
.storage-summary-label{color:#66758a;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.storage-summary-value{font-size:1.55rem;font-weight:800;color:#172033;margin-top:.25rem}
.storage-filter-card{border:1px solid #dfe8e3;border-radius:16px;margin-bottom:1.5rem}
.storage-capacity-line{height:8px;border-radius:999px;background:#e8efeb;overflow:hidden;margin-top:.55rem}
.storage-capacity-fill{height:100%;border-radius:999px;background:#159455}
.storage-card-footer-note{display:flex;justify-content:space-between;gap:.75rem;color:#66758a;font-size:.78rem;margin-top:.45rem}

@media (max-width: 1199.98px) {
    .storage-table-card {
        overflow-x: auto;
    }
}
</style>

<div class="storage-page-head">
    <div>
        <h2 class="mb-1">Storage Spaces</h2>

        <p class="text-muted mb-0">
            <?= $isManager
                ? 'Manage refrigerators, freezers, cabinets, shelves, drawers and storage rooms.'
                : 'Reserve an available storage space for samples, materials and laboratory work.' ?>
        </p>
    </div>

    <?php if ($isManager): ?>
        <a href="?add=1" class="btn btn-success">
            <i class="bi bi-plus-lg me-1"></i>
            New Storage Space
        </a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total Spaces', (int)$summary['total'], 'bi-archive'],
        ['Available', (int)$summary['available'], 'bi-check-circle'],
        ['Partially Available', (int)$summary['partial'], 'bi-pie-chart'],
        ['Full', (int)$summary['full_count'], 'bi-exclamation-circle'],
        ['Under Maintenance', (int)$summary['maintenance'], 'bi-tools'],
    ] as $card): ?>
        <div class="col-6 col-md-4 col-xl">
            <div class="storage-summary-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="storage-summary-label"><?= e($card[0]) ?></div>
                        <div class="storage-summary-value"><?= (int)$card[1] ?></div>
                    </div>
                    <i class="bi <?= e($card[2]) ?> fs-4 text-success"></i>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card storage-filter-card">
    <div class="card-body p-3">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label">Search</label>
                <input type="search" class="form-control" name="q" value="<?= e($search) ?>" placeholder="Name, type, laboratory or description">
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Type</label>
                <select class="form-select" name="type">
                    <option value="">All types</option>
                    <?php foreach ($allowedFilterTypes as $value): ?>
                        <option value="<?= e($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All statuses</option>
                    <?php foreach ($allowedFilterStatuses as $value): ?>
                        <option value="<?= e($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Laboratory</label>
                <select class="form-select" name="lab_id">
                    <option value="">All laboratories</option>
                    <?php foreach ($laboratories as $laboratory): ?>
                        <option value="<?= (int)$laboratory['id'] ?>" <?= $laboratoryFilter === (int)$laboratory['id'] ? 'selected' : '' ?>><?= e($laboratory['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label">Sort</label>
                <select class="form-select" name="sort">
                    <?php foreach (['newest'=>'Newest','name'=>'Name','available'=>'Most available','used'=>'Most used','type'=>'Type'] as $value=>$label): ?>
                        <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                <a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>
                <button class="btn btn-success"><i class="bi bi-search me-1"></i>Apply</button>
            </div>
        </form>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($showSpaceForm): ?>
    <div class="card storage-panel">
        <div class="card-header">
            <?= $editSpace ? 'Edit Storage Space' : 'New Storage Space' ?>
        </div>

        <div class="card-body p-4">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_space">
                <input type="hidden" name="id" value="<?= e($formValues['id']) ?>">

                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="form-label">Name</label>
                        <input
                            type="text"
                            class="form-control"
                            name="name"
                            value="<?= e($formValues['name']) ?>"
                            placeholder="e.g. Refrigerator R1 — Shelf A"
                            required
                        >
                    </div>

                    <div class="col-lg-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" required>
                            <?php foreach (['Refrigerator', 'Freezer', 'Cabinet', 'Shelf', 'Storage Room', 'Drawer'] as $type): ?>
                                <option value="<?= e($type) ?>" <?= $formValues['type'] === $type ? 'selected' : '' ?>>
                                    <?= e($type) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-lg-3">
                        <label class="form-label">Laboratory</label>
                        <select class="form-select" name="lab_id">
                            <option value="">Not assigned</option>

                            <?php foreach ($laboratories as $laboratory): ?>
                                <option
                                    value="<?= (int)$laboratory['id'] ?>"
                                    <?= (int)$formValues['lab_id'] === (int)$laboratory['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($laboratory['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea
                            class="form-control"
                            name="description"
                            rows="3"
                            placeholder="Describe the storage space and its intended use."
                        ><?= e($formValues['description']) ?></textarea>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Total Capacity (Samples)</label>
                        <input
                            type="number"
                            class="form-control"
                            name="capacity"
                            min="1"
                            value="<?= e($formValues['capacity']) ?>"
                            required
                        >
                        <div class="form-text">Base capacity is stored in individual samples. 1 Box = 100 samples, 1 Rack = 1,200 samples, 1 Shelf = 4,800 samples.</div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Used Capacity (Samples)</label>
                        <input
                            type="number"
                            class="form-control"
                            name="used_capacity"
                            min="0"
                            value="<?= e($formValues['used_capacity']) ?>"
                            required
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Minimum Temperature °C</label>
                        <input
                            type="number"
                            class="form-control"
                            name="temp_min"
                            step="0.01"
                            value="<?= e($formValues['temp_min']) ?>"
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Maximum Temperature °C</label>
                        <input
                            type="number"
                            class="form-control"
                            name="temp_max"
                            step="0.01"
                            value="<?= e($formValues['temp_max']) ?>"
                        >
                    </div>

                    <div class="col-lg-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach (['Available', 'Partially Available', 'Full', 'Under Maintenance'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $formValues['status'] === $status ? 'selected' : '' ?>>
                                    <?= e($status) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="index.php" class="btn btn-light">Cancel</a>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>
                        <?= $editSpace ? 'Update Storage Space' : 'Save Storage Space' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($showTemperatureForm && $temperatureSpace): ?>
    <div class="card storage-panel">
        <div class="card-header">
            Log Temperature — <?= e($temperatureSpace['name']) ?>
        </div>

        <div class="card-body p-4">
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="log_temperature">
                <input type="hidden" name="storage_id" value="<?= (int)$temperatureSpace['id'] ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Temperature °C</label>
                        <input
                            type="number"
                            class="form-control"
                            name="temperature"
                            step="0.01"
                            required
                        >
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Note</label>
                        <input
                            type="text"
                            class="form-control"
                            name="note"
                            placeholder="Optional reading note"
                        >
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="index.php" class="btn btn-light">Cancel</a>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-thermometer-half me-1"></i>
                        Save Reading
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($isManager): ?>

    <div class="card storage-table-card">
        <div class="table-responsive">
            <table class="table storage-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Laboratory</th>
                        <th>Capacity</th>
                        <th>Temp. Range</th>
                        <th>Last Reading</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-archive display-6"></i>
                                <div class="mt-3 fw-semibold">No storage spaces found</div>
                                <div class="small">Add the first storage space to begin.</div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($rows as $row): ?>
                        <?php
                        $canLogTemperature = in_array($row['type'], ['Refrigerator', 'Freezer'], true);

                        if ($row['temp_min'] !== null && $row['temp_max'] !== null) {
                            $temperatureRange = ((float)$row['temp_min'] === (float)$row['temp_max'])
                                ? $row['temp_min'] . '°C'
                                : $row['temp_min'] . '–' . $row['temp_max'] . '°C';
                        } else {
                            $temperatureRange = 'Room temp';
                        }
                        ?>

                        <tr>
                            <td class="storage-id"><?= e(storage_code((int)$row['id'])) ?></td>
                            <td class="storage-name"><?= e($row['name']) ?></td>
                            <td><?= e($row['type']) ?></td>
                            <td><?= e($row['laboratory_name'] ?: 'Not assigned') ?></td>
                            <td style="min-width:150px">
                                <?php $usagePercent = (int)$row['capacity'] > 0 ? min(100, round(((int)$row['used_capacity'] / (int)$row['capacity']) * 100)) : 0; ?>
                                <div class="fw-semibold"><?= number_format((int)$row['used_capacity']) ?>/<?= number_format((int)$row['capacity']) ?> samples</div>
                                <div class="storage-capacity-line"><div class="storage-capacity-fill" style="width:<?= $usagePercent ?>%"></div></div>
                                <div class="small text-muted mt-1"><?= $usagePercent ?>% used</div>
                            </td>
                            <td><?= e($temperatureRange) ?></td>

                            <td>
                                <?php if ($row['last_temperature'] !== null): ?>
                                    <?= e($row['last_temperature']) ?>°C
                                    <div class="small text-muted">
                                        <?= e(date('Y-m-d H:i', strtotime($row['last_logged_at']))) ?>
                                    </div>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="storage-status <?= e(storage_status_class($row['status'])) ?>">
                                    <?= e($row['status']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="d-flex gap-2">
                                    <?php if ($canLogTemperature): ?>
                                        <a
                                            href="?temperature=<?= (int)$row['id'] ?>"
                                            class="btn btn-outline-primary storage-action"
                                            title="Log temperature"
                                        >
                                            <i class="bi bi-thermometer-half"></i>
                                        </a>
                                    <?php endif; ?>

                                    <a
                                        href="view.php?id=<?= (int)$row['id'] ?>"
                                        class="btn btn-outline-secondary storage-action"
                                        title="View details"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <a
                                        href="?edit=<?= (int)$row['id'] ?>"
                                        class="btn btn-outline-success storage-action"
                                        title="Edit"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                    <form
                                        method="post"
                                        onsubmit="return confirm('Delete this storage space?');"
                                    >
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_space">
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

                                        <button
                                            type="submit"
                                            class="btn btn-outline-danger storage-action"
                                            title="Delete"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>

    <div class="row g-4">
        <?php if (!$rows): ?>
            <div class="col-12">
                <div class="card text-center py-5">
                    <i class="bi bi-archive display-6 text-muted"></i>
                    <div class="mt-3 fw-semibold">No storage spaces are currently available.</div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($rows as $row): ?>
            <?php
            $availableCapacity = max(0, (int)$row['capacity'] - (int)$row['used_capacity']);
            $canReserve = $isRegularUser
                && in_array($row['status'], ['Available', 'Partially Available'], true)
                && $availableCapacity > 0;

            if ($row['temp_min'] !== null && $row['temp_max'] !== null) {
                $temperatureRange = ((float)$row['temp_min'] === (float)$row['temp_max'])
                    ? $row['temp_min'] . '°C'
                    : $row['temp_min'] . '–' . $row['temp_max'] . '°C';
            } else {
                $temperatureRange = 'Room temp';
            }
            ?>

            <div class="col-md-6 col-xl-4">
                <div class="card student-storage-card">
                    <div class="student-storage-top">
                        <i class="bi <?= e(storage_icon($row['type'])) ?> student-storage-icon"></i>

                        <span class="storage-status student-storage-badge <?= e(storage_status_class($row['status'])) ?>">
                            <?= e($row['status']) ?>
                        </span>
                    </div>

                    <div class="student-storage-body">
                        <div class="student-storage-type"><?= e($row['type']) ?></div>
                        <h5 class="mt-1 mb-2"><?= e($row['name']) ?></h5>

                        <p class="text-muted mb-3">
                            <?= e($row['description'] ?: 'Laboratory storage space.') ?>
                        </p>

                        <div class="student-storage-meta">
                            <div class="mb-1">
                                <i class="bi bi-geo-alt me-1"></i>
                                <?= e($row['laboratory_name'] ?: 'Not assigned') ?>
                            </div>

                            <div>
                                <i class="bi bi-boxes me-1"></i>
                                <?= number_format((int)$row['used_capacity']) ?>/<?= number_format((int)$row['capacity']) ?> samples used
                                · <?= e($temperatureRange) ?>
                            </div>

                            <div class="mt-1">
                                <i class="bi bi-check2-circle me-1"></i>
                                <?= number_format($availableCapacity) ?> samples available
                            </div>

                            <?php $usagePercent = (int)$row['capacity'] > 0 ? min(100, round(((int)$row['used_capacity'] / (int)$row['capacity']) * 100)) : 0; ?>
                            <div class="storage-capacity-line"><div class="storage-capacity-fill" style="width:<?= $usagePercent ?>%"></div></div>
                            <div class="storage-card-footer-note"><span><?= $usagePercent ?>% used</span><span><?= number_format($availableCapacity) ?> samples available</span></div>
                        </div>

                        <div class="student-storage-actions">
                            <a href="view.php?id=<?= (int)$row['id'] ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-eye me-1"></i>View Details
                            </a>

                            <?php if ($canReserve): ?>
                                <a
                                    href="reserve.php?storage_id=<?= (int)$row['id'] ?>"
                                    class="btn btn-success"
                                >
                                    <i class="bi bi-calendar-plus me-1"></i>
                                    Reserve
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary" disabled>
                                    Not Available
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require '../../includes/footer.php'; ?>