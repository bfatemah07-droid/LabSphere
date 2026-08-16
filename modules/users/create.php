<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_role(['Admin']);

/*
|--------------------------------------------------------------------------
| Get User for Editing
|--------------------------------------------------------------------------
*/
$edit = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];

    $statement = $pdo->prepare(
        'SELECT * FROM users WHERE id = ?'
    );

    $statement->execute([$editId]);
    $edit = $statement->fetch();

    if (!$edit) {
        flash('danger', 'User not found.');

        header('Location: index.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Add or Update User
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $id = (int) ($_POST['id'] ?? 0);

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');
    $researchTitle = trim($_POST['research_title'] ?? '');
    $college = trim($_POST['college'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'Student';
    $active = isset($_POST['active']) ? 1 : 0;

    /*
    |--------------------------------------------------------------------------
    | Validate Main Fields
    |--------------------------------------------------------------------------
    */
    if ($name === '' || $email === '') {
        flash(
            'danger',
            'Name and email are required.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash(
            'danger',
            'Please enter a valid email address.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Role
    |--------------------------------------------------------------------------
    */
    $allowedRoles = [
        'Student',
        'Supervisor',
        'Admin'
    ];

    if (!in_array($role, $allowedRoles, true)) {
        $role = 'Student';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Role-Specific Fields
    |--------------------------------------------------------------------------
    */

    // Student must have all student information.
    if ($role === 'Student') {
        if (
            $contactNumber === '' ||
            $researchTitle === '' ||
            $college === '' ||
            $department === '' ||
            $specialization === ''
        ) {
            flash(
                'danger',
                'Please complete all student information.'
            );

            $redirectUrl = $id
                ? 'create.php?edit=' . $id
                : 'create.php';

            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Supervisor must have a contact number.
    if ($role === 'Supervisor' && $contactNumber === '') {
        flash(
            'danger',
            'Contact number is required for the supervisor.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }

    /*
     * Admin does not need role-specific information.
     */
    if ($role === 'Admin') {
        $contactNumber = '';
        $researchTitle = '';
        $college = '';
        $department = '';
        $specialization = '';
    }

    /*
     * Supervisor only needs the contact number.
     */
    if ($role === 'Supervisor') {
        $researchTitle = '';
        $college = '';
        $department = '';
        $specialization = '';
    }

    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Email
    |--------------------------------------------------------------------------
    */
    if ($id) {
        $statement = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = ?
             AND id != ?'
        );

        $statement->execute([
            $email,
            $id
        ]);
    } else {
        $statement = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = ?'
        );

        $statement->execute([$email]);
    }

    if ($statement->fetch()) {
        flash(
            'danger',
            'This email address is already registered.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }

    try {
        /*
        |--------------------------------------------------------------------------
        | Update Existing User
        |--------------------------------------------------------------------------
        */
        if ($id) {
            if ($password !== '') {
                $statement = $pdo->prepare(
                    'UPDATE users
                     SET name = ?,
                         email = ?,
                         contact_number = ?,
                         research_title = ?,
                         college = ?,
                         department = ?,
                         specialization = ?,
                         password_hash = ?,
                         role = ?,
                         active = ?
                     WHERE id = ?'
                );

                $statement->execute([
                    $name,
                    $email,
                    $contactNumber ?: null,
                    $researchTitle ?: null,
                    $college ?: null,
                    $department ?: null,
                    $specialization ?: null,
                    password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    ),
                    $role,
                    $active,
                    $id
                ]);
            } else {
                $statement = $pdo->prepare(
                    'UPDATE users
                     SET name = ?,
                         email = ?,
                         contact_number = ?,
                         research_title = ?,
                         college = ?,
                         department = ?,
                         specialization = ?,
                         role = ?,
                         active = ?
                     WHERE id = ?'
                );

                $statement->execute([
                    $name,
                    $email,
                    $contactNumber ?: null,
                    $researchTitle ?: null,
                    $college ?: null,
                    $department ?: null,
                    $specialization ?: null,
                    $role,
                    $active,
                    $id
                ]);
            }

            audit(
                $pdo,
                'UPDATE',
                'Users',
                $email
            );

            flash(
                'success',
                'User updated successfully.'
            );
        } else {
            /*
            |--------------------------------------------------------------------------
            | Add New User
            |--------------------------------------------------------------------------
            */
            if ($password === '') {
                flash(
                    'danger',
                    'Password is required for a new user.'
                );

                header('Location: create.php');
                exit;
            }

            $statement = $pdo->prepare(
                'INSERT INTO users
                (
                    name,
                    email,
                    contact_number,
                    research_title,
                    college,
                    department,
                    specialization,
                    password_hash,
                    role,
                    active
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $statement->execute([
                $name,
                $email,
                $contactNumber ?: null,
                $researchTitle ?: null,
                $college ?: null,
                $department ?: null,
                $specialization ?: null,
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                $role,
                $active
            ]);

            audit(
                $pdo,
                'CREATE',
                'Users',
                $email
            );

            flash(
                'success',
                'User added successfully.'
            );
        }

        header('Location: index.php');
        exit;
    } catch (PDOException $exception) {
        flash(
            'danger',
            'An error occurred while saving the user.'
        );

        $redirectUrl = $id
            ? 'create.php?edit=' . $id
            : 'create.php';

        header('Location: ' . $redirectUrl);
        exit;
    }
}

$page_title = $edit
    ? 'Edit User'
    : 'Add User';

require '../../includes/header.php';

?>

<div class="row justify-content-center">

    <div class="col-lg-8 col-xl-7">

        <div class="d-flex align-items-center mb-4">

            <a
                href="index.php"
                class="btn btn-outline-secondary me-3"
                title="Back to users"
            >
                <i class="bi bi-arrow-left"></i>
            </a>

            <div>
                <h2 class="mb-1">
                    <?= $edit ? 'Edit User' : 'Add User' ?>
                </h2>

                <p class="text-muted mb-0">
                    <?= $edit
                        ? 'Update the selected user information.'
                        : 'Enter the information for the new user.'
                    ?>
                </p>
            </div>

        </div>

        <div class="card p-4">

            <form method="post" id="userForm">

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

                <!-- Name -->
                <div class="mb-3">

                    <label
                        for="name"
                        class="form-label"
                    >
                        Name
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

                <!-- Email -->
                <div class="mb-3">

                    <label
                        for="email"
                        class="form-label"
                    >
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="form-control"
                        required
                        value="<?= e($edit['email'] ?? '') ?>"
                    >

                </div>

                <!-- Password -->
                <div class="mb-3">

                    <label
                        for="password"
                        class="form-label"
                    >
                        Password

                        <?php if ($edit): ?>

                            <span class="text-muted fw-normal">
                                (leave blank to keep the current password)
                            </span>

                        <?php endif; ?>

                    </label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        <?= $edit ? '' : 'required' ?>
                    >

                </div>

                <!-- Role -->
                <div class="mb-3">

                    <label
                        for="role"
                        class="form-label"
                    >
                        Role
                    </label>

                    <select
                        id="role"
                        name="role"
                        class="form-select"
                        onchange="toggleRoleFields()"
                    >

                        <?php
                        $roles = [
                            'Student',
                            'Supervisor',
                            'Admin'
                        ];
                        ?>

                        <?php foreach ($roles as $role): ?>

                            <option
                                value="<?= $role ?>"
                                <?=
                                    ($edit['role'] ?? 'Student') === $role
                                        ? 'selected'
                                        : ''
                                ?>
                            >
                                <?= $role ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <!-- Contact Number -->
                <div
                    class="mb-3"
                    id="contactNumberField"
                >

                    <label
                        for="contact_number"
                        class="form-label"
                    >
                        Contact Number
                    </label>

                    <input
                        type="tel"
                        id="contact_number"
                        name="contact_number"
                        class="form-control"
                        placeholder="Example: 05XXXXXXXX"
                        value="<?= e(
                            $edit['contact_number'] ?? ''
                        ) ?>"
                    >

                </div>

                <!-- Student Fields -->
                <div id="studentFields">

                    <hr class="my-4">

                    <h5 class="mb-3">
                        Student Research Information
                    </h5>

                    <!-- Research Title -->
                    <div class="mb-3">

                        <label
                            for="research_title"
                            class="form-label"
                        >
                            Research Title
                        </label>

                        <input
                            type="text"
                            id="research_title"
                            name="research_title"
                            class="form-control"
                            value="<?= e(
                                $edit['research_title'] ?? ''
                            ) ?>"
                        >

                    </div>

                    <!-- College -->
                    <div class="mb-3">

                        <label
                            for="college"
                            class="form-label"
                        >
                            College
                        </label>

                        <input
                            type="text"
                            id="college"
                            name="college"
                            class="form-control"
                            value="<?= e(
                                $edit['college'] ?? ''
                            ) ?>"
                        >

                    </div>

                    <!-- Department -->
                    <div class="mb-3">

                        <label
                            for="department"
                            class="form-label"
                        >
                            Department
                        </label>

                        <input
                            type="text"
                            id="department"
                            name="department"
                            class="form-control"
                            value="<?= e(
                                $edit['department'] ?? ''
                            ) ?>"
                        >

                    </div>

                    <!-- Specialization -->
                    <div class="mb-3">

                        <label
                            for="specialization"
                            class="form-label"
                        >
                            Specialization
                        </label>

                        <input
                            type="text"
                            id="specialization"
                            name="specialization"
                            class="form-control"
                            value="<?= e(
                                $edit['specialization'] ?? ''
                            ) ?>"
                        >

                    </div>

                </div>

                <!-- Active -->
                <div class="form-check mb-4">

                    <input
                        type="checkbox"
                        id="active"
                        name="active"
                        class="form-check-input"
                        <?=
                            ($edit['active'] ?? 1)
                                ? 'checked'
                                : ''
                        ?>
                    >

                    <label
                        for="active"
                        class="form-check-label"
                    >
                        Active
                    </label>

                </div>

                <!-- Buttons -->
                <div class="d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-check-lg"></i>

                        <?= $edit
                            ? 'Update User'
                            : 'Save User'
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

<script>
    function toggleRoleFields() {
        const role = document.getElementById('role').value;

        const contactNumberField =
            document.getElementById('contactNumberField');

        const contactNumberInput =
            document.getElementById('contact_number');

        const studentFields =
            document.getElementById('studentFields');

        const researchTitle =
            document.getElementById('research_title');

        const college =
            document.getElementById('college');

        const department =
            document.getElementById('department');

        const specialization =
            document.getElementById('specialization');

        /*
         * Contact number appears for Student and Supervisor.
         */
        if (role === 'Student' || role === 'Supervisor') {
            contactNumberField.style.display = 'block';
            contactNumberInput.required = true;
        } else {
            contactNumberField.style.display = 'none';
            contactNumberInput.required = false;
        }

        /*
         * Research information appears only for Student.
         */
        if (role === 'Student') {
            studentFields.style.display = 'block';

            researchTitle.required = true;
            college.required = true;
            department.required = true;
            specialization.required = true;
        } else {
            studentFields.style.display = 'none';

            researchTitle.required = false;
            college.required = false;
            department.required = false;
            specialization.required = false;
        }
    }

    /*
     * Run when the page first opens,
     * especially when editing an existing user.
     */
    document.addEventListener(
        'DOMContentLoaded',
        toggleRoleFields
    );
</script>

<?php require '../../includes/footer.php'; ?>