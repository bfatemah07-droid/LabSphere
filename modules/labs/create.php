<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_login();
require_role(['Supervisor', 'Admin']);

$formData = [
    'name' => '',
    'code' => '',
    'location' => '',
    'capacity' => 1,
    'responsible_supervisor_id' => ''
];

$errors = [];

$supervisors = $pdo->query(
    "SELECT id, name
     FROM users
     WHERE role = 'Supervisor'
       AND active = 1
     ORDER BY name"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $formData['name'] =
        trim($_POST['name'] ?? '');

    $formData['code'] =
        trim($_POST['code'] ?? '');

    $formData['location'] =
        trim($_POST['location'] ?? '');

    $formData['capacity'] =
        max(
            1,
            (int) ($_POST['capacity'] ?? 1)
        );

    $formData['responsible_supervisor_id'] =
        $_POST['responsible_supervisor_id']
        ?? '';

    if ($formData['name'] === '') {
        $errors[] = 'Laboratory name is required.';
    }

    if ($formData['code'] === '') {
        $errors[] = 'Room number is required.';
    }

    if (empty($errors)) {

        $statement = $pdo->prepare(
            'SELECT id
             FROM laboratories
             WHERE code = ?
             LIMIT 1'
        );

        $statement->execute([
            $formData['code']
        ]);

        if ($statement->fetch()) {
            $errors[] =
                'Another laboratory already uses this room number.';
        }
    }

    if (empty($errors)) {

        try {

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
                $formData['name'],
                $formData['code'],
                $formData['location'] !== ''
                    ? $formData['location']
                    : null,
                $formData['capacity'],
                $formData['responsible_supervisor_id'] !== ''
                    ? (int) $formData['responsible_supervisor_id']
                    : null
            ]);

            audit(
                $pdo,
                'CREATE',
                'Laboratories',
                $formData['name']
            );

            flash(
                'success',
                'Laboratory added successfully.'
            );

            header('Location: index.php');
            exit;

        } catch (PDOException $exception) {

            $errors[] =
                'An error occurred while adding the laboratory.';
        }
    }
}

$page_title = 'Add Laboratory';

require '../../includes/header.php';

?>

<div class="row justify-content-center">

    <div class="col-lg-8 col-xl-7">

        <div class="d-flex justify-content-between align-items-center mb-3">

            <div>
                <h2 class="mb-1">Add Laboratory</h2>

                <p class="text-muted mb-0">
                    Enter the new laboratory information.
                </p>
            </div>

            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>
                Back
            </a>

        </div>

        <div class="card p-4 laboratory-form-card">

            <?php if (!empty($errors)): ?>

                <div class="alert alert-danger">

                    <ul class="mb-0">

                        <?php foreach ($errors as $error): ?>

                            <li><?= e($error) ?></li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= csrf_token() ?>"
                >

                <div class="row g-3">

                    <div class="col-md-6">

                        <label class="form-label" for="name">
                            Laboratory Name
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="name"
                            name="name"
                            value="<?= e($formData['name']) ?>"
                            placeholder="Example: General Biology Laboratory"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label" for="code">
                            Room Number
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="code"
                            name="code"
                            value="<?= e($formData['code']) ?>"
                            placeholder="Example: 107GB256"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label" for="location">
                            Location
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="location"
                            name="location"
                            value="<?= e($formData['location']) ?>"
                            placeholder="Example: Ground Floor"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label" for="capacity">
                            Capacity
                        </label>

                        <input
                            type="number"
                            class="form-control"
                            id="capacity"
                            name="capacity"
                            min="1"
                            value="<?= e($formData['capacity']) ?>"
                            required
                        >

                    </div>

                    <div class="col-12">

                        <label
                            class="form-label"
                            for="responsible_supervisor_id"
                        >
                            Supervisor
                        </label>

                        <select
                            class="form-select"
                            id="responsible_supervisor_id"
                            name="responsible_supervisor_id"
                        >

                            <option value="">None</option>

                            <?php foreach ($supervisors as $supervisor): ?>

                                <option
                                    value="<?= (int) $supervisor['id'] ?>"
                                    <?= (
                                        (string) $formData['responsible_supervisor_id']
                                        ===
                                        (string) $supervisor['id']
                                    )
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

                    <a href="index.php" class="btn btn-light">
                        Cancel
                    </a>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>
                        Add Laboratory
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<style>

    .laboratory-form-card {
        border: 1px solid #e2e7ee;
        border-radius: 16px;
    }

</style>

<?php require '../../includes/footer.php'; ?>