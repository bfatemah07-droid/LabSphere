<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

$canManage = in_array(
    user()['role'],
    ['Supervisor', 'Admin'],
    true
);

/*
|--------------------------------------------------------------------------
| Save Laboratory
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    require_role([
        'Supervisor',
        'Admin'
    ]);

    verify_csrf();

    $id =
        (int) ($_POST['id'] ?? 0);

    $name =
        trim($_POST['name'] ?? '');

    $code =
        trim($_POST['code'] ?? '');

    $location =
        trim($_POST['location'] ?? '');

    $capacity =
        max(
            1,
            (int) ($_POST['capacity'] ?? 1)
        );

    $supervisorId =
        !empty($_POST['responsible_supervisor_id'])
            ? (int) $_POST['responsible_supervisor_id']
            : null;

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        flash(
            'danger',
            'Laboratory name is required.'
        );

        header(
            'Location: ' .
            ($id > 0
                ? 'index.php?edit=' . $id
                : 'index.php')
        );

        exit;
    }

    if ($code === '') {

        flash(
            'danger',
            'Room number is required.'
        );

        header(
            'Location: ' .
            ($id > 0
                ? 'index.php?edit=' . $id
                : 'index.php')
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Room Number
    |--------------------------------------------------------------------------
    */

    if ($id > 0) {

        $statement = $pdo->prepare(
            'SELECT id
             FROM laboratories
             WHERE code = ?
               AND id != ?
             LIMIT 1'
        );

        $statement->execute([
            $code,
            $id
        ]);

    } else {

        $statement = $pdo->prepare(
            'SELECT id
             FROM laboratories
             WHERE code = ?
             LIMIT 1'
        );

        $statement->execute([
            $code
        ]);
    }

    if ($statement->fetch()) {

        flash(
            'danger',
            'Another laboratory already uses this room number.'
        );

        header(
            'Location: ' .
            ($id > 0
                ? 'index.php?edit=' . $id
                : 'index.php')
        );

        exit;
    }

    try {

        if ($id > 0) {

            /*
            |--------------------------------------------------------------------------
            | Update Laboratory
            |--------------------------------------------------------------------------
            */

            $statement = $pdo->prepare(
                'UPDATE laboratories
                 SET name = ?,
                     code = ?,
                     location = ?,
                     capacity = ?,
                     responsible_supervisor_id = ?
                 WHERE id = ?'
            );

            $statement->execute([
                $name,
                $code,
                $location !== ''
                    ? $location
                    : null,
                $capacity,
                $supervisorId,
                $id
            ]);

            audit(
                $pdo,
                'UPDATE',
                'Laboratories',
                $name
            );

            flash(
                'success',
                'Laboratory updated successfully.'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Add Laboratory
            |--------------------------------------------------------------------------
            */

            $statement = $pdo->prepare(
                'INSERT INTO laboratories
                (
                    name,
                    code,
                    location,
                    capacity,
                    responsible_supervisor_id
                )
                VALUES (?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $name,
                $code,
                $location !== ''
                    ? $location
                    : null,
                $capacity,
                $supervisorId
            ]);

            audit(
                $pdo,
                'CREATE',
                'Laboratories',
                $name
            );

            flash(
                'success',
                'Laboratory added successfully.'
            );
        }

        header('Location: index.php');
        exit;

    } catch (PDOException $exception) {

        flash(
            'danger',
            'An error occurred while saving the laboratory.'
        );

        header(
            'Location: ' .
            ($id > 0
                ? 'index.php?edit=' . $id
                : 'index.php')
        );

        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Delete Laboratory
|--------------------------------------------------------------------------
*/

if (isset($_GET['delete'])) {

    require_role(['Admin']);

    $laboratoryId =
        (int) $_GET['delete'];

    try {

        $statement = $pdo->prepare(
            'DELETE FROM laboratories
             WHERE id = ?'
        );

        $statement->execute([
            $laboratoryId
        ]);

        audit(
            $pdo,
            'DELETE',
            'Laboratories',
            'Laboratory ID: ' . $laboratoryId
        );

        flash(
            'success',
            'Laboratory deleted successfully.'
        );

    } catch (PDOException $exception) {

        flash(
            'danger',
            'This laboratory cannot be deleted because it contains related equipment, reservations, or records.'
        );
    }

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Get Laboratory for Editing
|--------------------------------------------------------------------------
*/

$edit = null;

if (isset($_GET['edit'])) {

    require_role([
        'Supervisor',
        'Admin'
    ]);

    $laboratoryId =
        (int) $_GET['edit'];

    $statement = $pdo->prepare(
        'SELECT *
         FROM laboratories
         WHERE id = ?
         LIMIT 1'
    );

    $statement->execute([
        $laboratoryId
    ]);

    $edit =
        $statement->fetch();

    if (!$edit) {

        flash(
            'danger',
            'Laboratory not found.'
        );

        header('Location: index.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search =
    trim($_GET['q'] ?? '');

$sql = '
    SELECT
        laboratories.*,
        users.name AS supervisor,
        COUNT(DISTINCT equipment.id) AS equipment_count
    FROM laboratories
    LEFT JOIN users
        ON users.id =
           laboratories.responsible_supervisor_id
    LEFT JOIN equipment
        ON equipment.lab_id =
           laboratories.id
    WHERE 1 = 1
';

$parameters = [];

if ($search !== '') {

    $sql .= '
        AND (
            laboratories.name LIKE ?
            OR laboratories.code LIKE ?
            OR laboratories.location LIKE ?
            OR users.name LIKE ?
        )
    ';

    $searchValue =
        '%' . $search . '%';

    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
    $parameters[] = $searchValue;
}

$sql .= '
    GROUP BY
        laboratories.id,
        laboratories.name,
        laboratories.code,
        laboratories.location,
        laboratories.capacity,
        laboratories.responsible_supervisor_id,
        users.name
    ORDER BY laboratories.id DESC
';

$statement =
    $pdo->prepare($sql);

$statement->execute($parameters);

$rows =
    $statement->fetchAll();

/*
|--------------------------------------------------------------------------
| Supervisors
|--------------------------------------------------------------------------
*/

$supervisors = $pdo->query(
    "SELECT id, name
     FROM users
     WHERE role = 'Supervisor'
       AND active = 1
     ORDER BY name"
)->fetchAll();

$page_title =
    'Laboratories';

require '../../includes/header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>
        <h2 class="mb-1">Laboratories</h2>

        <p class="text-muted mb-0">
            Browse laboratories and reserve an entire laboratory.
        </p>
    </div>

    <?php if ($canManage): ?>

        <a
            href="create.php"
            class="btn btn-primary"
        >
            <i class="bi bi-plus-lg me-1"></i>
            Add Laboratory
        </a>

    <?php endif; ?>

</div>

<div class="card p-3 mb-4 laboratory-filter-card">

    <form method="get">

        <div class="row g-2 align-items-end">

            <div class="col-lg-10">

                <label for="q" class="form-label">
                    Search
                </label>

                <input
                    type="search"
                    id="q"
                    name="q"
                    class="form-control"
                    placeholder="Search by laboratory name, room number, location or supervisor..."
                    value="<?= e($search) ?>"
                >

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
                        title="Reset search"
                    >
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>

                </div>

            </div>

        </div>

    </form>

</div>

<div class="row g-3">

    <?php if (empty($rows)): ?>

        <div class="col-12">

            <div class="card p-5 text-center">

                <i class="bi bi-building display-5 text-muted mb-3"></i>

                <h5>No laboratories found</h5>

                <p class="text-muted mb-0">
                    No laboratories match the current search.
                </p>

            </div>

        </div>

    <?php endif; ?>

    <?php foreach ($rows as $laboratory): ?>

        <?php
            $modalId =
                'laboratoryModal' .
                (int) $laboratory['id'];

            $supervisorName =
                $laboratory['supervisor']
                ?: 'Not assigned';

            $location =
                $laboratory['location']
                ?: 'Not specified';
        ?>

        <div class="col-sm-6 col-lg-4 col-xl-3">

            <div
                class="card laboratory-card h-100"
                role="button"
                tabindex="0"
                data-bs-toggle="modal"
                data-bs-target="#<?= e($modalId) ?>"
            >

                <div class="laboratory-image-area">

                    <div class="laboratory-main-icon">
                        <i class="bi bi-building"></i>
                    </div>

                    <span class="badge text-bg-primary laboratory-code">
                        <?= e($laboratory['code']) ?>
                    </span>

                </div>

                <div class="laboratory-card-body">

                    <div class="laboratory-category">
                        Laboratory
                    </div>

                    <h5 class="laboratory-name">
                        <?= e($laboratory['name']) ?>
                    </h5>

                    <div class="laboratory-main-info">

                        <div class="laboratory-compact-line">
                            <i class="bi bi-geo-alt"></i>

                            <span>
                                <?= e($location) ?>
                            </span>
                        </div>

                        <div class="laboratory-compact-line">
                            <i class="bi bi-door-open"></i>

                            <span>
                                <?= e($laboratory['code']) ?>
                            </span>
                        </div>

                        <div class="laboratory-compact-line">
                            <i class="bi bi-people"></i>

                            <span>
                                <?= e($laboratory['capacity']) ?>
                                people
                            </span>
                        </div>

                        <div class="laboratory-compact-line">
                            <i class="bi bi-person-badge"></i>

                            <span>
                                <?= e($supervisorName) ?>
                            </span>
                        </div>

                        <div class="laboratory-compact-line">
                            <i class="bi bi-cpu"></i>

                            <span>
                                <?= e($laboratory['equipment_count']) ?>
                                device(s)
                            </span>
                        </div>

                    </div>

                    <div class="laboratory-actions">

                        <a
                            href="../reservations/create.php?type=Laboratory&selection_id=<?= (int) $laboratory['id'] ?>"
                            class="btn btn-primary btn-sm flex-grow-1"
                            onclick="event.stopPropagation();"
                        >
                            Reserve
                        </a>

                        <?php if ($canManage): ?>

                            <a
                                href="?edit=<?= (int) $laboratory['id'] ?>#laboratoryForm"
                                class="btn btn-outline-primary btn-sm"
                                title="Edit laboratory"
                                onclick="event.stopPropagation();"
                            >
                                <i class="bi bi-pencil"></i>
                            </a>

                        <?php endif; ?>

                        <?php if (user()['role'] === 'Admin'): ?>

                            <a
                                href="?delete=<?= (int) $laboratory['id'] ?>"
                                class="btn btn-outline-danger btn-sm"
                                title="Delete laboratory"
                                onclick="
                                    event.stopPropagation();

                                    return confirm(
                                        'Are you sure you want to delete this laboratory?'
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

        <div
            class="modal fade"
            id="<?= e($modalId) ?>"
            tabindex="-1"
            aria-hidden="true"
        >

            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable laboratory-modal">

                <div class="modal-content">

                    <div class="modal-header">

                        <div>

                            <h5 class="modal-title mb-1">
                                <?= e($laboratory['name']) ?>
                            </h5>

                            <div class="text-muted small">
                                Room <?= e($laboratory['code']) ?>
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

                                <div class="laboratory-modal-icon">
                                    <i class="bi bi-building"></i>
                                </div>

                                <div class="mt-3">

                                    <span class="badge text-bg-primary">
                                        Laboratory
                                    </span>

                                </div>

                            </div>

                            <div class="col-md-7">

                                <div class="laboratory-modal-summary">

                                    <div class="laboratory-modal-info">

                                        <div class="laboratory-modal-info-item">

                                            <div class="laboratory-modal-info-label">
                                                <i class="bi bi-door-open"></i>
                                                Room Number
                                            </div>

                                            <div class="laboratory-modal-info-value">
                                                <?= e($laboratory['code']) ?>
                                            </div>

                                        </div>

                                        <div class="laboratory-modal-info-item">

                                            <div class="laboratory-modal-info-label">
                                                <i class="bi bi-geo-alt"></i>
                                                Location
                                            </div>

                                            <div class="laboratory-modal-info-value">
                                                <?= e($location) ?>
                                            </div>

                                        </div>

                                        <div class="laboratory-modal-info-item">

                                            <div class="laboratory-modal-info-label">
                                                <i class="bi bi-people"></i>
                                                Capacity
                                            </div>

                                            <div class="laboratory-modal-info-value">
                                                <?= e($laboratory['capacity']) ?>
                                                people
                                            </div>

                                        </div>

                                        <div class="laboratory-modal-info-item">

                                            <div class="laboratory-modal-info-label">
                                                <i class="bi bi-person-badge"></i>
                                                Supervisor
                                            </div>

                                            <div class="laboratory-modal-info-value">
                                                <?= e($supervisorName) ?>
                                            </div>

                                        </div>

                                        <div class="laboratory-modal-info-item">

                                            <div class="laboratory-modal-info-label">
                                                <i class="bi bi-cpu"></i>
                                                Equipment
                                            </div>

                                            <div class="laboratory-modal-info-value">
                                                <?= e($laboratory['equipment_count']) ?>
                                                device(s)
                                            </div>

                                        </div>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal"
                        >
                            Close
                        </button>

                        <a
                            href="../reservations/create.php?type=Laboratory&selection_id=<?= (int) $laboratory['id'] ?>"
                            class="btn btn-primary"
                        >
                            Reserve Laboratory
                        </a>

                    </div>

                </div>

            </div>

        </div>

    <?php endforeach; ?>

</div>

<?php if ($canManage && $edit): ?>

    <div
        class="row justify-content-center mt-4"
        id="laboratoryForm"
    >

        <div class="col-lg-8 col-xl-7">

            <div class="card p-4 laboratory-form-card">

                <div class="d-flex align-items-center justify-content-between mb-3">

                    <div>

                        <h5 class="mb-1">
                            <?= $edit
                                ? 'Edit Laboratory'
                                : 'Add Laboratory'
                            ?>
                        </h5>

                        <div class="small text-muted">
                            <?= $edit
                                ? 'Update the laboratory information.'
                                : 'Enter the new laboratory information.'
                            ?>
                        </div>

                    </div>

                    <?php if ($edit): ?>

                        <a
                            href="index.php#laboratoryForm"
                            class="btn btn-sm btn-light"
                            title="Cancel editing"
                        >
                            <i class="bi bi-x-lg"></i>
                        </a>

                    <?php endif; ?>

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
                        value="<?= e($edit['id'] ?? '') ?>"
                    >

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label
                                for="name"
                                class="form-label"
                            >
                                Laboratory Name
                            </label>

                            <input
                                type="text"
                                id="name"
                                name="name"
                                class="form-control"
                                value="<?= e($edit['name'] ?? '') ?>"
                                placeholder="Example: General Biology Laboratory"
                                required
                            >

                        </div>

                        <div class="col-md-6">

                            <label
                                for="code"
                                class="form-label"
                            >
                                Room Number
                            </label>

                            <input
                                type="text"
                                id="code"
                                name="code"
                                class="form-control"
                                value="<?= e($edit['code'] ?? '') ?>"
                                placeholder="Example: 107GB256"
                                required
                            >

                        </div>

                        <div class="col-md-6">

                            <label
                                for="location"
                                class="form-label"
                            >
                                Location
                            </label>

                            <input
                                type="text"
                                id="location"
                                name="location"
                                class="form-control"
                                value="<?= e($edit['location'] ?? '') ?>"
                                placeholder="Example: Ground Floor"
                            >

                        </div>

                        <div class="col-md-6">

                            <label
                                for="capacity"
                                class="form-label"
                            >
                                Capacity
                            </label>

                            <input
                                type="number"
                                id="capacity"
                                name="capacity"
                                class="form-control"
                                min="1"
                                value="<?= e($edit['capacity'] ?? 1) ?>"
                                required
                            >

                        </div>

                        <div class="col-12">

                            <label
                                for="responsible_supervisor_id"
                                class="form-label"
                            >
                                Supervisor
                            </label>

                            <select
                                id="responsible_supervisor_id"
                                name="responsible_supervisor_id"
                                class="form-select"
                            >

                                <option value="">
                                    None
                                </option>

                                <?php foreach ($supervisors as $supervisor): ?>

                                    <option
                                        value="<?= (int) $supervisor['id'] ?>"
                                        <?= (
                                            $edit['responsible_supervisor_id']
                                            ?? ''
                                        ) == $supervisor['id']
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= e($supervisor['name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">

                        <?php if ($edit): ?>

                            <a
                                href="index.php"
                                class="btn btn-light"
                            >
                                Cancel
                            </a>

                        <?php endif; ?>

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="bi bi-check-lg me-1"></i>

                            <?= $edit
                                ? 'Update Laboratory'
                                : 'Add Laboratory'
                            ?>
                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

<?php endif; ?>

<style>

    .laboratory-filter-card,
    .laboratory-form-card {
        border: 1px solid #e7edf4;
        border-radius: 16px;
    }

    .laboratory-card {
        overflow: hidden;
        border: 1px solid #e2e7ee;
        border-radius: 16px;
        cursor: pointer;
        display: flex;
        flex-direction: column;
        transition:
            transform 0.15s ease,
            box-shadow 0.15s ease;
    }

    .laboratory-card:hover {
        transform: translateY(-3px);
        box-shadow:
            0 10px 24px
            rgba(31, 48, 76, 0.09);
    }

    .laboratory-image-area {
        position: relative;
        height: 175px;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
            linear-gradient(
                135deg,
                #edf4ff,
                #f8fafc
            );
        border-bottom: 1px solid #edf1f5;
    }

    .laboratory-main-icon {
        width: 82px;
        height: 82px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 22px;
        background: #ffffff;
        color: #0d6efd;
        font-size: 40px;
        box-shadow:
            0 10px 26px
            rgba(25, 74, 140, 0.12);
    }

    .laboratory-code {
        position: absolute;
        top: 12px;
        right: 12px;
    }

    .laboratory-card-body {
        padding: 15px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }

    .laboratory-category {
        color: #0d6efd;
        font-size: 0.78rem;
        font-weight: 600;
        margin-bottom: 3px;
        text-transform: uppercase;
    }

    .laboratory-name {
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.35;
        min-height: 2.7em;
        margin-bottom: 0;
        color: #172033;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .laboratory-main-info {
        margin-top: 14px;
        display: grid;
        gap: 8px;
        flex: 1;
    }

    .laboratory-compact-line {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        color: #475569;
        font-size: 0.86rem;
        line-height: 1.35;
        min-width: 0;
    }

    .laboratory-compact-line i {
        width: 16px;
        flex: 0 0 16px;
        color: #6b778c;
        margin-top: 1px;
    }

    .laboratory-compact-line span {
        overflow-wrap: anywhere;
    }

    .laboratory-actions {
        display: flex;
        gap: 7px;
        margin-top: 14px;
        align-items: stretch;
    }

    .laboratory-modal .modal-content {
        border: 0;
        border-radius: 18px;
        overflow: hidden;
    }

    .laboratory-modal .modal-header {
        padding: 18px 22px;
    }

    .laboratory-modal .modal-body {
        padding: 22px;
    }

    .laboratory-modal .modal-footer {
        padding: 14px 18px;
    }

    .laboratory-modal-icon {
        width: 100%;
        height: 250px;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background:
            linear-gradient(
                135deg,
                #edf4ff,
                #f8fafc
            );
        color: #0d6efd;
        font-size: 72px;
    }

    .laboratory-modal-summary {
        background: #f8fafc;
        border: 1px solid #e7edf4;
        border-radius: 14px;
        padding: 18px;
    }

    .laboratory-modal-info {
        display: grid;
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
        gap: 18px 22px;
    }

    .laboratory-modal-info-item {
        min-width: 0;
    }

    .laboratory-modal-info-label {
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

    .laboratory-modal-info-value {
        color: #172033;
        font-weight: 600;
        line-height: 1.45;
        overflow-wrap: anywhere;
    }

    @media (max-width: 767.98px) {

        .laboratory-image-area {
            height: 165px;
        }

        .laboratory-modal-info {
            grid-template-columns: 1fr;
        }

        .laboratory-modal-icon {
            height: 220px;
        }
    }

</style>

<script>

    document.querySelectorAll('.laboratory-card').forEach(
        function (card) {

            card.addEventListener(
                'keydown',
                function (event) {

                    if (
                        event.key === 'Enter'
                        || event.key === ' '
                    ) {

                        event.preventDefault();

                        const modalSelector =
                            card.getAttribute(
                                'data-bs-target'
                            );

                        const modalElement =
                            document.querySelector(
                                modalSelector
                            );

                        if (modalElement) {

                            bootstrap.Modal
                                .getOrCreateInstance(
                                    modalElement
                                )
                                .show();
                        }
                    }
                }
            );
        }
    );

</script>

<?php require '../../includes/footer.php'; ?>