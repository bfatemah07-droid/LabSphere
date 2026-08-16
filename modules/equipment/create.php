<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_role(['Supervisor', 'Admin']);

/*
|--------------------------------------------------------------------------
| Get Equipment for Editing
|--------------------------------------------------------------------------
*/

$edit = null;

if (isset($_GET['edit'])) {

    $equipmentId = (int) $_GET['edit'];

    $statement = $pdo->prepare(
        'SELECT *
         FROM equipment
         WHERE id = ?'
    );

    $statement->execute([$equipmentId]);

    $edit = $statement->fetch();

    if (!$edit) {

        flash(
            'danger',
            'Equipment not found.'
        );

        header('Location: index.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Save Equipment
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    $labId = !empty($_POST['lab_id'])
        ? (int) $_POST['lab_id']
        : null;

    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $serialNumber = trim($_POST['serial_number'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Optional Hourly Price
    |--------------------------------------------------------------------------
    */

    $hourlyPriceInput = trim($_POST['hourly_price'] ?? '');

    $hourlyPrice = $hourlyPriceInput !== ''
        ? (float) $hourlyPriceInput
        : null;

    $status = $_POST['status'] ?? 'Available';

    $usageInstructions =
        trim($_POST['usage_instructions'] ?? '');

    $safetyGuidelines =
        trim($_POST['safety_guidelines'] ?? '');

    $lastMaintenance =
        !empty($_POST['last_maintenance'])
            ? $_POST['last_maintenance']
            : null;

    $nextMaintenance =
        !empty($_POST['next_maintenance'])
            ? $_POST['next_maintenance']
            : null;

    $imagePath =
        trim($_POST['current_image'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === '') {

        flash(
            'danger',
            'Equipment name is required.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($hourlyPrice !== null && $hourlyPrice < 0) {

        flash(
            'danger',
            'Price per hour cannot be negative.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }

    $allowedStatuses = [
        'Available',
        'Under maintenance',
        'Broken',
        'Expired'
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'Available';
    }

    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Serial Number
    |--------------------------------------------------------------------------
    */

    if ($serialNumber !== '') {

        if ($id > 0) {

            $statement = $pdo->prepare(
                'SELECT id
                 FROM equipment
                 WHERE serial_number = ?
                   AND id != ?
                 LIMIT 1'
            );

            $statement->execute([
                $serialNumber,
                $id
            ]);

        } else {

            $statement = $pdo->prepare(
                'SELECT id
                 FROM equipment
                 WHERE serial_number = ?
                 LIMIT 1'
            );

            $statement->execute([
                $serialNumber
            ]);
        }

        if ($statement->fetch()) {

            flash(
                'danger',
                'Another equipment item already uses this serial number.'
            );

            $redirectUrl = $id
                ? 'create.php?edit=' . $id
                : 'create.php';

            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Equipment Image
    |--------------------------------------------------------------------------
    */

    if (
        isset($_FILES['equipment_image']) &&
        $_FILES['equipment_image']['error']
            !== UPLOAD_ERR_NO_FILE
    ) {

        if (
            $_FILES['equipment_image']['error']
            !== UPLOAD_ERR_OK
        ) {

            flash(
                'danger',
                'An error occurred while uploading the image.'
            );

            $redirectUrl = $id
                ? 'create.php?edit=' . $id
                : 'create.php';

            header('Location: ' . $redirectUrl);
            exit;
        }

        /*
         * Maximum file size is 5 MB.
         */

        if (
            $_FILES['equipment_image']['size']
            > 5 * 1024 * 1024
        ) {

            flash(
                'danger',
                'The image size must not exceed 5 MB.'
            );

            $redirectUrl = $id
                ? 'create.php?edit=' . $id
                : 'create.php';

            header('Location: ' . $redirectUrl);
            exit;
        }

        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        $temporaryPath =
            $_FILES['equipment_image']['tmp_name'];

        $fileInformation =
            finfo_open(FILEINFO_MIME_TYPE);

        $mimeType =
            finfo_file(
                $fileInformation,
                $temporaryPath
            );

        finfo_close($fileInformation);

        if (!isset($allowedMimeTypes[$mimeType])) {

            flash(
                'danger',
                'Only JPG, PNG and WEBP images are allowed.'
            );

            $redirectUrl = $id
                ? 'create.php?edit=' . $id
                : 'create.php';

            header('Location: ' . $redirectUrl);
            exit;
        }

        $extension =
            $allowedMimeTypes[$mimeType];

        $fileName =
            'equipment_' .
            date('Ymd_His') .
            '_' .
            bin2hex(random_bytes(4)) .
            '.' .
            $extension;

        $uploadDirectory =
            __DIR__ .
            '/../../uploads/equipment/';

        if (!is_dir($uploadDirectory)) {

            mkdir(
                $uploadDirectory,
                0775,
                true
            );
        }

        $destination =
            $uploadDirectory .
            $fileName;

        if (
            !move_uploaded_file(
                $temporaryPath,
                $destination
            )
        ) {

            flash(
                'danger',
                'The equipment image could not be saved.'
            );

            $redirectUrl = $id
                ? 'create.php?edit=' . $id
                : 'create.php';

            header('Location: ' . $redirectUrl);
            exit;
        }

        /*
         * Delete the old image when replacing it.
         */

        if ($imagePath !== '') {

            $oldImageFile =
                __DIR__ .
                '/../../' .
                $imagePath;

            if (is_file($oldImageFile)) {
                unlink($oldImageFile);
            }
        }

        $imagePath =
            'uploads/equipment/' .
            $fileName;
    }

    try {

        /*
        |--------------------------------------------------------------------------
        | Update Existing Equipment
        |--------------------------------------------------------------------------
        */

        if ($id > 0) {

            $statement = $pdo->prepare(
                'UPDATE equipment
                 SET lab_id = ?,
                     name = ?,
                     category = ?,
                     description = ?,
                     serial_number = ?,
                     hourly_price = ?,
                     status = ?,
                     usage_instructions = ?,
                     safety_guidelines = ?,
                     last_maintenance = ?,
                     next_maintenance = ?,
                     image_path = ?
                 WHERE id = ?'
            );

            $statement->execute([
                $labId,
                $name,
                $category,
                $description,
                $serialNumber !== ''
                    ? $serialNumber
                    : null,
                $hourlyPrice,
                $status,
                $usageInstructions,
                $safetyGuidelines,
                $lastMaintenance,
                $nextMaintenance,
                $imagePath !== ''
                    ? $imagePath
                    : null,
                $id
            ]);

            audit(
                $pdo,
                'UPDATE',
                'Equipment',
                $name
            );

            flash(
                'success',
                'Equipment updated successfully.'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Add New Equipment
            |--------------------------------------------------------------------------
            */

            $statement = $pdo->prepare(
                'INSERT INTO equipment
                (
                    lab_id,
                    name,
                    category,
                    description,
                    serial_number,
                    hourly_price,
                    status,
                    usage_instructions,
                    safety_guidelines,
                    last_maintenance,
                    next_maintenance,
                    image_path
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $labId,
                $name,
                $category,
                $description,
                $serialNumber !== ''
                    ? $serialNumber
                    : null,
                $hourlyPrice,
                $status,
                $usageInstructions,
                $safetyGuidelines,
                $lastMaintenance,
                $nextMaintenance,
                $imagePath !== ''
                    ? $imagePath
                    : null
            ]);

            audit(
                $pdo,
                'CREATE',
                'Equipment',
                $name
            );

            flash(
                'success',
                'Equipment added successfully.'
            );
        }

        header('Location: index.php');
        exit;

    } catch (PDOException $exception) {

        flash(
            'danger',
            'An error occurred while saving the equipment.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Laboratories
|--------------------------------------------------------------------------
*/

$labs = $pdo->query(
    'SELECT id, name
     FROM laboratories
     ORDER BY name'
)->fetchAll();

$page_title = $edit
    ? 'Edit Equipment'
    : 'Add Equipment';

require '../../includes/header.php';

?>

<div class="row justify-content-center">

    <div class="col-xl-9 col-lg-10">

        <div class="d-flex align-items-center mb-4">

            <a
                href="index.php"
                class="btn btn-outline-secondary me-3"
                title="Back to equipment"
            >
                <i class="bi bi-arrow-left"></i>
            </a>

            <div>

                <h2 class="mb-1">

                    <?= $edit
                        ? 'Edit Equipment'
                        : 'Add Equipment'
                    ?>

                </h2>

                <p class="text-muted mb-0">

                    <?= $edit
                        ? 'Update the equipment information and image.'
                        : 'Enter the equipment information and upload its image.'
                    ?>

                </p>

            </div>

        </div>

        <div class="card p-4">

            <form
                method="post"
                enctype="multipart/form-data"
            >

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

                <input
                    type="hidden"
                    name="current_image"
                    value="<?= e($edit['image_path'] ?? '') ?>"
                >

                <div class="row">

                    <!-- Equipment Image -->

                    <div class="col-lg-4 mb-4">

                        <label class="form-label">
                            Equipment Image
                        </label>

                        <div
                            class="image-preview-box"
                            id="imagePreviewBox"
                        >

                            <?php if (!empty($edit['image_path'])): ?>

                                <img
                                    src="../../<?= e($edit['image_path']) ?>"
                                    alt="Current equipment image"
                                    id="imagePreview"
                                >

                                <div
                                    class="image-placeholder d-none"
                                    id="imagePlaceholder"
                                >
                                    <i class="bi bi-image"></i>

                                    <span>
                                        Image preview
                                    </span>
                                </div>

                            <?php else: ?>

                                <img
                                    src=""
                                    alt="Equipment image preview"
                                    id="imagePreview"
                                    class="d-none"
                                >

                                <div
                                    class="image-placeholder"
                                    id="imagePlaceholder"
                                >
                                    <i class="bi bi-image"></i>

                                    <span>
                                        Image preview
                                    </span>
                                </div>

                            <?php endif; ?>

                        </div>

                        <input
                            type="file"
                            id="equipment_image"
                            name="equipment_image"
                            class="form-control mt-3"
                            accept=".jpg,.jpeg,.png,.webp"
                        >

                        <div class="form-text">
                            Upload a JPG, PNG or WEBP image.
                            Maximum size: 5 MB.
                        </div>

                    </div>

                    <!-- Main Information -->

                    <div class="col-lg-8">

                        <div class="row">

                            <!-- Equipment Name -->

                            <div class="col-md-6 mb-3">

                                <label
                                    for="name"
                                    class="form-label"
                                >
                                    Equipment Name
                                </label>

                                <input
                                    type="text"
                                    id="name"
                                    name="name"
                                    class="form-control"
                                    required
                                    value="<?= e($edit['name'] ?? '') ?>"
                                >

                            </div>

                            <!-- Laboratory -->

                            <div class="col-md-6 mb-3">

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
                                >

                                    <option value="">
                                        Select laboratory
                                    </option>

                                    <?php foreach ($labs as $lab): ?>

                                        <option
                                            value="<?= $lab['id'] ?>"
                                            <?= (
                                                $edit['lab_id'] ?? ''
                                            ) == $lab['id']
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= e($lab['name']) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- Category -->

                            <div class="col-md-6 mb-3">

                                <label
                                    for="category"
                                    class="form-label"
                                >
                                    Category
                                </label>

                                <input
                                    type="text"
                                    id="category"
                                    name="category"
                                    class="form-control"
                                    placeholder="Example: Analytical"
                                    value="<?= e($edit['category'] ?? '') ?>"
                                >

                            </div>

                            <!-- Serial Number -->

                            <div class="col-md-6 mb-3">

                                <label
                                    for="serial_number"
                                    class="form-label"
                                >
                                    Serial Number
                                </label>

                                <input
                                    type="text"
                                    id="serial_number"
                                    name="serial_number"
                                    class="form-control"
                                    value="<?= e(
                                        $edit['serial_number'] ?? ''
                                    ) ?>"
                                >

                            </div>

                            <!-- Price per Hour -->

                            <div class="col-md-6 mb-3">

                                <label
                                    for="hourly_price"
                                    class="form-label"
                                >
                                    Price per Hour

                                    <span class="text-muted small">
                                        (Optional)
                                    </span>
                                </label>

                                <div class="input-group">

                                    <input
                                        type="number"
                                        id="hourly_price"
                                        name="hourly_price"
                                        class="form-control"
                                        min="0"
                                        step="0.01"
                                        placeholder="Example: 50.00"
                                        value="<?= e(
                                            $edit['hourly_price'] ?? ''
                                        ) ?>"
                                    >

                                    <span class="input-group-text">
                                        SAR / hour
                                    </span>

                                </div>

                                <div class="form-text">
                                    Leave empty if this equipment is free.
                                </div>

                            </div>

                            <!-- Status -->

                            <div class="col-md-6 mb-3">

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

                                    <?php

                                    $statuses = [
                                        'Available',
                                        'Under maintenance',
                                        'Broken',
                                        'Expired'
                                    ];

                                    ?>

                                    <?php foreach ($statuses as $equipmentStatus): ?>

                                        <option
                                            value="<?= e($equipmentStatus) ?>"
                                            <?= (
                                                $edit['status']
                                                ?? 'Available'
                                            ) === $equipmentStatus
                                                ? 'selected'
                                                : ''
                                            ?>
                                        >
                                            <?= e($equipmentStatus) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <!-- Last Maintenance -->

                            <div class="col-md-6 mb-3">

                                <label
                                    for="last_maintenance"
                                    class="form-label"
                                >
                                    Last Maintenance
                                </label>

                                <input
                                    type="date"
                                    id="last_maintenance"
                                    name="last_maintenance"
                                    class="form-control"
                                    value="<?= e(
                                        $edit['last_maintenance']
                                        ?? ''
                                    ) ?>"
                                >

                            </div>

                            <!-- Next Maintenance -->

                            <div class="col-md-6 mb-3">

                                <label
                                    for="next_maintenance"
                                    class="form-label"
                                >
                                    Next Maintenance
                                </label>

                                <input
                                    type="date"
                                    id="next_maintenance"
                                    name="next_maintenance"
                                    class="form-control"
                                    value="<?= e(
                                        $edit['next_maintenance']
                                        ?? ''
                                    ) ?>"
                                >

                            </div>

                        </div>

                    </div>

                    <!-- Description -->

                    <div class="col-12 mb-3">

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
                            rows="3"
                            placeholder="Write a short description of the equipment."
                        ><?= e(
                            $edit['description'] ?? ''
                        ) ?></textarea>

                    </div>

                    <!-- Usage Instructions -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="usage_instructions"
                            class="form-label"
                        >
                            Usage Instructions
                        </label>

                        <textarea
                            id="usage_instructions"
                            name="usage_instructions"
                            class="form-control"
                            rows="4"
                            placeholder="Enter the basic usage instructions."
                        ><?= e(
                            $edit['usage_instructions'] ?? ''
                        ) ?></textarea>

                    </div>

                    <!-- Safety Guidelines -->

                    <div class="col-md-6 mb-3">

                        <label
                            for="safety_guidelines"
                            class="form-label"
                        >
                            Safety Guidelines
                        </label>

                        <textarea
                            id="safety_guidelines"
                            name="safety_guidelines"
                            class="form-control"
                            rows="4"
                            placeholder="Enter the safety guidelines."
                        ><?= e(
                            $edit['safety_guidelines'] ?? ''
                        ) ?></textarea>

                    </div>

                </div>

                <div class="d-flex gap-2 mt-2">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-check-lg me-1"></i>

                        <?= $edit
                            ? 'Update Equipment'
                            : 'Add Equipment'
                        ?>
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-light"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    </div>

</div>

<style>

    .image-preview-box {
        width: 100%;
        height: 280px;
        overflow: hidden;
        border: 2px dashed #d7dde5;
        border-radius: 10px;
        background-color: #f7f9fb;
    }

    .image-preview-box img {
        width: 100%;
        height: 100%;
        padding: 10px;
        object-fit: contain;
        background-color: #ffffff;
    }

    .image-placeholder {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 10px;
        color: #8d98a8;
    }

    .image-placeholder i {
        font-size: 52px;
    }

</style>

<script>

    const imageInput =
        document.getElementById('equipment_image');

    const imagePreview =
        document.getElementById('imagePreview');

    const imagePlaceholder =
        document.getElementById('imagePlaceholder');

    imageInput.addEventListener(
        'change',
        function () {

            const selectedFile =
                imageInput.files[0];

            if (!selectedFile) {
                return;
            }

            const imageUrl =
                URL.createObjectURL(selectedFile);

            imagePreview.src = imageUrl;

            imagePreview.classList.remove('d-none');

            imagePlaceholder.classList.add('d-none');
        }
    );

</script>

<?php require '../../includes/footer.php'; ?>