<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();

$canManage = in_array(
    user()['role'],
    ['Supervisor', 'Admin'],
    true
);

/*
|--------------------------------------------------------------------------
| Delete Equipment
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {

    require_role(['Supervisor', 'Admin']);

    $equipmentId = (int) $_GET['delete'];

    $statement = $pdo->prepare(
        'SELECT image_path
         FROM equipment
         WHERE id = ?'
    );

    $statement->execute([$equipmentId]);
    $equipmentToDelete = $statement->fetch();

    if ($equipmentToDelete) {

        $statement = $pdo->prepare(
            'DELETE FROM equipment
             WHERE id = ?'
        );

        $statement->execute([$equipmentId]);

        if (!empty($equipmentToDelete['image_path'])) {

            $imageFile =
                __DIR__
                . '/../../'
                . ltrim($equipmentToDelete['image_path'], '/');

            if (is_file($imageFile)) {
                @unlink($imageFile);
            }
        }

        audit(
            $pdo,
            'DELETE',
            'Equipment',
            'Equipment ID: ' . $equipmentId
        );

        flash(
            'success',
            'Equipment deleted successfully.'
        );
    }

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$labFilter = (int) ($_GET['lab_id'] ?? 0);

$allowedStatuses = [
    'Available',
    'Under maintenance',
    'Broken',
    'Expired'
];

if (
    $statusFilter !== ''
    && !in_array($statusFilter, $allowedStatuses, true)
) {
    $statusFilter = '';
}

/*
|--------------------------------------------------------------------------
| Get Equipment
|--------------------------------------------------------------------------
*/

$sql = '
    SELECT
        equipment.*,
        laboratories.name AS lab_name,
        laboratories.location AS lab_location
    FROM equipment
    LEFT JOIN laboratories
        ON laboratories.id = equipment.lab_id
    WHERE 1 = 1
';

$parameters = [];

/*
 * Regular users can only see equipment that is currently available.
 * Supervisors and administrators can see every equipment status.
 */
if (!$canManage) {
    $sql .= " AND equipment.status = 'Available'";
    $statusFilter = '';
}

if ($search !== '') {

    $sql .= '
        AND (
            equipment.name LIKE ?
            OR equipment.serial_number LIKE ?
            OR equipment.category LIKE ?
            OR laboratories.name LIKE ?
        )
    ';

    $searchValue = '%' . $search . '%';

    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
}

if ($statusFilter !== '') {
    $sql .= ' AND equipment.status = ?';
    $parameters[] = $statusFilter;
}

if ($labFilter > 0) {
    $sql .= ' AND equipment.lab_id = ?';
    $parameters[] = $labFilter;
}

$sql .= ' ORDER BY equipment.id DESC';

$statement = $pdo->prepare($sql);
$statement->execute($parameters);
$equipmentItems = $statement->fetchAll();

$laboratories = $pdo->query(
    'SELECT id, name
     FROM laboratories
     ORDER BY name'
)->fetchAll();

$page_title = 'Equipment';

require '../../includes/header.php';

?>

<style>

    .equipment-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        transition:
            transform 0.2s ease,
            box-shadow 0.2s ease,
            border-color 0.2s ease;
        cursor: pointer;
        background: #ffffff;
    }

    .equipment-card:hover {
        transform: translateY(-3px);
        border-color: #b9d2ff;
        box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10);
    }

    .equipment-image {
        width: 100%;
        height: 175px;
        object-fit: contain;
        padding: 12px;
        background: #ffffff;
        border-bottom: 1px solid #edf1f5;
    }

    .equipment-image-placeholder {
        height: 175px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 10px;
        color: #8a98aa;
        background: #f8fafc;
        border-bottom: 1px solid #edf1f5;
    }

    .equipment-image-placeholder i {
        font-size: 54px;
    }

    .equipment-card-body {
        padding: 15px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .equipment-card {
        display: flex;
        flex-direction: column;
    }

    .equipment-name {
        font-size: 1rem;
        font-weight: 700;
        margin-bottom: 5px;
        color: #172033;
        line-height: 1.35;
        min-height: 2.7em;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .equipment-category {
        color: #0d6efd;
        font-size: 0.78rem;
        font-weight: 600;
        margin-top: 2px;
        min-height: 1.2em;
    }

    .equipment-main-info {
        margin-top: 14px;
        display: grid;
        gap: 8px;
        flex: 1;
    }

    .equipment-compact-line {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        color: #475569;
        font-size: 0.86rem;
        line-height: 1.35;
        min-width: 0;
    }

    .equipment-compact-line i {
        width: 16px;
        flex: 0 0 16px;
        color: #6b778c;
        margin-top: 1px;
    }

    .equipment-compact-line span {
        overflow-wrap: anywhere;
    }

    .equipment-price {
        font-weight: 700;
        color: #0d6efd;
    }

    .equipment-actions {
        display: flex;
        gap: 7px;
        margin-top: 14px;
        align-items: stretch;
    }

    .equipment-actions .reserve-button {
        flex: 1;
    }

    .equipment-status {
        font-size: 0.78rem;
    }

    .status-available {
        background: #d1fae5;
        color: #047857;
    }

    .status-maintenance {
        background: #fef3c7;
        color: #92400e;
    }

    .status-broken {
        background: #fee2e2;
        color: #b91c1c;
    }

    .status-expired {
        background: #fff3cd;
        color: #856404;
    }

    .equipment-details-image {
        width: 100%;
        height: 250px;
        object-fit: contain;
        padding: 14px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #ffffff;
    }

    .equipment-modal-summary {
        background: #f8fafc;
        border: 1px solid #e7edf4;
        border-radius: 14px;
        padding: 18px;
    }

    .equipment-modal-info {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px 22px;
    }

    .equipment-modal-info-item {
        min-width: 0;
    }

    .equipment-modal-info-label {
        display: flex;
        align-items: center;
        gap: 7px;
        color: #64748b;
        font-size: 0.78rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 5px;
    }

    .equipment-modal-info-value {
        color: #172033;
        font-weight: 600;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    .detail-section {
        background: #ffffff;
        border: 1px solid #e7edf4;
        border-radius: 12px;
        padding: 16px;
        height: 100%;
    }

    .detail-section-title {
        font-weight: 700;
        margin-bottom: 8px;
        color: #172033;
    }

    .detail-text {
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        color: #4b5563;
        margin-bottom: 0;
        line-height: 1.6;
    }

    .modal-content {
        border: 0;
        border-radius: 18px;
        overflow: hidden;
    }

    .equipment-modal .modal-header {
        padding: 18px 22px;
    }

    .equipment-modal .modal-body {
        padding: 22px;
    }

    .equipment-modal .modal-footer {
        padding: 14px 18px;
    }

    @media (max-width: 767.98px) {
        .equipment-modal-info {
            grid-template-columns: 1fr;
        }

        .equipment-details-image {
            height: 220px;
        }
    }

    @media (max-width: 575.98px) {

        .equipment-image,
        .equipment-image-placeholder {
            height: 165px;
        }
    }

</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

    <div>
        <h2 class="mb-1">Laboratory Equipment</h2>
        <p class="text-muted mb-0">
            Browse equipment and click any card to view complete details.
        </p>
    </div>

    <?php if ($canManage): ?>

        <a
            href="create.php"
            class="btn btn-primary"
        >
            <i class="bi bi-plus-lg me-1"></i>
            Add Equipment
        </a>

    <?php endif; ?>

</div>

<div class="card p-3 mb-4">

    <form
        method="get"
        id="equipmentFilterForm"
        class="row g-3 align-items-end"
    >

        <div class="col-lg-5">

            <label class="form-label">
                Search
            </label>

            <input
                type="search"
                class="form-control"
                name="q"
                placeholder="Search by name, serial number or category..."
                value="<?= e($search) ?>"
            >

        </div>

        <div class="col-md-5 col-lg-3">

            <label class="form-label">
                Laboratory
            </label>

            <select
                class="form-select equipment-auto-filter"
                name="lab_id"
            >

                <option value="0">
                    All laboratories
                </option>

                <?php foreach ($laboratories as $laboratory): ?>

                    <option
                        value="<?= (int) $laboratory['id'] ?>"
                        <?= $labFilter === (int) $laboratory['id']
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($laboratory['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <?php if ($canManage): ?>

            <div class="col-md-5 col-lg-2">

                <label class="form-label">
                    Status
                </label>

                <select
                    class="form-select equipment-auto-filter"
                    name="status"
                >

                    <option value="">
                        All statuses
                    </option>

                    <?php foreach ($allowedStatuses as $status): ?>

                        <option
                            value="<?= e($status) ?>"
                            <?= $statusFilter === $status
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= e($status) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

        <?php endif; ?>

        <div class="col-6 col-md-1">

            <button
                type="submit"
                class="btn btn-outline-primary w-100"
                title="Search"
            >
                <i class="bi bi-search"></i>
            </button>

        </div>

        <div class="col-6 col-md-1">

            <a
                href="index.php"
                class="btn btn-outline-secondary w-100"
                title="Reset filters"
            >
                <i class="bi bi-arrow-counterclockwise"></i>
            </a>

        </div>

    </form>

</div>

<?php if (empty($equipmentItems)): ?>

    <div class="card p-5 text-center">

        <i class="bi bi-cpu fs-1 text-muted mb-3"></i>

        <h5>No equipment found</h5>

        <p class="text-muted mb-0">
            Try changing the search or filter options.
        </p>

    </div>

<?php else: ?>

    <div class="row g-4">

        <?php foreach ($equipmentItems as $equipment): ?>

            <?php

            $statusClass = match ($equipment['status']) {
                'Available' => 'status-available',
                'Under maintenance' => 'status-maintenance',
                'Broken' => 'status-broken',
                'Expired' => 'status-expired',
                default => 'bg-light text-dark'
            };

            $isAvailable =
                $equipment['status'] === 'Available';

            $imageUrl = !empty($equipment['image_path'])
                ? '../../' . ltrim($equipment['image_path'], '/')
                : '';

            $modalId =
                'equipmentDetails'
                . (int) $equipment['id'];

            ?>

            <div class="col-sm-6 col-lg-4 col-xl-3">

                <div
                    class="equipment-card h-100"
                    role="button"
                    tabindex="0"
                    data-bs-toggle="modal"
                    data-bs-target="#<?= $modalId ?>"
                    aria-label="View <?= e($equipment['name']) ?> details"
                >

                    <?php if ($imageUrl !== ''): ?>

                        <img
                            src="<?= e($imageUrl) ?>"
                            class="equipment-image"
                            alt="<?= e($equipment['name']) ?>"
                        >

                    <?php else: ?>

                        <div class="equipment-image-placeholder">

                            <i class="bi bi-cpu"></i>

                            <span>No image</span>

                        </div>

                    <?php endif; ?>

                    <div class="equipment-card-body">

                        <div class="d-flex justify-content-between align-items-start gap-3">

                            <div>

                                <div class="equipment-name">
                                    <?= e($equipment['name']) ?>
                                </div>

                                <div class="equipment-category">
                                    <?= e($equipment['category'] ?: 'Uncategorized') ?>
                                </div>

                            </div>

                            <span
                                class="badge equipment-status <?= $statusClass ?>"
                            >
                                <?= e($equipment['status']) ?>
                            </span>

                        </div>

                        <div class="equipment-main-info">

                            <div class="equipment-compact-line">
                                <i class="bi bi-building"></i>
                                <span>
                                    <?= e(
                                        $equipment['lab_name']
                                        ?: 'Not assigned'
                                    ) ?>
                                </span>
                            </div>

                            <?php if (!empty($equipment['lab_location'])): ?>

                                <div class="equipment-compact-line">
                                    <i class="bi bi-geo-alt"></i>
                                    <span>
                                        <?= e($equipment['lab_location']) ?>
                                    </span>
                                </div>

                            <?php endif; ?>

                            <div class="equipment-compact-line">
                                <i class="bi bi-upc-scan"></i>
                                <span>
                                    <?= e(
                                        $equipment['serial_number']
                                        ?: 'Not provided'
                                    ) ?>
                                </span>
                            </div>

                            <div
                                class="equipment-compact-line equipment-price"
                                <?= (
                                    !isset($equipment['hourly_price'])
                                    || $equipment['hourly_price'] === null
                                    || $equipment['hourly_price'] === ''
                                )
                                    ? 'style="visibility: hidden;"'
                                    : ''
                                ?>
                            >
                                <i class="bi bi-cash-coin"></i>
                                <span>
                                    SAR
                                    <?= number_format(
                                        (float) (
                                            $equipment['hourly_price']
                                            ?? 0
                                        ),
                                        2
                                    ) ?>
                                    / hour
                                </span>
                            </div>

                        </div>

                        <div class="equipment-actions">

                            <?php if ($isAvailable): ?>

                                <a
                                    href="../reservations/create.php?type=Equipment&selection_id=<?= (int) $equipment['id'] ?>"
                                    class="btn btn-primary btn-sm reserve-button"
                                    onclick="event.stopPropagation();"
                                >
                                    Reserve
                                </a>

                            <?php else: ?>

                                <?php
                                $unavailableLabel = match ($equipment['status']) {
                                    'Under maintenance' => 'Under Maintenance',
                                    'Broken' => 'Out of Service',
                                    'Expired' => 'Calibration Required',
                                    default => 'Unavailable'
                                };
                                ?>

                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm reserve-button"
                                    disabled
                                    onclick="event.stopPropagation();"
                                >
                                    <?= e($unavailableLabel) ?>
                                </button>

                            <?php endif; ?>

                            <?php if ($canManage): ?>

                                <a
                                    href="create.php?edit=<?= (int) $equipment['id'] ?>"
                                    class="btn btn-outline-primary btn-sm"
                                    title="Edit equipment"
                                    onclick="event.stopPropagation();"
                                >
                                    <i class="bi bi-pencil-square"></i>
                                </a>

                            <?php endif; ?>

                            <?php if ($canManage): ?>

                                <a
                                    href="?delete=<?= (int) $equipment['id'] ?>"
                                    class="btn btn-outline-danger btn-sm"
                                    title="Delete equipment"
                                    onclick="
                                        event.stopPropagation();
                                        return confirm(
                                            'Are you sure you want to delete this equipment?'
                                        );
                                    "
                                >
                                    <i class="bi bi-trash"></i>
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Equipment Details Modal -->

            <div
                class="modal fade"
                id="<?= $modalId ?>"
                tabindex="-1"
                aria-hidden="true"
            >

                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable equipment-modal">

                    <div class="modal-content">

                        <div class="modal-header">

                            <div>

                                <h4 class="modal-title mb-1">
                                    <?= e($equipment['name']) ?>
                                </h4>

                                <div class="text-muted">
                                    <?= e($equipment['category'] ?: 'Uncategorized') ?>
                                </div>

                            </div>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                                aria-label="Close"
                            ></button>

                        </div>

                        <div class="modal-body">

                            <div class="row g-4 align-items-start">

                                <div class="col-md-5">

                                    <?php if ($imageUrl !== ''): ?>

                                        <img
                                            src="<?= e($imageUrl) ?>"
                                            class="equipment-details-image"
                                            alt="<?= e($equipment['name']) ?>"
                                        >

                                    <?php else: ?>

                                        <div class="equipment-image-placeholder equipment-details-image">

                                            <i class="bi bi-cpu"></i>

                                            <span>No image</span>

                                        </div>

                                    <?php endif; ?>

                                    <div class="mt-3">

                                        <span class="badge <?= $statusClass ?>">
                                            <?= e($equipment['status']) ?>
                                        </span>

                                    </div>

                                </div>

                                <div class="col-md-7">

                                    <div class="equipment-modal-summary">

                                        <div class="equipment-modal-info">

                                            <div class="equipment-modal-info-item">

                                                <div class="equipment-modal-info-label">
                                                    <i class="bi bi-building"></i>
                                                    Laboratory
                                                </div>

                                                <div class="equipment-modal-info-value">
                                                    <?= e(
                                                        $equipment['lab_name']
                                                        ?: 'Not assigned'
                                                    ) ?>
                                                </div>

                                            </div>

                                            <div class="equipment-modal-info-item">

                                                <div class="equipment-modal-info-label">
                                                    <i class="bi bi-geo-alt"></i>
                                                    Location
                                                </div>

                                                <div class="equipment-modal-info-value">
                                                    <?= e(
                                                        $equipment['lab_location']
                                                        ?: 'Not provided'
                                                    ) ?>
                                                </div>

                                            </div>

                                            <div class="equipment-modal-info-item">

                                                <div class="equipment-modal-info-label">
                                                    <i class="bi bi-upc-scan"></i>
                                                    Serial Number
                                                </div>

                                                <div class="equipment-modal-info-value">
                                                    <?= e(
                                                        $equipment['serial_number']
                                                        ?: 'Not provided'
                                                    ) ?>
                                                </div>

                                            </div>

                                            <div class="equipment-modal-info-item">

                                                <div class="equipment-modal-info-label">
                                                    <i class="bi bi-cash-coin"></i>
                                                    Price per Hour
                                                </div>

                                                <div class="equipment-modal-info-value">

                                                    <?php if (
                                                        isset($equipment['hourly_price'])
                                                        && $equipment['hourly_price'] !== null
                                                        && $equipment['hourly_price'] !== ''
                                                    ): ?>

                                                        SAR
                                                        <?= number_format(
                                                            (float) $equipment['hourly_price'],
                                                            2
                                                        ) ?>

                                                    <?php else: ?>

                                                        Free

                                                    <?php endif; ?>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <div class="col-12">

                                    <div class="detail-section">

                                        <div class="detail-section-title">
                                            Description
                                        </div>

                                        <p class="detail-text">
                                            <?= e(
                                                $equipment['description']
                                                ?: 'No description provided.'
                                            ) ?>
                                        </p>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="detail-section">

                                        <div class="detail-section-title">
                                            Usage Instructions
                                        </div>

                                        <p class="detail-text">
                                            <?= e(
                                                $equipment['usage_instructions']
                                                ?: 'No usage instructions provided.'
                                            ) ?>
                                        </p>

                                    </div>

                                </div>

                                <div class="col-md-6">

                                    <div class="detail-section">

                                        <div class="detail-section-title">
                                            Safety Guidelines
                                        </div>

                                        <p class="detail-text">
                                            <?= e(
                                                $equipment['safety_guidelines']
                                                ?: 'No safety guidelines provided.'
                                            ) ?>
                                        </p>

                                    </div>

                                </div>

                                <?php if ($canManage): ?>

                                    <div class="col-md-6">

                                        <div class="detail-section">

                                            <div class="detail-section-title">
                                                Last Maintenance
                                            </div>

                                            <p class="detail-text">
                                                <?= e(
                                                    $equipment['last_maintenance']
                                                    ?: 'Not recorded'
                                                ) ?>
                                            </p>

                                        </div>

                                    </div>

                                    <div class="col-md-6">

                                        <div class="detail-section">

                                            <div class="detail-section-title">
                                                Next Maintenance
                                            </div>

                                            <p class="detail-text">
                                                <?= e(
                                                    $equipment['next_maintenance']
                                                    ?: 'Not scheduled'
                                                ) ?>
                                            </p>

                                        </div>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <?php if ($canManage): ?>

                                <a
                                    href="create.php?edit=<?= (int) $equipment['id'] ?>"
                                    class="btn btn-outline-primary"
                                >
                                    <i class="bi bi-pencil-square me-1"></i>
                                    Edit
                                </a>

                            <?php endif; ?>

                            <?php if ($isAvailable): ?>

                                <a
                                    href="../reservations/create.php?type=Equipment&selection_id=<?= (int) $equipment['id'] ?>"
                                    class="btn btn-primary"
                                >
                                    Reserve Equipment
                                </a>

                            <?php endif; ?>

                            <button
                                type="button"
                                class="btn btn-light"
                                data-bs-dismiss="modal"
                            >
                                Close
                            </button>

                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php endif; ?>

<script>

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            const filterForm =
                document.getElementById(
                    'equipmentFilterForm'
                );

            document
                .querySelectorAll(
                    '.equipment-auto-filter'
                )
                .forEach(function (field) {

                    field.addEventListener(
                        'change',
                        function () {

                            if (filterForm) {
                                filterForm.submit();
                            }
                        }
                    );
                });

            document
                .querySelectorAll(
                    '.equipment-card'
                )
                .forEach(function (card) {

                    card.addEventListener(
                        'keydown',
                        function (event) {

                            if (
                                event.key === 'Enter'
                                || event.key === ' '
                            ) {

                                event.preventDefault();
                                card.click();
                            }
                        }
                    );
                });
        }
    );

</script>

<?php require '../../includes/footer.php'; ?>