<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();
require_role(['Supervisor', 'Admin']);

/*
|--------------------------------------------------------------------------
| Laboratories
|--------------------------------------------------------------------------
*/

$laboratories = $pdo->query(
    'SELECT id, name
     FROM laboratories
     ORDER BY name'
)->fetchAll();

/*
|--------------------------------------------------------------------------
| Detect Existing Supply Columns
|--------------------------------------------------------------------------
*/

$columnRows = $pdo->query(
    'SHOW COLUMNS FROM supplies'
)->fetchAll();

$supplyColumns = array_column(
    $columnRows,
    'Field'
);

$hasDescription = in_array(
    'description',
    $supplyColumns,
    true
);

$hasStatus = in_array(
    'status',
    $supplyColumns,
    true
);

$hasNotes = in_array(
    'notes',
    $supplyColumns,
    true
);

$hasLowStockThreshold = in_array(
    'low_stock_threshold',
    $supplyColumns,
    true
);

/*
|--------------------------------------------------------------------------
| Add / Edit Mode
|--------------------------------------------------------------------------
*/

$edit = null;

if (isset($_GET['edit'])) {

    $editId = (int) $_GET['edit'];

    if ($editId <= 0) {
        flash('danger', 'Invalid supply item.');
        header('Location: index.php');
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT *
         FROM supplies
         WHERE id = ?'
    );

    $statement->execute([$editId]);
    $edit = $statement->fetch();

    if (!$edit) {
        flash('danger', 'Supply item not found.');
        header('Location: index.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Generate Supply Code Automatically
|--------------------------------------------------------------------------
*/

function generateSupplyCode(PDO $pdo): string
{
    $statement = $pdo->query(
        "SELECT code
         FROM supplies
         WHERE code REGEXP '^SP-[0-9]+$'
         ORDER BY CAST(SUBSTRING(code, 4) AS UNSIGNED) DESC
         LIMIT 1"
    );

    $lastCode = $statement->fetchColumn();

    if (!$lastCode) {
        return 'SP-701';
    }

    $lastNumber = (int) substr(
        $lastCode,
        3
    );

    return 'SP-' . ($lastNumber + 1);
}

/*
|--------------------------------------------------------------------------
| Form Values
|--------------------------------------------------------------------------
*/

$storedDescription = '';

if ($edit) {
    if ($hasDescription) {
        $storedDescription = (string) ($edit['description'] ?? '');
    } elseif ($hasNotes) {
        $storedDescription = (string) ($edit['notes'] ?? '');
    }
}

$storedStatus = 'Available';

if ($edit) {
    if ($hasStatus && !empty($edit['status'])) {
        $storedStatus = (string) $edit['status'];
    } else {
        $quantityValue = (float) ($edit['quantity'] ?? 0);
        $thresholdValue = (float) ($edit['low_stock_threshold'] ?? 5);

        if ($quantityValue <= 0) {
            $storedStatus = 'Out of Stock';
        } elseif ($quantityValue <= $thresholdValue) {
            $storedStatus = 'Low Stock';
        } else {
            $storedStatus = 'Available';
        }
    }
}

$formData = [
    'id' => $edit['id'] ?? '',
    'code' => $edit['code'] ?? '',
    'name' => $edit['name'] ?? '',
    'category' => $edit['category'] ?? 'PPE',
    'unit' => $edit['unit'] ?? 'Piece(s)',
    'description' => $storedDescription,
    'quantity' => $edit['quantity'] ?? '',
    'lab_id' => $edit['lab_id'] ?? '',
    'status' => $storedStatus
];

$errors = [];

/*
|--------------------------------------------------------------------------
| Save Supply
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    foreach ($formData as $field => $defaultValue) {
        $formData[$field] = trim(
            (string) ($_POST[$field] ?? $defaultValue)
        );
    }

    $id = (int) ($formData['id'] ?? 0);
    $isEditing = $id > 0;

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($formData['name'] === '') {
        $errors[] = 'Item name is required.';
    }

    $allowedCategories = [
        'PPE',
        'Consumable',
        'Glassware',
        'Other'
    ];

    if (!in_array(
        $formData['category'],
        $allowedCategories,
        true
    )) {
        $errors[] = 'Please select a valid category.';
    }

    if ($formData['unit'] === '') {
        $errors[] = 'Unit is required.';
    }

    if (
        $formData['quantity'] === ''
        || !is_numeric($formData['quantity'])
        || (float) $formData['quantity'] < 0
    ) {
        $errors[] = 'Quantity in stock must be zero or greater.';
    }

    if ((int) $formData['lab_id'] <= 0) {
        $errors[] = 'Please select a laboratory.';
    }

    $allowedStatuses = [
        'Available',
        'Low Stock',
        'Out of Stock'
    ];

    if (!in_array(
        $formData['status'],
        $allowedStatuses,
        true
    )) {
        $errors[] = 'Please select a valid status.';
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm Existing Supply in Edit Mode
    |--------------------------------------------------------------------------
    */

    if ($isEditing && empty($errors)) {

        $statement = $pdo->prepare(
            'SELECT id, code
             FROM supplies
             WHERE id = ?'
        );

        $statement->execute([$id]);
        $existingSupply = $statement->fetch();

        if (!$existingSupply) {
            flash('danger', 'Supply item not found.');
            header('Location: index.php');
            exit;
        }

        $formData['code'] = $existingSupply['code'];
    }

    /*
    |--------------------------------------------------------------------------
    | Automatically Create Code in Add Mode
    |--------------------------------------------------------------------------
    */

    if (!$isEditing && empty($errors)) {
        $formData['code'] = generateSupplyCode($pdo);
    }

    /*
    |--------------------------------------------------------------------------
    | Save Add or Edit
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $quantity = max(
            0,
            (float) $formData['quantity']
        );

        $labId = (int) $formData['lab_id'];

        /*
         * Keep the selected status consistent with the quantity.
         */
        if ($quantity <= 0) {
            $formData['status'] = 'Out of Stock';
        }

        /*
         * A hidden default is still saved for the old stock-filter logic.
         */
        $lowStockThreshold = 5;

        try {

            if ($isEditing) {

                $updateFields = [
                    'name = ?',
                    'category = ?',
                    'unit = ?',
                    'quantity = ?',
                    'lab_id = ?'
                ];

                $updateValues = [
                    $formData['name'],
                    $formData['category'],
                    $formData['unit'],
                    $quantity,
                    $labId
                ];

                if ($hasDescription) {
                    $updateFields[] = 'description = ?';
                    $updateValues[] = $formData['description'];
                } elseif ($hasNotes) {
                    $updateFields[] = 'notes = ?';
                    $updateValues[] = $formData['description'];
                }

                if ($hasStatus) {
                    $updateFields[] = 'status = ?';
                    $updateValues[] = $formData['status'];
                }

                if ($hasLowStockThreshold) {
                    $updateFields[] = 'low_stock_threshold = ?';
                    $updateValues[] = $lowStockThreshold;
                }

                $updateValues[] = $id;

                $statement = $pdo->prepare(
                    'UPDATE supplies
                     SET ' . implode(', ', $updateFields) . '
                     WHERE id = ?'
                );

                $statement->execute($updateValues);

                audit(
                    $pdo,
                    'UPDATE',
                    'Supplies',
                    $formData['name']
                );

                flash(
                    'success',
                    'Supply item updated successfully.'
                );

            } else {

                $insertColumns = [
                    'code',
                    'name',
                    'category',
                    'unit',
                    'quantity',
                    'lab_id'
                ];

                $insertValues = [
                    $formData['code'],
                    $formData['name'],
                    $formData['category'],
                    $formData['unit'],
                    $quantity,
                    $labId
                ];

                if ($hasDescription) {
                    $insertColumns[] = 'description';
                    $insertValues[] = $formData['description'];
                } elseif ($hasNotes) {
                    $insertColumns[] = 'notes';
                    $insertValues[] = $formData['description'];
                }

                if ($hasStatus) {
                    $insertColumns[] = 'status';
                    $insertValues[] = $formData['status'];
                }

                if ($hasLowStockThreshold) {
                    $insertColumns[] = 'low_stock_threshold';
                    $insertValues[] = $lowStockThreshold;
                }

                $placeholders = implode(
                    ', ',
                    array_fill(
                        0,
                        count($insertColumns),
                        '?'
                    )
                );

                $statement = $pdo->prepare(
                    'INSERT INTO supplies
                    (' . implode(', ', $insertColumns) . ')
                    VALUES (' . $placeholders . ')'
                );

                $statement->execute($insertValues);

                audit(
                    $pdo,
                    'CREATE',
                    'Supplies',
                    $formData['name']
                );

                flash(
                    'success',
                    'Supply item added successfully.'
                );
            }

            header('Location: index.php');
            exit;

        } catch (PDOException $exception) {

            $errors[] = $isEditing
                ? 'An error occurred while updating the supply item.'
                : 'An error occurred while adding the supply item.';
        }
    }

    if ($isEditing) {
        $edit = ['id' => $id];
    }
}

$isEditing = !empty($edit)
    || (int) ($formData['id'] ?? 0) > 0;

$page_title = $isEditing
    ? 'Edit Supply Item'
    : 'Add Supply Item';

require '../../includes/header.php';

?>

<style>
    .supply-form-wrapper {
        max-width: 900px;
        margin: 0 auto 2rem;
    }

    .supply-form-card {
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 12px 35px rgba(15, 23, 42, 0.08);
    }

    .supply-form-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e7edf4;
    }

    .supply-form-body {
        padding: 1.5rem;
    }

    .supply-form-card .form-label {
        margin-bottom: 0.45rem;
        color: #172033;
        font-weight: 600;
    }

    .supply-form-card .form-control,
    .supply-form-card .form-select {
        min-height: 44px;
        border-radius: 11px;
    }

    .supply-form-card textarea.form-control {
        min-height: 115px;
    }

    .supply-form-footer {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        padding-top: 1.25rem;
        margin-top: 1.25rem;
        border-top: 1px solid #e7edf4;
    }

    @media (max-width: 767.98px) {
        .supply-form-header,
        .supply-form-body {
            padding: 1rem;
        }

        .supply-form-footer {
            flex-direction: column-reverse;
        }

        .supply-form-footer .btn {
            width: 100%;
        }
    }
</style>

<div class="supply-form-wrapper">

    <div class="d-flex align-items-center gap-3 mb-3">

        <a
            href="index.php"
            class="btn btn-outline-secondary"
            aria-label="Back to supplies"
        >
            <i class="bi bi-arrow-left"></i>
        </a>

        <div>

            <h2 class="mb-1">
                <?= $isEditing
                    ? 'Edit Supply Item'
                    : 'Add Supply Item'
                ?>
            </h2>

            <p class="text-muted mb-0">
                <?= $isEditing
                    ? 'Update the complete supply item information below.'
                    : 'Enter the complete supply item information below.'
                ?>
            </p>

        </div>

    </div>

    <div class="card supply-form-card">

        <div class="supply-form-header">

            <div>

                <h5 class="mb-1">
                    <?= $isEditing
                        ? 'Edit Supply Item'
                        : 'Add Supply Item'
                    ?>
                </h5>

                <div class="small text-muted">
                    <?= $isEditing
                        ? 'Change the fields you need, then save the updated item.'
                        : 'Complete all required fields, then save the new item.'
                    ?>
                </div>

            </div>

            <a
                href="index.php"
                class="btn-close"
                aria-label="Close"
            ></a>

        </div>

        <div class="supply-form-body">

            <?php if (!empty($errors)): ?>

                <div class="alert alert-danger">

                    <ul class="mb-0">

                        <?php foreach ($errors as $error): ?>

                            <li><?= e($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <?php if (!$hasDescription || !$hasStatus): ?>

                
            <?php endif; ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= csrf_token() ?>"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?= e($formData['id']) ?>"
                >

                <input
                    type="hidden"
                    name="code"
                    value="<?= e($formData['code']) ?>"
                >

                <div class="row g-3">

                    <div class="col-12">

                        <label
                            for="name"
                            class="form-label"
                        >
                            Item Name
                        </label>

                        <input
                            type="text"
                            id="name"
                            name="name"
                            class="form-control"
                            placeholder="Example: Nitrile Gloves (Box of 100)"
                            value="<?= e($formData['name']) ?>"
                            required
                        >

                    </div>

                    <div class="col-md-6">

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
                            required
                        >

                            <?php foreach (
                                ['PPE', 'Consumable', 'Glassware', 'Other']
                                as $category
                            ): ?>

                                <option
                                    value="<?= e($category) ?>"
                                    <?= $formData['category'] === $category
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= e($category) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-md-6">

                        <label
                            for="unit"
                            class="form-label"
                        >
                            Unit
                        </label>

                        <input
                            type="text"
                            id="unit"
                            name="unit"
                            class="form-control"
                            placeholder="Example: Piece(s), Box(es), Pack(s), Roll(s)"
                            value="<?= e($formData['unit']) ?>"
                            required
                        >

                    </div>

                    <div class="col-12">

                        <label
                            for="description"
                            class="form-label"
                        >
                            Description
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            class="form-control"
                            rows="4"
                            placeholder="Enter a complete description of the supply item, its contents, size, specifications, or any important details."
                        ><?= e($formData['description']) ?></textarea>

                    </div>

                    <div class="col-md-6">

                        <label
                            for="quantity"
                            class="form-label"
                        >
                            Quantity in Stock
                        </label>

                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            class="form-control"
                            min="0"
                            step="0.01"
                            placeholder="Example: 50"
                            value="<?= e($formData['quantity']) ?>"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label
                            for="lab_id"
                            class="form-label"
                        >
                            Laboratory
                        </label>

                        <select
                            id="lab_id"
                            name="lab_id"
                            class="form-select"
                            required
                        >

                            <option value="">
                                Select a laboratory
                            </option>

                            <?php foreach ($laboratories as $laboratory): ?>

                                <option
                                    value="<?= (int) $laboratory['id'] ?>"
                                    <?= (int) ($formData['lab_id'] ?: 0)
                                        === (int) $laboratory['id']
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= e($laboratory['name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12">

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
                            required
                        >

                            <?php foreach (
                                ['Available', 'Low Stock', 'Out of Stock']
                                as $status
                            ): ?>

                                <option
                                    value="<?= e($status) ?>"
                                    <?= $formData['status'] === $status
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= e($status) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <div class="supply-form-footer">

                    <a
                        href="index.php"
                        class="btn btn-light px-4"
                    >
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn btn-primary px-4"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        <?= $isEditing
                            ? 'Update Supply Item'
                            : 'Save Supply Item'
                        ?>

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php require '../../includes/footer.php'; ?>