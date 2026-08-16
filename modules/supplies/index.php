<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();

$currentUser = user();

$canManage = in_array(
    $currentUser['role'],
    ['Supervisor', 'Admin'],
    true
);

$canReserve = in_array($currentUser['role'], ['Student', 'User'], true);

/*
|--------------------------------------------------------------------------
| Delete supply item
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {

    require_role(['Supervisor', 'Admin']);

    $supplyId = (int) $_GET['delete'];

    try {

        $statement = $pdo->prepare(
            'DELETE FROM supplies
             WHERE id = ?'
        );

        $statement->execute([$supplyId]);

        audit(
            $pdo,
            'DELETE',
            'Supplies',
            'Supply ID: ' . $supplyId
        );

        flash('success', 'Supply item deleted successfully.');

    } catch (PDOException $exception) {

        flash(
            'danger',
            'This supply item could not be deleted.'
        );
    }

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Search and filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$status = trim($_GET['status'] ?? '');

$categories = ['PPE', 'Consumable', 'Glassware', 'Other'];

$sql = '
    SELECT
        supplies.*,
        laboratories.name AS laboratory_name
    FROM supplies
    LEFT JOIN laboratories
        ON laboratories.id = supplies.lab_id
    WHERE 1 = 1
';

$parameters = [];

if ($search !== '') {

    $sql .= '
        AND (
            supplies.code LIKE ?
            OR supplies.name LIKE ?
            OR supplies.unit LIKE ?
            OR supplies.category LIKE ?
            OR laboratories.name LIKE ?
        )
    ';

    $searchValue = '%' . $search . '%';

    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
}

if ($category !== '') {

    $sql .= ' AND supplies.category = ?';
    $parameters[] = $category;
}

$sql .= ' ORDER BY supplies.id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($parameters);

$rows = $statement->fetchAll();

/*
|--------------------------------------------------------------------------
| Status filter
|--------------------------------------------------------------------------
*/

if ($status !== '') {

    $rows = array_values(
        array_filter(
            $rows,
            function ($supply) use ($status) {

                $quantity = (float) $supply['quantity'];
                $threshold = (float) $supply['low_stock_threshold'];

                if ($quantity <= 0) {
                    $currentStatus = 'Out of Stock';
                } elseif ($quantity <= $threshold) {
                    $currentStatus = 'Low Stock';
                } else {
                    $currentStatus = 'Available';
                }

                return $currentStatus === $status;
            }
        )
    );
}

$page_title = 'Supplies';

require '../../includes/header.php';

?>

<style>
    .supplies-toolbar {
        border: 1px solid #e7edf4;
        border-radius: 16px;
    }

    .supplies-grid {
        margin-bottom: 1rem;
    }

    .supply-card {
        height: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        overflow: hidden;
        transition:
            transform 0.2s ease,
            box-shadow 0.2s ease,
            border-color 0.2s ease;
    }

    .supply-card:hover {
        transform: translateY(-4px);
        border-color: #cbd5e1;
        box-shadow: 0 14px 32px rgba(15, 23, 42, 0.09);
    }

    .supply-card-header {
        padding: .9rem .9rem 0;
    }

    .supply-card-body {
        padding: .9rem;
    }

    .supply-card-footer {
        padding: 0 .9rem .9rem;
        background: transparent;
        border-top: 0;
    }

    .supply-code {
        display: inline-block;
        margin-bottom: 0.35rem;
        color: #64748b;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .supply-name {
        margin-bottom: 0;
        color: #172033;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .category-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        max-width: 100%;
        padding: 6px 10px;
        color: #475569;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .status-badge::before {
        content: "";
        width: 7px;
        height: 7px;
        flex: 0 0 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .status-available {
        color: #079455;
        background: #e8f7ef;
    }

    .status-low {
        color: #b66a00;
        background: #fff4dd;
    }

    .status-out {
        color: #d92d20;
        background: #feecec;
    }

    .supply-info-list {
        display: flex;
        flex-direction: column;
        gap: 0;
        margin-top: 1rem;
        border-top: 1px solid #edf1f5;
    }

    .supply-info-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        padding: .45rem 0;
        border-bottom: 1px solid #edf1f5;
    }

    .supply-info-row:last-child {
        border-bottom: 0;
    }

    .supply-info-label {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #64748b;
        font-size: 0.86rem;
        white-space: nowrap;
    }

    .supply-info-label i {
        color: #0d6efd;
    }

    .supply-info-value {
        color: #172033;
        font-size: 0.9rem;
        font-weight: 600;
        text-align: right;
        overflow-wrap: anywhere;
    }

    .supply-quantity {
        color: #0d6efd;
        font-size: 1.05rem;
        font-weight: 700;
    }

    .supply-actions {
        display: flex;
        gap: 7px;
        align-items: stretch;
    }

    .supply-actions .reserve-btn {
        flex: 1;
    }

    .empty-state {
        padding: 4rem 1.5rem;
        border: 1px dashed #cbd5e1;
        border-radius: 18px;
        background: #ffffff;
        text-align: center;
    }

    @media (max-width: 575.98px) {
        .supply-card-header,
        .supply-card-body {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .supply-card-footer {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .supply-info-row {
            gap: 0.65rem;
        }

        .supply-info-value {
            max-width: 58%;
        }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h2 class="mb-1">Supplies</h2>

        <p class="text-muted mb-0">
            Consumables and countable laboratory supplies.
        </p>
    </div>

    <?php if ($canManage): ?>

        <a
            href="create.php"
            class="btn btn-primary"
        >
            <i class="bi bi-plus-lg me-1"></i>
            Add Supply Item
        </a>

    <?php endif; ?>

</div>

<div class="card p-3 mb-4 supplies-toolbar">

    <form method="get">

        <div class="row g-2 align-items-end">

            <div class="col-lg-5">

                <label
                    for="q"
                    class="form-label"
                >
                    Search
                </label>

                <input
                    type="search"
                    id="q"
                    name="q"
                    class="form-control"
                    placeholder="Search by ID, name, category, unit or laboratory..."
                    value="<?= e($search) ?>"
                >

            </div>

            <div class="col-lg-3">

                <label
                    for="category"
                    class="form-label"
                >
                    Category
                </label>

                <select
                    id="category"
                    name="category"
                    class="form-select"
                >

                    <option value="">All categories</option>

                    <?php foreach ($categories as $categoryOption): ?>

                        <option
                            value="<?= e($categoryOption) ?>"
                            <?= $category === $categoryOption
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($categoryOption) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-lg-2">

                <label
                    for="status"
                    class="form-label"
                >
                    Status
                </label>

                <select
                    id="status"
                    name="status"
                    class="form-select"
                >

                    <option value="">All statuses</option>

                    <?php foreach (
                        ['Available', 'Low Stock', 'Out of Stock']
                        as $statusOption
                    ): ?>

                        <option
                            value="<?= e($statusOption) ?>"
                            <?= $status === $statusOption
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($statusOption) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="col-lg-2">

                <div class="d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-outline-primary flex-fill"
                        title="Search"
                    >
                        <i class="bi bi-search"></i>
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-outline-secondary"
                        title="Reset"
                    >
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>

                </div>

            </div>

        </div>

    </form>

</div>

<?php if (empty($rows)): ?>

    <div class="empty-state">

        <i class="bi bi-box-seam display-5 text-muted"></i>

        <div class="mt-3 fw-semibold">
            No supply items found
        </div>

        <div class="text-muted small mt-1">
            Add a supply item or change the filters.
        </div>

    </div>

<?php else: ?>

    <div class="row g-4 supplies-grid">

        <?php foreach ($rows as $supply): ?>

            <?php

                $quantity = (float) $supply['quantity'];
                $threshold = (float) $supply['low_stock_threshold'];

                if ($quantity <= 0) {
                    $currentStatus = 'Out of Stock';
                    $statusClass = 'status-out';
                } elseif ($quantity <= $threshold) {
                    $currentStatus = 'Low Stock';
                    $statusClass = 'status-low';
                } else {
                    $currentStatus = 'Available';
                    $statusClass = 'status-available';
                }

                $quantityDisplay = rtrim(
                    rtrim(
                        number_format($quantity, 2, '.', ''),
                        '0'
                    ),
                    '.'
                );

                $laboratoryName = $supply['laboratory_name']
                    ?: 'Not assigned';

                $categoryName = trim((string) $supply['category']) !== ''
                    ? $supply['category']
                    : 'Uncategorized';

            ?>

            <div class="col-12 col-md-6 col-xl-3">

                <div class="card supply-card">

                    <div class="text-center border-bottom py-3">
<?php if(!empty($supply['image_path'])): ?>
<img src="../../<?=e($supply['image_path'])?>" style="height:120px;object-fit:contain;max-width:100%;">
<?php else: ?>
<div style="height:120px;display:flex;align-items:center;justify-content:center;color:#94a3b8;">
<i class="bi bi-box-seam" style="font-size:54px"></i>
</div>
<?php endif; ?>
</div>

<div class="supply-card-header">

                        <div class="d-flex justify-content-between align-items-start gap-3">

                            <div class="flex-grow-1">

                                <span class="supply-code">
                                    <?= e($supply['code']) ?>
                                </span>

                                <h5 class="supply-name">
                                    <?= e($supply['name']) ?>
                                </h5>

                            </div>

                            <span class="status-badge <?= e($statusClass) ?>">
                                <?= e($currentStatus) ?>
                            </span>

                        </div>

                    </div>

                    <div class="supply-card-body">

                        <span class="category-badge">
                            <i class="bi bi-tag"></i>
                            <?= e($categoryName) ?>
                        </span>

                        <div class="supply-info-list">

                            <div class="supply-info-row">

    <span class="supply-info-value">
        <i class="bi bi-upc-scan text-primary me-2"></i>
        <?= e($supply['code']) ?>
    </span>

</div>

                           <div class="supply-info-row">

    <span class="supply-info-value supply-quantity">
        <i class="bi bi-boxes text-primary me-2"></i>
        <?= e($quantityDisplay) ?>
        <?= e($supply['unit']) ?>
    </span>

</div>

                            <div class="supply-info-row">

    <span class="supply-info-value">
        <i class="bi bi-building text-primary me-2"></i>
        <?= e($laboratoryName) ?>
    </span>

</div>

                        </div>

                    </div>

                    <div class="supply-card-footer">

                        <div class="supply-actions">

                            <?php if ($quantity > 0): ?>

                                <a
                                    href="../reservations/create.php?type=Supply&selection_id=<?= (int) $supply['id'] ?>"
                                    class="btn btn-primary btn-sm reserve-btn"
                                >
                                    Reserve
                                </a>

                            <?php else: ?>

                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm reserve-btn"
                                    disabled
                                >
                                    Unavailable
                                </button>

                            <?php endif; ?>

                            <?php if ($canManage): ?>

                                <a
                                    href="create.php?edit=<?= (int) $supply['id'] ?>"
                                    class="btn btn-outline-primary btn-sm"
                                    title="Edit"
                                    aria-label="Edit <?= e($supply['name']) ?>"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>

                                <a
                                    href="?delete=<?= (int) $supply['id'] ?>"
                                    class="btn btn-outline-danger btn-sm"
                                    title="Delete"
                                    aria-label="Delete <?= e($supply['name']) ?>"
                                    onclick="return confirm('Are you sure you want to delete this supply item?');"
                                >
                                    <i class="bi bi-trash"></i>
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

<?php require '../../includes/footer.php'; ?>