<?php

require 'config/database.php';
require 'includes/auth.php';

/*
|--------------------------------------------------------------------------
| Redirect Logged-in Users
|--------------------------------------------------------------------------
*/

if (user()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Initial Values
|--------------------------------------------------------------------------
*/

$error = '';
$success = '';

$name = '';
$email = '';
$contactNumber = '';
$researchTitle = '';
$college = '';
$department = '';
$specialization = '';

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');

    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $researchTitle = trim($_POST['research_title'] ?? '');
    $college = trim($_POST['college'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === '') {
        $error = 'Full name is required.';
    } elseif (strlen($name) < 2) {
        $error = 'Full name must contain at least 2 characters.';
    } elseif ($email === '') {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($contactNumber === '') {
        $error = 'Phone number is required.';
    } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $contactNumber)) {
        $error = 'Please enter a valid phone number.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    }

    /*
    |--------------------------------------------------------------------------
    | Check Email
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        $check = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = ?
             LIMIT 1'
        );

        $check->execute([$email]);

        if ($check->fetch()) {
            $error = 'An account with this email already exists.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Insert New User
    |--------------------------------------------------------------------------
    */

    if ($error === '') {

        try {

            $insert = $pdo->prepare(
                'INSERT INTO users (
                    name,
                    email,
                    contact_number,
                    research_title,
                    college,
                    department,
                    specialization,
                    password_hash,
                    role,
                    active,
                    created_at
                ) VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    NOW()
                )'
            );

            $insert->execute([
                $name,
                $email,
                $contactNumber,
                $researchTitle !== '' ? $researchTitle : null,
                $college !== '' ? $college : null,
                $department !== '' ? $department : null,
                $specialization !== '' ? $specialization : null,
                password_hash($password, PASSWORD_DEFAULT),
                'Student',
                1
            ]);

            audit(
                $pdo,
                'REGISTER',
                'New student account created'
            );

            $_SESSION['success'] =
                'Your account was created successfully. You can now sign in.';

            header('Location: ' . BASE_URL . '/index.php');
            exit;

        } catch (PDOException $exception) {

            $error =
                'Unable to create the account. Please try again.';
        }
    }
}

?>

<!doctype html>

<html lang="en">

<head>

    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Create Account | LabSphere</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --kau-green: #0A8A4B;
            --kau-green-dark: #06733E;
            --kau-green-light: #E7F6EE;
            --kau-gold: #C9A227;
            --kau-gold-dark: #A88618;
            --page-bg: #F7FAF8;
            --border-color: #DDE7E2;
            --text-dark: #1F2937;
            --text-muted: #6B7280;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 34px 15px;
            color: var(--text-dark);
            background:
                radial-gradient(circle at top left, rgba(201, 162, 39, 0.12), transparent 30%),
                linear-gradient(135deg, var(--kau-green-light) 0%, #F8FBF9 55%, #FFFFFF 100%);
        }

        .register-wrapper {
            width: min(860px, 96vw);
            margin: 0 auto;
        }

        .register-card {
            overflow: hidden;
            border: 1px solid rgba(10, 138, 75, 0.12);
            border-radius: 20px;
            background: #FFFFFF;
            box-shadow: 0 22px 55px rgba(6, 115, 62, 0.16);
        }

        .register-header {
            position: relative;
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 24px 28px;
            color: #FFFFFF;
            background: linear-gradient(135deg, var(--kau-green) 0%, var(--kau-green-dark) 100%);
        }

        .register-header::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--kau-gold), transparent);
        }

        .university-logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
            flex: 0 0 auto;
        }

        .system-name {
            margin: 0;
            font-size: 29px;
            font-weight: 750;
            line-height: 1;
            letter-spacing: 0.2px;
        }

        .system-description {
            margin: 6px 0 0;
            font-size: 14px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.94);
        }

        .program-name {
            margin: 3px 0 0;
            font-size: 11px;
            color: rgba(255, 255, 255, 0.78);
        }

        .register-body {
            padding: 28px;
        }

        .page-title {
            margin: 0 0 4px;
            font-size: 22px;
            font-weight: 700;
            text-align: center;
        }

        .page-subtitle {
            margin: 0 0 24px;
            font-size: 13px;
            color: var(--text-muted);
            text-align: center;
        }

        .form-section-title {
            margin-top: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
            color: var(--kau-green-dark);
            font-size: 15px;
            font-weight: 700;
        }

        .required {
            color: #dc3545;
        }

        .optional {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 400;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
        }

        .form-control {
            min-height: 45px;
            border-color: var(--border-color);
        }

        .form-control:focus {
            border-color: var(--kau-green);
            box-shadow: 0 0 0 0.25rem rgba(10, 138, 75, 0.13);
        }

        .form-text {
            color: var(--text-muted);
        }

        .btn-create {
            min-height: 46px;
            border: 0;
            border-radius: 9px;
            color: #FFFFFF;
            font-weight: 700;
            background: linear-gradient(135deg, var(--kau-green) 0%, var(--kau-green-dark) 100%);
            box-shadow: 0 8px 18px rgba(10, 138, 75, 0.2);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-create:hover,
        .btn-create:focus {
            color: #FFFFFF;
            transform: translateY(-1px);
            box-shadow: 0 11px 24px rgba(10, 138, 75, 0.26);
        }

        .sign-in-link {
            color: var(--kau-green-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .sign-in-link:hover {
            color: var(--kau-gold-dark);
        }

        .footer-text {
            margin-top: 16px;
            font-size: 11px;
            color: var(--text-muted);
            text-align: center;
        }

        .alert {
            border-radius: 10px;
            font-size: 13px;
        }

        @media (max-width: 576px) {
            body {
                padding: 18px 10px;
            }

            .register-header {
                padding: 20px;
                gap: 13px;
            }

            .register-body {
                padding: 22px 18px;
            }

            .university-logo {
                width: 54px;
                height: 54px;
            }

            .system-name {
                font-size: 25px;
            }

            .program-name {
                line-height: 1.25;
            }
        }
    </style>

</head>

<body>

<div class="register-wrapper">

    <div class="register-card">

        <div class="register-header">

            <img
                src="<?= BASE_URL ?>/assets/images/kau-logo.png"
                alt="King Abdulaziz University"
                class="university-logo"
            >

            <div>
                <h1 class="system-name">LabSphere</h1>
                <p class="system-description">Laboratory Management System</p>
                <p class="program-name">Joint Supervision Program • King Abdulaziz University</p>
            </div>

        </div>

        <div class="register-body">

            <h2 class="page-title">Create New Account</h2>

            <p class="page-subtitle">
                New accounts are created as Student accounts.
            </p>

            <?php if ($error): ?>

                <div class="alert alert-danger">
                    <?= e($error) ?>
                </div>

            <?php endif; ?>

            <form method="post">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= csrf_token() ?>"
                >

                <div class="row g-3">

                    <div class="col-12">

                        <label class="form-label">
                            Full Name
                            <span class="required">*</span>
                        </label>

                        <input
                            class="form-control"
                            type="text"
                            name="name"
                            value="<?= e($name) ?>"
                            maxlength="150"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Email
                            <span class="required">*</span>
                        </label>

                        <input
                            class="form-control"
                            type="email"
                            name="email"
                            value="<?= e($email) ?>"
                            maxlength="190"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Phone Number
                            <span class="required">*</span>
                        </label>

                        <input
                            class="form-control"
                            type="tel"
                            name="contact_number"
                            value="<?= e($contactNumber) ?>"
                            placeholder="05XXXXXXXX"
                            maxlength="20"
                            required
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Password
                            <span class="required">*</span>
                        </label>

                        <input
                            class="form-control"
                            type="password"
                            name="password"
                            minlength="8"
                            required
                        >

                        <div class="form-text">
                            Minimum 8 characters.
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">
                            Confirm Password
                            <span class="required">*</span>
                        </label>

                        <input
                            class="form-control"
                            type="password"
                            name="confirm_password"
                            minlength="8"
                            required
                        >

                    </div>

                    <div class="col-12">

                        <div class="form-section-title">
                            Academic Information
                            <span class="optional">(Optional)</span>
                        </div>

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">College</label>

                        <input
                            class="form-control"
                            type="text"
                            name="college"
                            value="<?= e($college) ?>"
                            maxlength="150"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">Department</label>

                        <input
                            class="form-control"
                            type="text"
                            name="department"
                            value="<?= e($department) ?>"
                            maxlength="150"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">Specialization</label>

                        <input
                            class="form-control"
                            type="text"
                            name="specialization"
                            value="<?= e($specialization) ?>"
                            maxlength="150"
                        >

                    </div>

                    <div class="col-md-6">

                        <label class="form-label">Research Title</label>

                        <input
                            class="form-control"
                            type="text"
                            name="research_title"
                            value="<?= e($researchTitle) ?>"
                            maxlength="255"
                        >

                    </div>

                    <div class="col-12 mt-4">

                        <button
                            class="btn btn-create w-100"
                            type="submit"
                        >
                            <i class="bi bi-person-plus me-2"></i>
                            Create Account
                        </button>

                    </div>

                </div>

            </form>

            <div class="text-center mt-3">

                <span class="text-muted">
                    Already have an account?
                </span>

                <a
                    href="<?= BASE_URL ?>/index.php"
                    class="sign-in-link"
                >
                    Sign In
                </a>

            </div>

        </div>

    </div>

    <div class="footer-text">
        © <?= date('Y') ?> Joint Supervision Program — King Abdulaziz University
    </div>

</div>

</body>

</html>