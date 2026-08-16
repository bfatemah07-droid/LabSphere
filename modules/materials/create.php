<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();
require_role(['Supervisor', 'Admin']);

$laboratories = $pdo->query(
    'SELECT id, name
     FROM laboratories
     ORDER BY name'
)->fetchAll();

$errors = [];

$formData = [
    'lab_id' => '',
    'name' => '',
    'type' => 'Liquid',
    'description' => '',
    'available_quantity' => '',
    'unit' => 'L',
    'low_stock_threshold' => '0.1',
    'max_stock_level' => '',
    'safety_notes' => '',
    'expiry_date' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    foreach ($formData as $field => $defaultValue) {
        $formData[$field] = trim((string) ($_POST[$field] ?? $defaultValue));
    }

    if ($formData['name'] === '') {
        $errors[] = 'Material name is required.';
    }

    if (!in_array($formData['type'], ['Gas', 'Liquid', 'Solid'], true)) {
        $errors[] = 'Please select a valid material type.';
    }

    if (!in_array($formData['unit'], ['L', 'g'], true)) {
        $errors[] = 'Please select Liter or Gram.';
    }

    if (!is_numeric($formData['available_quantity'])) {
        $errors[] = 'Quantity must be a number.';
    }

    if (!is_numeric($formData['low_stock_threshold'])) {
        $errors[] = 'Low-stock threshold must be a number.';
    }

    if (
        $formData['max_stock_level'] !== ''
        && !is_numeric($formData['max_stock_level'])
    ) {
        $errors[] = 'Maximum stock must be a number.';
    }

    if (empty($errors)) {

        $statement = $pdo->prepare(
            'INSERT INTO materials
            (
                lab_id,
                name,
                type,
                description,
                available_quantity,
                unit,
                low_stock_threshold,
                max_stock_level,
                safety_notes,
                expiry_date
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $statement->execute([
            $formData['lab_id'] !== ''
                ? (int) $formData['lab_id']
                : null,
            $formData['name'],
            $formData['type'],
            $formData['description'],
            max(0, (float) $formData['available_quantity']),
            $formData['unit'],
            max(0, (float) $formData['low_stock_threshold']),
            $formData['max_stock_level'] !== ''
                ? max(0, (float) $formData['max_stock_level'])
                : 0,
            $formData['safety_notes'],
            $formData['expiry_date'] !== ''
                ? $formData['expiry_date']
                : null
        ]);

        audit(
            $pdo,
            'CREATE',
            'Materials',
            $formData['name']
        );

        flash('success', 'Material added successfully.');

        header('Location: index.php');
        exit;
    }
}

$page_title = 'Add Material';

require '../../includes/header.php';

?>

<style>
    .material-form-wrapper {
        max-width: 980px;
        margin: 0 auto;
    }

    .material-form-card {
        border: 1px solid #e5eaf1;
        border-radius: 16px;
        box-shadow: 0 4px 18px rgba(15, 23, 42, 0.05);
    }

    .material-form-card .card-body {
        padding: 1.5rem;
    }

    .material-form-card .form-label {
        font-weight: 600;
        margin-bottom: 0.4rem;
    }

    .material-form-card .form-control,
    .material-form-card .form-select {
        min-height: 42px;
    }

    @media (max-width: 767.98px) {
        .material-form-card .card-body {
            padding: 1rem;
        }
    }
</style>

<div class="material-form-wrapper">
    <div class="d-flex align-items-center gap-3 mb-3">
        <a href="index.php" class="btn btn-outline-secondary" aria-label="Back">
            <i class="bi bi-arrow-left"></i>
        </a>

        <div>
            <h2 class="mb-1">Add Material</h2>
            <p class="text-muted mb-0">
                Enter the material information below.
            </p>
        </div>
    </div>

    <div class="card material-form-card">
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-end mb-2">
                <a href="index.php" class="btn-close" aria-label="Close"></a>
            </div>

            <form method="post">
                <input type="hidden" name="csrf" value="<?= csrf_token() ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Material Name</label>
                        <input
                            type="text"
                            class="form-control"
                            name="name"
                            required
                            value="<?= e($formData['name']) ?>"
                        >
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" required>
                            <?php foreach (['Gas', 'Liquid', 'Solid'] as $materialType): ?>
                                <option
                                    value="<?= e($materialType) ?>"
                                    <?= $formData['type'] === $materialType ? 'selected' : '' ?>
                                >
                                    <?= e($materialType) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Laboratory</label>
                        <select class="form-select" name="lab_id">
                            <option value="">None</option>
                            <?php foreach ($laboratories as $laboratory): ?>
                                <option
                                    value="<?= (int) $laboratory['id'] ?>"
                                    <?= (int) ($formData['lab_id'] ?: 0) === (int) $laboratory['id'] ? 'selected' : '' ?>
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
                            rows="2"
                        ><?= e($formData['description']) ?></textarea>
                    </div>

                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Available Quantity</label>
                        <input
                            type="text"
                            class="form-control"
                            name="available_quantity"
                            inputmode="decimal"
                            pattern="^\d+(\.\d+)?$"
                            required
                            placeholder="Example: 1.5"
                            value="<?= e($formData['available_quantity']) ?>"
                        >
                    </div>

                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Unit</label>
                        <select class="form-select" name="unit" required>
                            <option value="L" <?= $formData['unit'] === 'L' ? 'selected' : '' ?>>
                                Liter (L)
                            </option>
                            <option value="g" <?= $formData['unit'] === 'g' ? 'selected' : '' ?>>
                                Gram (g)
                            </option>
                        </select>
                    </div>

                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Low-stock Threshold</label>
                        <input
                            type="text"
                            class="form-control"
                            name="low_stock_threshold"
                            inputmode="decimal"
                            pattern="^\d+(\.\d+)?$"
                            value="<?= e($formData['low_stock_threshold']) ?>"
                        >
                        <div class="form-text">
                            Default low-stock level: 0.1
                        </div>
                    </div>

                    <div class="col-sm-6 col-md-3">
                        <label class="form-label">Maximum Stock</label>
                        <input
                            type="text"
                            class="form-control"
                            name="max_stock_level"
                            inputmode="decimal"
                            pattern="^\d+(\.\d+)?$"
                            placeholder="Optional"
                            value="<?= e($formData['max_stock_level']) ?>"
                        >
                        <div class="form-text">
                            Leave empty if the full-stock capacity is unknown.
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Expiry Date</label>
                        <input
                            type="date"
                            class="form-control"
                            name="expiry_date"
                            value="<?= e($formData['expiry_date']) ?>"
                        >
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Safety Notes</label>
                        <textarea
                            class="form-control"
                            name="safety_notes"
                            rows="2"
                        ><?= e($formData['safety_notes']) ?></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <a href="index.php" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>
                        Save Material
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require '../../includes/footer.php'; ?>