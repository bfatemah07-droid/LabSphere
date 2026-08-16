<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();

$canManage = in_array(
    user()['role'],
    ['Supervisor', 'Admin'],
    true
);


$materialColumns = $pdo->query(
    'SHOW COLUMNS FROM materials'
)->fetchAll();

$materialColumnNames = array_column(
    $materialColumns,
    'Field'
);

$hasUsageInstructions = in_array(
    'usage_instructions',
    $materialColumnNames,
    true
);

$hasSafetyGuidelines = in_array(
    'safety_guidelines',
    $materialColumnNames,
    true
);

/*
|--------------------------------------------------------------------------
| Update Material
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Supervisor', 'Admin']);
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    if ($id <= 0) {
        flash('danger', 'Invalid material.');
        header('Location: index.php');
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'Liquid';
    $description = trim($_POST['description'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $safetyNotes = trim($_POST['safety_notes'] ?? '');

    $usageInstructions =
        trim($_POST['usage_instructions'] ?? '');

    $safetyGuidelines =
        trim($_POST['safety_guidelines'] ?? '');

    $labId = !empty($_POST['lab_id'])
        ? (int) $_POST['lab_id']
        : null;

    $quantity = max(
        0,
        (float) ($_POST['available_quantity'] ?? 0)
    );

    $lowStockThreshold = max(
        0,
        (float) ($_POST['low_stock_threshold'] ?? 0)
    );

    $maxStockLevel = max(
        0,
        (float) ($_POST['max_stock_level'] ?? 0)
    );

    $expiryDate = !empty($_POST['expiry_date'])
        ? $_POST['expiry_date']
        : null;

    if ($name === '') {
        flash('danger', 'Name is required.');
        header('Location: index.php?edit=' . $id);
        exit;
    }

    if (!in_array($type, ['Gas', 'Liquid', 'Solid'], true)) {
        $type = 'Liquid';
    }

    if (!in_array($unit, ['L', 'g'], true)) {
        $unit = 'L';
    }

    $updateFields = [
        'lab_id = ?',
        'name = ?',
        'type = ?',
        'description = ?',
        'available_quantity = ?',
        'unit = ?',
        'low_stock_threshold = ?',
        'max_stock_level = ?',
        'safety_notes = ?',
        'expiry_date = ?'
    ];

    $updateValues = [
        $labId,
        $name,
        $type,
        $description,
        $quantity,
        $unit,
        $lowStockThreshold,
        $maxStockLevel,
        $safetyNotes,
        $expiryDate
    ];

    if ($hasUsageInstructions) {
        $updateFields[] = 'usage_instructions = ?';
        $updateValues[] = $usageInstructions;
    }

    if ($hasSafetyGuidelines) {
        $updateFields[] = 'safety_guidelines = ?';
        $updateValues[] = $safetyGuidelines;
    }

    $updateValues[] = $id;

    $statement = $pdo->prepare(
        'UPDATE materials
         SET ' . implode(', ', $updateFields) . '
         WHERE id = ?'
    );

    $statement->execute($updateValues);

    audit($pdo, 'UPDATE', 'Materials', $name);
    flash('success', 'Material updated successfully.');

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Delete Material
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {
    require_role(['Admin']);

    $materialId = (int) $_GET['delete'];

    $statement = $pdo->prepare(
        'DELETE FROM materials WHERE id = ?'
    );

    $statement->execute([$materialId]);

    flash('success', 'Material deleted successfully.');
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$labFilter = (int) ($_GET['lab_id'] ?? 0);
$stockFilter = trim($_GET['stock'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(materials.name LIKE ? OR materials.description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if (in_array($typeFilter, ['Gas', 'Liquid', 'Solid'], true)) {
    $where[] = 'materials.type = ?';
    $params[] = $typeFilter;
}

if ($labFilter > 0) {
    $where[] = 'materials.lab_id = ?';
    $params[] = $labFilter;
}

$notExpiredCondition = '(materials.expiry_date IS NULL OR materials.expiry_date >= CURDATE())';

if ($stockFilter === 'available') {
    $where[] = $notExpiredCondition;
    $where[] = 'materials.available_quantity > materials.low_stock_threshold';
} elseif ($stockFilter === 'low') {
    $where[] = $notExpiredCondition;
    $where[] = 'materials.available_quantity > 0
                AND materials.available_quantity <= materials.low_stock_threshold';
} elseif ($stockFilter === 'out') {
    $where[] = $notExpiredCondition;
    $where[] = 'materials.available_quantity <= 0';
} elseif ($stockFilter === 'expired') {
    $where[] = 'materials.expiry_date IS NOT NULL
                AND materials.expiry_date < CURDATE()';
}

$sql =
    'SELECT
        materials.*,
        laboratories.name AS lab_name
     FROM materials
     LEFT JOIN laboratories
        ON laboratories.id = materials.lab_id';

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY materials.id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$materials = $statement->fetchAll();

$laboratories = $pdo->query(
    'SELECT id, name
     FROM laboratories
     ORDER BY name'
)->fetchAll();

/*
|--------------------------------------------------------------------------
| Edit Material
|--------------------------------------------------------------------------
*/

$editMaterial = null;

if ($canManage && isset($_GET['edit'])) {
    $statement = $pdo->prepare(
        'SELECT * FROM materials WHERE id = ?'
    );

    $statement->execute([
        (int) $_GET['edit']
    ]);

    $editMaterial = $statement->fetch();

    if (!$editMaterial) {
        flash('danger', 'Material not found.');
        header('Location: index.php');
        exit;
    }
}

$page_title = 'Materials';
require '../../includes/header.php';

?>

<style>
    .materials-page-header { margin-bottom: 1.5rem; }

    .materials-filter-card,
    .material-card,
    .material-edit-card {
        border: 1px solid #e5eaf1;
        border-radius: 16px;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
    }

    .material-card {
        height: 100%;
        overflow: hidden;
        cursor: pointer;
        background: #fff;
        transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }

    .material-card:hover {
        transform: translateY(-3px);
        border-color: #d8e0ea;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .09);
    }

    .material-card-visual {
        position: relative;
        height: 175px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        border-bottom: 1px solid #eef2f7;
        background: linear-gradient(180deg, #ffffff 0%, #fbfcfe 100%);
    }

    .material-3d-icon,
    .material-modal-icon {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 26px;
        isolation: isolate;
    }

    .material-3d-icon {
        width: 100px;
        height: 100px;
        font-size: 3rem;
        transform: perspective(500px) rotateX(5deg) rotateY(-7deg);
        box-shadow:
            0 18px 30px rgba(15, 23, 42, .14),
            inset 0 2px 2px rgba(255, 255, 255, .95),
            inset 0 -8px 14px rgba(15, 23, 42, .08);
    }

    .material-3d-icon::before,
    .material-modal-icon::before {
        content: "";
        position: absolute;
        inset: 8px;
        border-radius: 20px;
        background: linear-gradient(145deg, rgba(255,255,255,.92), rgba(255,255,255,.18));
        z-index: -1;
    }

    .material-3d-icon i,
    .material-modal-icon i {
        filter: drop-shadow(0 7px 7px rgba(15, 23, 42, .22));
    }

    .material-icon-gas {
        color: #7c3aed;
        background: linear-gradient(145deg, #f5efff, #ddd0ff);
    }

    .material-icon-liquid {
        color: #0d6efd;
        background: linear-gradient(145deg, #eef6ff, #cfe2ff);
    }

    .material-icon-solid {
        color: #d97706;
        background: linear-gradient(145deg, #fff7e8, #ffe0a8);
    }

    .material-status {
        position: absolute;
        top: 14px;
        right: 14px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: .72rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .material-status::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .status-available { color: #079455; background: #e8f7ef; }
    .status-low,
    .status-expired { color: #b66a00; background: #fff4dd; }
    .status-out { color: #d92d20; background: #feecec; }

    .material-card-body {
        min-height: 300px;
        display: flex;
        flex-direction: column;
        padding: 1rem;
    }

    .material-name {
        margin: 0 0 .15rem;
        color: #172033;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.3;
        overflow-wrap: anywhere;
    }

    .material-type-text {
        margin-bottom: .85rem;
        color: #0d6efd;
        font-size: .82rem;
        font-weight: 600;
    }

    .material-info-list {
        padding-top: .85rem;
        border-top: 1px solid #eef2f7;
    }

    .material-info-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: .72rem;
        color: #526278;
        font-size: .88rem;
        line-height: 1.4;
    }

    .material-info-item i {
        width: 18px;
        flex: 0 0 18px;
        margin-top: 2px;
        color: #7890aa;
        text-align: center;
    }

    .material-info-value { overflow-wrap: anywhere; }

    .stock-area { margin: .15rem 0 .85rem; }

    .stock-progress {
        height: 7px;
        border-radius: 999px;
        background: #edf1f5;
    }

    .stock-progress .progress-bar { border-radius: 999px; }

    .material-actions {
        margin-top: auto;
        display: flex;
        gap: 7px;
        align-items: stretch;
    }

    .material-actions .material-reserve-btn {
        flex: 1;
    }

    .material-modal .modal-content {
        border: 0;
        border-radius: 18px;
        overflow: hidden;
    }

    .material-modal .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e8edf3;
    }

    .material-modal .modal-body { padding: 1.5rem; }

    .material-modal-section {
        height: 100%;
        padding: 1rem;
        border: 1px solid #e5eaf1;
        border-radius: 14px;
        background: #fff;
    }

    .material-modal-section h6 {
        margin-bottom: .75rem;
        font-weight: 700;
    }

    .material-modal-value {
        color: #475569;
        white-space: pre-line;
        overflow-wrap: anywhere;
    }

    .material-modal-icon {
        width: 72px;
        height: 72px;
        font-size: 2rem;
        box-shadow: 0 10px 20px rgba(15, 23, 42, .12), inset 0 2px 2px rgba(255,255,255,.9);
    }

    .material-edit-wrapper {
        max-width: 980px;
        margin: 0 auto 1.5rem;
    }

    .material-edit-card .card-body { padding: 1.5rem; }

    .material-edit-card .form-label {
        margin-bottom: .4rem;
        font-weight: 600;
    }

    .material-edit-card .form-control,
    .material-edit-card .form-select { min-height: 42px; }

    @media (max-width: 767.98px) {
        .material-edit-card .card-body { padding: 1rem; }
        .material-card-visual { height: 155px; }
        .material-3d-icon { width: 88px; height: 88px; font-size: 2.55rem; }
    }
</style>

<?php if ($editMaterial): ?>
    <div class="material-edit-wrapper">
        <div class="d-flex align-items-center gap-3 mb-3">
            <a href="index.php" class="btn btn-outline-secondary" aria-label="Back">
                <i class="bi bi-arrow-left"></i>
            </a>
            <div>
                <h2 class="mb-1">Edit Material</h2>
                <p class="text-muted mb-0">Update the material information below.</p>
            </div>
        </div>
        <div class="card material-edit-card">
            <div class="card-body">
                <div class="d-flex justify-content-end mb-2">
                    <a href="index.php" class="btn-close" aria-label="Close"></a>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" value="<?= (int) $editMaterial['id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input
                                type="text"
                                name="name"
                                class="form-control"
                                required
                                value="<?= e($editMaterial['name']) ?>"
                            >
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select" required>
                                <?php foreach (['Gas', 'Liquid', 'Solid'] as $materialType): ?>
                                    <option
                                        value="<?= e($materialType) ?>"
                                        <?= $editMaterial['type'] === $materialType ? 'selected' : '' ?>
                                    >
                                        <?= e($materialType) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Laboratory</label>
                            <select name="lab_id" class="form-select">
                                <option value="">None</option>
                                <?php foreach ($laboratories as $laboratory): ?>
                                    <option
                                        value="<?= (int) $laboratory['id'] ?>"
                                        <?= (int) ($editMaterial['lab_id'] ?? 0) === (int) $laboratory['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($laboratory['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Description</label>
                            <textarea
                                name="description"
                                class="form-control"
                                rows="2"
                            ><?= e($editMaterial['description'] ?? '') ?></textarea>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label">Quantity</label>
                            <input
                                type="text"
                                name="available_quantity"
                                class="form-control"
                                inputmode="decimal"
                                pattern="^\d+(\.\d+)?$"
                                required
                                placeholder="Example: 1.5"
                                value="<?= e($editMaterial['available_quantity']) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label">Unit</label>
                            <select name="unit" class="form-select" required>
                                <option value="L" <?= $editMaterial['unit'] === 'L' ? 'selected' : '' ?>>
                                    Liter (L)
                                </option>
                                <option value="g" <?= $editMaterial['unit'] === 'g' ? 'selected' : '' ?>>
                                    Gram (g)
                                </option>
                            </select>
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label">Low-stock threshold</label>
                            <input
                                type="text"
                                name="low_stock_threshold"
                                class="form-control"
                                inputmode="decimal"
                                pattern="^\d+(\.\d+)?$"
                                placeholder="Default: 0.1"
                                value="<?= e($editMaterial['low_stock_threshold']) ?>"
                            >
                        </div>

                        <div class="col-sm-6 col-md-3">
                            <label class="form-label">Maximum stock</label>
                            <input
                                type="text"
                                name="max_stock_level"
                                class="form-control"
                                inputmode="decimal"
                                pattern="^\d+(\.\d+)?$"
                                placeholder="Optional"
                                value="<?= e($editMaterial['max_stock_level']) ?>"
                            >
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Expiry date</label>
                            <input
                                type="date"
                                name="expiry_date"
                                class="form-control"
                                value="<?= e($editMaterial['expiry_date'] ?? '') ?>"
                            >
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Safety notes</label>
                            <textarea
                                name="safety_notes"
                                class="form-control"
                                rows="2"
                            ><?= e($editMaterial['safety_notes'] ?? '') ?></textarea>
                        </div>


                        <?php if ($hasUsageInstructions): ?>
                            <div class="col-md-6">
                                <label class="form-label">Usage Instructions</label>
                                <textarea
                                    name="usage_instructions"
                                    class="form-control"
                                    rows="4"
                                ><?= e($editMaterial['usage_instructions'] ?? '') ?></textarea>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasSafetyGuidelines): ?>
                            <div class="col-md-6">
                                <label class="form-label">Safety Guidelines</label>
                                <textarea
                                    name="safety_guidelines"
                                    class="form-control"
                                    rows="4"
                                ><?= e($editMaterial['safety_guidelines'] ?? '') ?></textarea>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="index.php" class="btn btn-light">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>
                            Update Material
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php else: ?>

<div class="materials-page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h2 class="mb-1">Laboratory Materials</h2>
        <p class="text-muted mb-0">
            Browse laboratory materials and monitor available stock.
        </p>
    </div>

    <?php if ($canManage): ?>
        <a href="create.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>
            Add Material
        </a>
    <?php endif; ?>
</div>

<div class="card materials-filter-card mb-4">
    <div class="card-body">
        <form method="get" id="materialsFilterForm" class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label">Search</label>
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search by name or description..."
                    value="<?= e($search) ?>"
                >
            </div>

            <div class="col-sm-6 col-lg-2">
                <label class="form-label">Type</label>
                <select name="type" class="form-select material-auto-filter">
                    <option value="">All types</option>
                    <option value="Gas" <?= $typeFilter === 'Gas' ? 'selected' : '' ?>>Gas</option>
                    <option value="Liquid" <?= $typeFilter === 'Liquid' ? 'selected' : '' ?>>Liquid</option>
                    <option value="Solid" <?= $typeFilter === 'Solid' ? 'selected' : '' ?>>Solid</option>
                </select>
            </div>

            <div class="col-sm-6 col-lg-3">
                <label class="form-label">Laboratory</label>
                <select name="lab_id" class="form-select material-auto-filter">
                    <option value="">All laboratories</option>
                    <?php foreach ($laboratories as $laboratory): ?>
                        <option
                            value="<?= (int) $laboratory['id'] ?>"
                            <?= $labFilter === (int) $laboratory['id'] ? 'selected' : '' ?>
                        >
                            <?= e($laboratory['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-sm-6 col-lg-2">
                <label class="form-label">Stock</label>
                <select name="stock" class="form-select material-auto-filter">
                    <option value="">All stock</option>
                    <option value="available" <?= $stockFilter === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low stock</option>
                    <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of stock</option>
                    <option value="expired" <?= $stockFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                </select>
            </div>

            <div class="col-sm-6 col-lg-1 d-flex gap-2">
                <button type="submit" class="btn btn-outline-primary flex-fill" title="Search">
                    <i class="bi bi-search"></i>
                </button>

                <a href="index.php" class="btn btn-outline-secondary" title="Reset">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<?php if (empty($materials)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-box-seam fs-1 text-muted"></i>
            <h5 class="mt-3">No materials found</h5>
            <p class="text-muted mb-0">
                No materials match the selected filters.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($materials as $material): ?>
            <?php
                $quantity = (float) $material['available_quantity'];
                $lowThreshold = (float) $material['low_stock_threshold'];
                $maxStock = (float) $material['max_stock_level'];

                $isExpired = !empty($material['expiry_date'])
                    && $material['expiry_date'] < date('Y-m-d');

                if ($isExpired) {
                    $stockLabel = 'Expired';
                    $stockBadge = 'text-bg-warning';
                } elseif ($quantity <= 0) {
                    $stockLabel = 'Out of Stock';
                    $stockBadge = 'text-bg-danger';
                } elseif ($quantity <= $lowThreshold) {
                    $stockLabel = 'Low Stock';
                    $stockBadge = 'text-bg-warning';
                } else {
                    $stockLabel = 'Available';
                    $stockBadge = 'text-bg-success';
                }

                $progressPercent = 0;

                if ($maxStock > 0) {
                    $progressPercent = min(
                        100,
                        max(0, ($quantity / $maxStock) * 100)
                    );
                }

                if ($material['type'] === 'Gas') {
                    $iconClass = 'bi-cloud-fill';
                    $iconColorClass = 'material-icon-gas';
                } elseif ($material['type'] === 'Solid') {
                    $iconClass = 'bi-box-fill';
                    $iconColorClass = 'material-icon-solid';
                } else {
                    $iconClass = 'bi-droplet-fill';
                    $iconColorClass = 'material-icon-liquid';
                }
            ?>

            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <div
                    class="card material-card"
                    role="button"
                    tabindex="0"
                    onclick="openMaterialModal(<?= (int) $material['id'] ?>)"
                    onkeydown="if(event.key === 'Enter' || event.key === ' '){event.preventDefault();openMaterialModal(<?= (int) $material['id'] ?>);}"
                >
                    <?php
                        if ($isExpired) {
                            $statusClass = 'status-expired';
                        } elseif ($quantity <= 0) {
                            $statusClass = 'status-out';
                        } elseif ($quantity <= $lowThreshold) {
                            $statusClass = 'status-low';
                        } else {
                            $statusClass = 'status-available';
                        }
                    ?>

                    <div class="material-card-visual">
                        <div class="material-3d-icon <?= e($iconColorClass) ?>">
                            <i class="bi <?= e($iconClass) ?>"></i>
                        </div>

                        <span class="material-status <?= e($statusClass) ?>">
                            <?= e($stockLabel) ?>
                        </span>
                    </div>

                    <div class="material-card-body">
                        <h4 class="material-name">
                            <?= e($material['name']) ?>
                        </h4>

                        <div class="material-type-text">
                            <?= e($material['type']) ?>
                        </div>

                        <div class="material-info-list">
                            <div class="material-info-item">
                                <i class="bi bi-building"></i>
                                <span class="material-info-value">
                                    <?= !empty($material['lab_name'])
                                        ? e($material['lab_name'])
                                        : 'Not assigned'
                                    ?>
                                </span>
                            </div>

                            <div class="material-info-item">
                                <i class="bi bi-boxes"></i>
                                <span class="material-info-value">
                                    <?= e($material['available_quantity'] . ' ' . $material['unit']) ?>
                                </span>
                            </div>

                            <div class="material-info-item">
                                <i class="bi bi-calendar3"></i>
                                <span class="material-info-value">
                                    <?= !empty($material['expiry_date'])
                                        ? e($material['expiry_date'])
                                        : 'No expiry date'
                                    ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($maxStock > 0): ?>
                            <div class="stock-area">
                                <div class="d-flex justify-content-between small mb-2">
                                    <span class="text-muted">Stock level</span>
                                    <span class="fw-semibold"><?= number_format($progressPercent, 0) ?>%</span>
                                </div>

                                <div class="progress stock-progress">
                                    <div
                                        class="progress-bar"
                                        role="progressbar"
                                        style="width: <?= number_format($progressPercent, 2, '.', '') ?>%;"
                                        aria-valuenow="<?= number_format($progressPercent, 0) ?>"
                                        aria-valuemin="0"
                                        aria-valuemax="100"
                                    ></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="material-actions">
                            <?php if (!$isExpired && $quantity > 0): ?>
                                <a
                                    href="../reservations/create.php?type=Material&selection_id=<?= (int) $material['id'] ?>"
                                    class="btn btn-primary btn-sm material-reserve-btn"
                                    onclick="event.stopPropagation();"
                                >
                                    Reserve
                                </a>
                            <?php else: ?>
                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm material-reserve-btn"
                                    disabled
                                    onclick="event.stopPropagation();"
                                >
                                    Unavailable
                                </button>
                            <?php endif; ?>

                            <?php if ($canManage): ?>
                                <a
                                    href="?edit=<?= (int) $material['id'] ?>"
                                    class="btn btn-outline-primary btn-sm"
                                    title="Edit"
                                    aria-label="Edit"
                                    onclick="event.stopPropagation();"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            <?php endif; ?>

                            <?php if (user()['role'] === 'Admin'): ?>
                                <a
                                    href="?delete=<?= (int) $material['id'] ?>"
                                    class="btn btn-outline-danger btn-sm"
                                    title="Delete"
                                    aria-label="Delete"
                                    onclick="event.stopPropagation(); return confirm('Are you sure you want to delete this material?');"
                                >
                                    <i class="bi bi-trash"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div
                class="modal fade material-modal"
                id="materialModal<?= (int) $material['id'] ?>"
                tabindex="-1"
                aria-hidden="true"
            >
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div class="d-flex align-items-center gap-3">
                                <div class="material-modal-icon <?= e($iconColorClass) ?>">
                                    <i class="bi <?= e($iconClass) ?>"></i>
                                </div>
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <h4 class="mb-0"><?= e($material['name']) ?></h4>
                                        <span class="badge text-bg-light border"><?= e($material['type']) ?></span>
                                        <span class="badge <?= e($stockBadge) ?>"><?= e($stockLabel) ?></span>
                                    </div>
                                    <div class="text-muted"><?= e($material['lab_name'] ?: 'Not assigned') ?></div>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            <div class="row g-3 mb-3">
                                <div class="col-sm-6 col-lg-4">
                                    <div class="material-modal-section">
                                        <h6><i class="bi bi-boxes me-1"></i>Available Quantity</h6>
                                        <div class="fs-5 fw-bold"><?= e($material['available_quantity'] . ' ' . $material['unit']) ?></div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-4">
                                    <div class="material-modal-section">
                                        <h6><i class="bi bi-calendar3 me-1"></i>Expiry Date</h6>
                                        <div class="fw-semibold"><?= e($material['expiry_date'] ?: 'No expiry date') ?></div>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-lg-4">
                                    <div class="material-modal-section">
                                        <h6><i class="bi bi-speedometer2 me-1"></i>Stock Status</h6>
                                        <span class="badge <?= e($stockBadge) ?>"><?= e($stockLabel) ?></span>
                                    </div>
                                </div>
                            </div>

                            <?php if (trim((string) ($material['description'] ?? '')) !== ''): ?>
                                <div class="material-modal-section mb-3">
                                    <h6>Description</h6>
                                    <div class="material-modal-value"><?= e($material['description']) ?></div>
                                </div>
                            <?php endif; ?>

                            <?php
                                $usageText = $hasUsageInstructions
                                    ? trim((string) ($material['usage_instructions'] ?? ''))
                                    : '';

                                $safetyText = $hasSafetyGuidelines
                                    ? trim((string) ($material['safety_guidelines'] ?? ''))
                                    : '';

                                if ($safetyText === '') {
                                    $safetyText = trim((string) ($material['safety_notes'] ?? ''));
                                }
                            ?>

                            <?php if ($usageText !== '' || $safetyText !== ''): ?>
                                <div class="row g-3">
                                    <?php if ($usageText !== ''): ?>
                                        <div class="col-md-6">
                                            <div class="material-modal-section">
                                                <h6>Usage Instructions</h6>
                                                <div class="material-modal-value"><?= e($usageText) ?></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($safetyText !== ''): ?>
                                        <div class="col-md-6">
                                            <div class="material-modal-section">
                                                <h6>Safety Guidelines</h6>
                                                <div class="material-modal-value"><?= e($safetyText) ?></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                            <?php if (!$isExpired && $quantity > 0): ?>
                                <a
                                    href="../reservations/create.php?type=Material&selection_id=<?= (int) $material['id'] ?>"
                                    class="btn btn-primary"
                                >
                                    <i class="bi bi-calendar-check me-1"></i>
                                    Request Material
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php endif; ?>

<script>
function openMaterialModal(materialId) {
    const modalElement = document.getElementById(
        'materialModal' + materialId
    );

    if (!modalElement) {
        return;
    }

    const modal = bootstrap.Modal.getOrCreateInstance(
        modalElement
    );

    modal.show();
}

document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.getElementById('materialsFilterForm');

    if (!filterForm) {
        return;
    }

    document.querySelectorAll('.material-auto-filter').forEach(function (field) {
        field.addEventListener('change', function () {
            filterForm.submit();
        });
    });
});
</script>

<?php require '../../includes/footer.php'; ?>