<?php

require 'config/database.php';
require 'includes/auth.php';

if (user()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = $pdo->prepare(
        'SELECT *
         FROM users
         WHERE email = ?
           AND active = 1
         LIMIT 1'
    );

    $statement->execute([$email]);

    $account = $statement->fetch();

    if (
        $account &&
        password_verify(
            $password,
            $account['password_hash']
        )
    ) {

        unset($account['password_hash']);

        $_SESSION['user'] = $account;

        audit(
            $pdo,
            'LOGIN',
            'Authentication'
        );

        header(
            'Location: ' .
            BASE_URL .
            '/dashboard.php'
        );

        exit;
    }

    $error = 'Invalid email or password.';
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

    <title>LabSphere Login</title>

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
            --kau-gold-light: #FFF8DF;

            --text-dark: #1F2937;
            --text-muted: #6B7280;
            --border-color: #DDE7E2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;

            display: grid;
            place-items: center;

            color: var(--text-dark);

            background:
                radial-gradient(
                    circle at top left,
                    rgba(201, 162, 39, 0.12),
                    transparent 30%
                ),
                linear-gradient(
                    135deg,
                    var(--kau-green-light) 0%,
                    #F8FBF9 55%,
                    #FFFFFF 100%
                );
        }

        .login-wrapper {
            width: min(440px, 92vw);
        }

        .login-card {
            overflow: hidden;

            border: 1px solid rgba(10, 138, 75, 0.12);
            border-radius: 20px;

            background-color: #FFFFFF;

            box-shadow:
                0 22px 55px rgba(6, 115, 62, 0.16);
        }

        .login-header {
            position: relative;

            padding: 26px 28px 22px;

            text-align: center;
            color: #FFFFFF;

            background:
                linear-gradient(
                    135deg,
                    var(--kau-green) 0%,
                    var(--kau-green-dark) 100%
                );
        }

        .login-header::after {
            content: "";

            position: absolute;
            left: 50%;
            bottom: 0;

            width: 74px;
            height: 3px;

            transform: translateX(-50%);

            border-radius: 999px;

            background-color: var(--kau-gold);
        }

        .university-logo {
            width: 68px;
            height: 68px;

            margin-bottom: 10px;

            object-fit: contain;
        }

        .system-name {
            margin: 0;

            font-size: 29px;
            font-weight: 750;
            line-height: 1.05;

            letter-spacing: 0.2px;
        }

        .system-description {
            margin: 7px 0 0;

            font-size: 13px;
            font-weight: 600;

            color: rgba(255, 255, 255, 0.94);
        }

        .program-name {
            margin: 4px 0 0;

            font-size: 11px;

            color: rgba(255, 255, 255, 0.78);
        }

        .login-body {
            padding: 28px;
        }

        .welcome-title {
            margin-bottom: 4px;

            font-size: 20px;
            font-weight: 700;
            text-align: center;
        }

        .welcome-text {
            margin-bottom: 24px;

            font-size: 13px;
            text-align: center;

            color: var(--text-muted);
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;

            color: var(--text-dark);
        }

        .input-group-text {
            border-color: var(--border-color);

            color: var(--kau-green);
            background-color: var(--kau-green-light);
        }

        .form-control {
            min-height: 46px;

            border-color: var(--border-color);
        }

        .form-control:focus {
            border-color: var(--kau-green);

            box-shadow:
                0 0 0 0.25rem rgba(10, 138, 75, 0.13);
        }

        .btn-login {
            min-height: 46px;

            border: 0;
            border-radius: 9px;

            font-weight: 700;

            color: #FFFFFF;

            background:
                linear-gradient(
                    135deg,
                    var(--kau-green) 0%,
                    var(--kau-green-dark) 100%
                );

            box-shadow:
                0 8px 18px rgba(10, 138, 75, 0.2);

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .btn-login:hover,
        .btn-login:focus {
            color: #FFFFFF;

            transform: translateY(-1px);

            box-shadow:
                0 11px 24px rgba(10, 138, 75, 0.26);
        }

        .register-section {
            margin-top: 20px;

            font-size: 13px;
            text-align: center;

            color: var(--text-muted);
        }

        .register-section a {
            color: var(--kau-green-dark);
            font-weight: 700;
            text-decoration: none;
        }

        .register-section a:hover {
            color: var(--kau-gold-dark, #A88618);
        }

        .footer-text {
            margin-top: 16px;

            font-size: 11px;
            text-align: center;

            color: var(--text-muted);
        }

        .alert {
            font-size: 13px;
            border-radius: 10px;
        }

        @media (max-width: 576px) {

            .login-header {
                padding: 22px 20px 19px;
            }

            .login-body {
                padding: 24px 20px;
            }

            .university-logo {
                width: 60px;
                height: 60px;
            }

            .system-name {
                font-size: 25px;
            }
        }

    </style>

</head>

<body>

<div class="login-wrapper">

    <div class="login-card">

        <div class="login-header">

            <img
                src="<?= BASE_URL ?>/assets/images/kau-logo.png"
                alt="King Abdulaziz University"
                class="university-logo"
            >

            <h1 class="system-name">
                LabSphere
            </h1>

            <p class="system-description">
                Laboratory Management System
            </p>

            <p class="program-name">
                Joint Supervision Program • King Abdulaziz University
            </p>

        </div>

        <div class="login-body">

            <h2 class="welcome-title">
                Welcome Back
            </h2>

            <p class="welcome-text">
                Sign in to access the laboratory management platform.
            </p>

            <?php if ($success): ?>

                <div class="alert alert-success">
                    <?= e($success) ?>
                </div>

            <?php endif; ?>

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

                <div class="mb-3">

                    <label class="form-label">
                        Email
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-envelope"></i>
                        </span>

                        <input
                            class="form-control"
                            type="email"
                            name="email"
                            placeholder="Enter your email"
                            autocomplete="email"
                            required
                        >

                    </div>

                </div>

                <div class="mb-4">

                    <label class="form-label">
                        Password
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-lock"></i>
                        </span>

                        <input
                            class="form-control"
                            type="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                    </div>

                </div>

                <button
                    class="btn btn-login w-100"
                    type="submit"
                >
                    <i class="bi bi-box-arrow-in-right me-2"></i>
                    Sign In
                </button>

            </form>

            <div class="register-section">

                <span>
                    Don't have an account?
                </span>

                <a href="<?= BASE_URL ?>/register.php">
                    Create New Account
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