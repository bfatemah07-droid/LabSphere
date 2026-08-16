<?php
$u = user();
$role = $u['role'];
$flash = take_flash();

/*
|--------------------------------------------------------------------------
| Automatic Maintenance Notifications
|--------------------------------------------------------------------------
| Runs silently for Admin and Supervisor users. The tracking table prevents
| the same due-soon or overdue alert from being created more than once.
*/
if (
    isset($pdo)
    && $pdo instanceof PDO
    && in_array($role, ['Supervisor', 'Admin'], true)
) {
    require_once __DIR__ . '/../modules/maintenance/maintenance_notifications.php';
    create_maintenance_due_notifications($pdo);
}

$unread_notifications = 0;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$u['id']]);
        $unread_notifications = (int) $stmt->fetchColumn();
    } catch (Throwable $exception) {
        $unread_notifications = 0;
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

    <title>
        <?= e($page_title ?? 'LabSphere') ?>
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="<?= BASE_URL ?>/assets/css/style.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --kau-green: #0A8A4B;
            --kau-green-dark: #06733E;
            --kau-green-light: #E7F6EE;

            --kau-gold: #C9A227;
            --kau-gold-dark: #A88618;
            --kau-gold-light: #FFF8DF;

            --page-bg: #F7FAF8;
            --card-bg: #FFFFFF;
            --border-color: #DDE7E2;

            --text-dark: #1F2937;
            --text-muted: #6B7280;

            --sidebar-width: 250px;
            --navbar-height: 78px;
        }

        body {
            background-color: var(--page-bg);
            color: var(--text-dark);
        }

      .navbar {
    height: 78px;
    min-height: 78px;
    padding: 0 16px;

    background: linear-gradient(
        135deg,
        var(--kau-green) 0%,
        var(--kau-green-dark) 100%
    ) !important;

    box-shadow: 0 4px 18px rgba(10, 138, 75, 0.18);
    z-index: 1040;
}

        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }

        .navbar-brand i {
            color: var(--kau-gold);
            font-size: 1.3rem;
        }

        .navbar .btn-light {
            color: var(--kau-green);
            border: 1px solid rgba(255, 255, 255, 0.65);
            font-weight: 600;
        }

        .navbar .btn-light:hover {
            background-color: var(--kau-gold);
            border-color: var(--kau-gold);
            color: #FFFFFF;
        }

        .sidebar {
            width: var(--sidebar-width);
            background-color: #FFFFFF;
            border-right: 1px solid var(--border-color);
            box-shadow: 4px 0 18px rgba(31, 41, 55, 0.05);
        }

        .offcanvas-header {
            border-bottom: 1px solid var(--border-color);
        }

        .offcanvas-header h5 {
            color: var(--kau-green);
            font-weight: 700;
            margin-bottom: 0;
        }

        .sidebar .nav {
            gap: 7px;
        }

        .sidebar .nav a {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 11px 14px;

            color: var(--text-dark);
            text-decoration: none;
            font-weight: 500;

            border-radius: 10px;

            transition:
                background-color 0.2s ease,
                color 0.2s ease,
                transform 0.2s ease;
        }

        .sidebar .nav a i {
            width: 20px;
            color: var(--kau-green);
            font-size: 1.05rem;
        }

        .sidebar .nav a:hover {
            color: var(--kau-green);
            background-color: var(--kau-green-light);
            transform: translateX(3px);
        }

        .sidebar .nav a:hover i {
            color: var(--kau-gold-dark);
        }

        .sidebar .nav a.active {
            color: #FFFFFF;
            background: linear-gradient(
                135deg,
                var(--kau-green) 0%,
                var(--kau-green-dark) 100%
            );

            box-shadow: 0 6px 16px rgba(10, 138, 75, 0.18);
        }

        .sidebar .nav a.active i {
            color: var(--kau-gold);
        }

       .content {
    padding-top: 48px;
}

        .content .container-fluid {
            min-height: calc(100vh - var(--navbar-height));
        }

        .btn-primary {
            background-color: var(--kau-green);
            border-color: var(--kau-green);
        }

        .btn-primary:hover,
        .btn-primary:focus,
        .btn-primary:active {
            background-color: var(--kau-green-dark) !important;
            border-color: var(--kau-green-dark) !important;
        }

        .btn-outline-primary {
            color: var(--kau-green);
            border-color: var(--kau-green);
        }

        .btn-outline-primary:hover {
            color: #FFFFFF;
            background-color: var(--kau-green);
            border-color: var(--kau-green);
        }

        .text-primary {
            color: var(--kau-green) !important;
        }

        .bg-primary {
            background-color: var(--kau-green) !important;
        }

        .border-primary {
            border-color: var(--kau-green) !important;
        }

        .link-primary {
            color: var(--kau-green) !important;
        }

        .link-primary:hover {
            color: var(--kau-green-dark) !important;
        }

        .card {
            border: 1px solid var(--border-color);
            border-radius: 14px;

            box-shadow: 0 6px 20px rgba(31, 41, 55, 0.05);

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(10, 138, 75, 0.09);
        }

        .card-header {
            background-color: #FFFFFF;
            border-bottom: 1px solid var(--border-color);
            color: var(--kau-green);
            font-weight: 700;
        }

        .table thead th {
            background-color: var(--kau-green);
            color: #FFFFFF;
            border-color: var(--kau-green-dark);
        }

        .table-hover tbody tr:hover {
            background-color: var(--kau-green-light);
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--kau-green);

            box-shadow:
                0 0 0 0.25rem rgba(10, 138, 75, 0.14);
        }

        .form-check-input:checked {
            background-color: var(--kau-green);
            border-color: var(--kau-green);
        }

        .page-link {
            color: var(--kau-green);
        }

        .page-link:hover {
            color: var(--kau-green-dark);
            background-color: var(--kau-green-light);
        }

        .page-item.active .page-link {
            background-color: var(--kau-green);
            border-color: var(--kau-green);
        }

        .nav-tabs .nav-link {
            color: var(--text-muted);
        }

        .nav-tabs .nav-link:hover {
            color: var(--kau-green);
        }

        .nav-tabs .nav-link.active {
            color: var(--kau-green);
            font-weight: 700;

            border-top-color: var(--kau-green);
            border-left-color: var(--border-color);
            border-right-color: var(--border-color);
        }

        .badge.bg-primary {
            background-color: var(--kau-green) !important;
        }

        .badge.bg-warning {
            background-color: var(--kau-gold) !important;
            color: #FFFFFF !important;
        }

        .alert-primary {
            color: var(--kau-green-dark);
            background-color: var(--kau-green-light);
            border-color: #BFDCCD;
        }

        .modal-header {
            border-bottom: 1px solid var(--border-color);
        }

        .modal-title {
            color: var(--kau-green);
            font-weight: 700;
        }

        .dropdown-item:active {
            background-color: var(--kau-green);
        }

        ::selection {
            color: #FFFFFF;
            background-color: var(--kau-green);
        }

        @media (min-width: 992px) {
            .sidebar {
                position: fixed;
                top: var(--navbar-height);
                bottom: 0;
                left: 0;

                overflow-y: auto;
            }

            .content {
                margin-left: var(--sidebar-width);
            }
        }

        @media (max-width: 991.98px) {
            .sidebar {
                padding-top: 0;
            }

            .navbar .container-fluid {
                gap: 10px;
            }

            .navbar .text-white {
                font-size: 0.75rem;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-dark fixed-top">
    <div class="container-fluid">

        <button
            class="navbar-toggler d-lg-none"
            type="button"
            data-bs-toggle="offcanvas"
            data-bs-target="#sidebar"
            aria-controls="sidebar"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

       <a class="navbar-brand d-flex align-items-center text-decoration-none"
   href="<?= BASE_URL ?>/dashboard.php">

    <img src="<?= BASE_URL ?>/assets/images/kau-logo.png"
         alt="King Abdulaziz University"
         style="
            width:52px;
            height:auto;
            margin-right:14px;
         ">

    <div class="d-flex flex-column justify-content-center">

        <span style="
            font-size:22px;
            font-weight:700;
            line-height:1;
            color:#fff;
            letter-spacing:.3px;">
            LabSphere
        </span>

        <span style="
            font-size:13px;
            font-weight:500;
            color:rgba(255,255,255,.95);
            line-height:1.2;">
            Laboratory Management System
        </span>

        <span style="
            font-size:11px;
            color:rgba(255,255,255,.75);
            line-height:1.2;">
            Joint Supervision Program • King Abdulaziz University
        </span>

    </div>

</a>

        <div class="text-white small d-flex align-items-center">

            <span>
                <?= e($u['name']) ?> · <?= e($role) ?>
            </span>


        </div>

    </div>
</nav>

<div
    class="offcanvas-lg offcanvas-start sidebar"
    tabindex="-1"
    id="sidebar"
    aria-labelledby="sidebarLabel"
>

    <div class="offcanvas-header">

        <h5 id="sidebarLabel">
            <i class="bi bi-beaker me-2"></i>
            LabSphere Menu
        </h5>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="offcanvas"
            data-bs-target="#sidebar"
            aria-label="Close"
        ></button>

    </div>

    <div class="offcanvas-body p-0">

        <nav class="nav flex-column p-3">

            <a href="<?= BASE_URL ?>/dashboard.php">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>

            <a href="<?= BASE_URL ?>/modules/labs/index.php">
                <i class="bi bi-building"></i>
                Laboratories
            </a>

            <a href="<?= BASE_URL ?>/modules/equipment/index.php">
                <i class="bi bi-cpu"></i>
                Equipment
            </a>

            <a href="<?= BASE_URL ?>/modules/materials/index.php">
                <i class="bi bi-box-seam"></i>
                Materials
            </a>

            <a href="<?= BASE_URL ?>/modules/supplies/index.php">
                <i class="bi bi-clipboard2-check"></i>
                Supplies
            </a>

            <a href="<?= BASE_URL ?>/modules/storage/index.php">
                <i class="bi bi-archive"></i>
                Storage Spaces
            </a>

            <a href="<?= BASE_URL ?>/modules/reservations/index.php">
                <i class="bi bi-calendar-check"></i>
                <?= $role === 'Student' ? 'My Reservations' : 'Reservations' ?>
            </a>

            <?php if (in_array($role, ['Supervisor', 'Admin'], true)): ?>

                <a href="<?= BASE_URL ?>/modules/maintenance/index.php">
                    <i class="bi bi-tools"></i>
                    Maintenance
                </a>

                <a href="<?= BASE_URL ?>/modules/reports/index.php">
                    <i class="bi bi-bar-chart"></i>
                    Reports
                </a>

            <?php endif; ?>

            <?php if ($role === 'Admin'): ?>

                <a href="<?= BASE_URL ?>/modules/users/index.php">
                    <i class="bi bi-people"></i>
                    Users
                </a>

            <?php endif; ?>

            <div class="border-top mt-3 pt-3"></div>

            <a href="<?= BASE_URL ?>/modules/notifications/index.php">
                <i class="bi bi-bell"></i>
                <span>Notifications</span>
                <?php if ($unread_notifications > 0): ?>
                    <span class="badge rounded-pill bg-danger ms-auto">
                        <?= $unread_notifications > 99 ? '99+' : e($unread_notifications) ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="<?= BASE_URL ?>/modules/profile/index.php">
                <i class="bi bi-person-circle"></i>
                My Profile
            </a>

            <a href="<?= BASE_URL ?>/logout.php" class="text-danger">
                <i class="bi bi-box-arrow-right text-danger"></i>
                Logout
            </a>

        </nav>

    </div>
</div>

<main class="content">

    <div class="container-fluid pt-2 pb-3">

        <?php if ($flash): ?>

            <div
                class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show"
                role="alert"
            >

                <?= e($flash['message']) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                    aria-label="Close"
                ></button>

            </div>

        <?php endif; ?>