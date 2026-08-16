<?php

require '../../config/database.php';
require '../../includes/auth.php';

require_role(['Supervisor', 'Admin']);

/*
|--------------------------------------------------------------------------
| Date Filters
|--------------------------------------------------------------------------
*/

$defaultFromDate = date('Y-m-01');
$defaultToDate = date('Y-m-d');

$fromDate = $_GET['from_date'] ?? $defaultFromDate;
$toDate = $_GET['to_date'] ?? $defaultToDate;

/*
 * Validate date format.
 */
$fromDateObject = DateTime::createFromFormat('Y-m-d', $fromDate);
$toDateObject = DateTime::createFromFormat('Y-m-d', $toDate);

if (
    !$fromDateObject ||
    $fromDateObject->format('Y-m-d') !== $fromDate
) {
    $fromDate = $defaultFromDate;
}

if (
    !$toDateObject ||
    $toDateObject->format('Y-m-d') !== $toDate
) {
    $toDate = $defaultToDate;
}

/*
 * Prevent the ending date from being before the starting date.
 */
if ($fromDate > $toDate) {
    $temporaryDate = $fromDate;
    $fromDate = $toDate;
    $toDate = $temporaryDate;
}

/*
|--------------------------------------------------------------------------
| Low-stock Materials
|--------------------------------------------------------------------------
|
| This is a current inventory report, so it does not depend on the
| selected date range.
|
*/

$lowStockMaterials = $pdo->query(
    'SELECT
        materials.name,
        materials.type,
        materials.available_quantity,
        materials.unit,
        materials.low_stock_threshold,
        laboratories.name AS lab_name
     FROM materials
     LEFT JOIN laboratories
        ON laboratories.id = materials.lab_id
     WHERE materials.available_quantity <= materials.low_stock_threshold
     ORDER BY materials.available_quantity ASC'
)->fetchAll();

/*
|--------------------------------------------------------------------------
| Maintenance Report
|--------------------------------------------------------------------------
|
| Equipment with next maintenance dates inside the selected period.
|
*/

$maintenanceStatement = $pdo->prepare(
    'SELECT
        equipment.name,
        equipment.serial_number,
        equipment.status,
        equipment.next_maintenance,
        laboratories.name AS lab_name
     FROM equipment
     LEFT JOIN laboratories
        ON laboratories.id = equipment.lab_id
     WHERE equipment.next_maintenance IS NOT NULL
       AND equipment.next_maintenance BETWEEN ? AND ?
     ORDER BY equipment.next_maintenance ASC'
);

$maintenanceStatement->execute([
    $fromDate,
    $toDate
]);

$maintenanceRows = $maintenanceStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Reservation Status Summary
|--------------------------------------------------------------------------
*/

$reservationStatusStatement = $pdo->prepare(
    'SELECT
        status,
        COUNT(*) AS total
     FROM reservations
     WHERE date_needed BETWEEN ? AND ?
     GROUP BY status
     ORDER BY status'
);

$reservationStatusStatement->execute([
    $fromDate,
    $toDate
]);

$reservationStatuses =
    $reservationStatusStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Detailed Reservations Report
|--------------------------------------------------------------------------
*/

$reservationsStatement = $pdo->prepare(
    'SELECT
        reservations.id,
        reservations.type,
        reservations.item_id,
        reservations.quantity,
        reservations.unit,
        reservations.time_slot,
        reservations.date_needed,
        reservations.purpose,
        reservations.status,
        users.name AS user_name,
        users.email AS user_email,
        CASE
            WHEN reservations.type = "Equipment"
                THEN equipment.name
            WHEN reservations.type = "Material"
                THEN materials.name
            ELSE "Unknown"
        END AS item_name
     FROM reservations
     INNER JOIN users
        ON users.id = reservations.user_id
     LEFT JOIN equipment
        ON reservations.type = "Equipment"
       AND equipment.id = reservations.item_id
     LEFT JOIN materials
        ON reservations.type = "Material"
       AND materials.id = reservations.item_id
     WHERE reservations.date_needed BETWEEN ? AND ?
     ORDER BY
        reservations.date_needed DESC,
        reservations.time_slot ASC'
);

$reservationsStatement->execute([
    $fromDate,
    $toDate
]);

$reservations =
    $reservationsStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Users Report
|--------------------------------------------------------------------------
|
| Users registered during the selected period.
|
*/

$usersStatement = $pdo->prepare(
    'SELECT
        id,
        name,
        email,
        contact_number,
        role,
        research_title,
        college,
        department,
        specialization,
        active,
        created_at
     FROM users
     WHERE DATE(created_at) BETWEEN ? AND ?
     ORDER BY created_at DESC'
);

$usersStatement->execute([
    $fromDate,
    $toDate
]);

$users =
    $usersStatement->fetchAll();

/*
|--------------------------------------------------------------------------
| Report Totals
|--------------------------------------------------------------------------
*/

$totalReservations =
    count($reservations);

$totalUsers =
    count($users);

$totalLowStock =
    count($lowStockMaterials);

$totalMaintenance =
    count($maintenanceRows);

$page_title = 'Reports';

require '../../includes/header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>

        <h2 class="mb-1">
            Reports
        </h2>

        <p class="text-muted mb-0">
            Select a date range, then print each report separately.
        </p>

    </div>

</div>

<!-- Date Range Filter -->

<div class="card p-3 mb-4 no-print">

    <form method="get">

        <div class="row g-3 align-items-end">

            <div class="col-md-4">

                <label
                    for="from_date"
                    class="form-label"
                >
                    From Date
                </label>

                <input
                    type="date"
                    id="from_date"
                    name="from_date"
                    class="form-control"
                    value="<?= e($fromDate) ?>"
                    required
                >

            </div>

            <div class="col-md-4">

                <label
                    for="to_date"
                    class="form-label"
                >
                    To Date
                </label>

                <input
                    type="date"
                    id="to_date"
                    name="to_date"
                    class="form-control"
                    value="<?= e($toDate) ?>"
                    required
                >

            </div>

            <div class="col-md-4">

                <div class="d-flex gap-2">

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-funnel me-1"></i>

                        Apply Date Range
                    </button>

                    <a
                        href="index.php"
                        class="btn btn-light"
                    >
                        Reset
                    </a>

                </div>

            </div>

        </div>

    </form>

</div>

<!-- Summary Cards -->

<div class="row g-3 mb-4 no-print">

    <div class="col-sm-6 col-xl-3">

        <div class="card p-3 h-100">

            <div class="small text-muted">
                Reservations
            </div>

            <div class="fs-3 fw-semibold">
                <?= $totalReservations ?>
            </div>

            <div class="small text-muted">
                During selected period
            </div>

        </div>

    </div>

    <div class="col-sm-6 col-xl-3">

        <div class="card p-3 h-100">

            <div class="small text-muted">
                New Users
            </div>

            <div class="fs-3 fw-semibold">
                <?= $totalUsers ?>
            </div>

            <div class="small text-muted">
                Registered during period
            </div>

        </div>

    </div>

    <div class="col-sm-6 col-xl-3">

        <div class="card p-3 h-100">

            <div class="small text-muted">
                Low-stock Materials
            </div>

            <div class="fs-3 fw-semibold">
                <?= $totalLowStock ?>
            </div>

            <div class="small text-muted">
                Current inventory status
            </div>

        </div>

    </div>

    <div class="col-sm-6 col-xl-3">

        <div class="card p-3 h-100">

            <div class="small text-muted">
                Maintenance
            </div>

            <div class="fs-3 fw-semibold">
                <?= $totalMaintenance ?>
            </div>

            <div class="small text-muted">
                Due during selected period
            </div>

        </div>

    </div>

</div>

<div class="row g-4">

    <!-- Low Stock Materials Report -->

    <div class="col-12">

        <div
            class="card p-3 report-section"
            id="lowStockReport"
        >

            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">

                <div>

                    <h5 class="mb-1">
                        Low-stock Materials
                    </h5>

                    <p class="small text-muted mb-0">
                        Current materials at or below their low-stock threshold.
                    </p>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary no-print"
                    onclick="printReport(
                        'lowStockReport',
                        'Low-stock Materials Report',
                        false
                    )"
                >
                    <i class="bi bi-printer me-1"></i>

                    Print
                </button>

            </div>

            <div class="report-print-header">
                Low-stock Materials Report
            </div>

            <div class="table-responsive">

                <table class="table table-bordered align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                Material
                            </th>

                            <th>
                                Type
                            </th>

                            <th>
                                Laboratory
                            </th>

                            <th>
                                Available
                            </th>

                            <th>
                                Threshold
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($lowStockMaterials)): ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="text-center text-muted py-4"
                                >
                                    No low-stock materials found.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($lowStockMaterials as $row): ?>

                                <tr>

                                    <td>
                                        <?= e($row['name']) ?>
                                    </td>

                                    <td>
                                        <?= e($row['type']) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['lab_name']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['available_quantity'] .
                                            ' ' .
                                            $row['unit']
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['low_stock_threshold'] .
                                            ' ' .
                                            $row['unit']
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- Maintenance Report -->

    <div class="col-12">

        <div
            class="card p-3 report-section"
            id="maintenanceReport"
        >

            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">

                <div>

                    <h5 class="mb-1">
                        Maintenance Report
                    </h5>

                    <p class="small text-muted mb-0">
                        Equipment with maintenance due during the selected period.
                    </p>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary no-print"
                    onclick="printReport(
                        'maintenanceReport',
                        'Maintenance Report',
                        true
                    )"
                >
                    <i class="bi bi-printer me-1"></i>

                    Print
                </button>

            </div>

            <div class="report-print-header">
                Maintenance Report
            </div>

            <div class="report-date-range">
                Period:
                <?= e($fromDate) ?>
                to
                <?= e($toDate) ?>
            </div>

            <div class="table-responsive">

                <table class="table table-bordered align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                Equipment
                            </th>

                            <th>
                                Serial Number
                            </th>

                            <th>
                                Laboratory
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Due Date
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($maintenanceRows)): ?>

                            <tr>

                                <td
                                    colspan="5"
                                    class="text-center text-muted py-4"
                                >
                                    No maintenance is due during this period.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($maintenanceRows as $row): ?>

                                <tr>

                                    <td>
                                        <?= e($row['name']) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['serial_number']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['lab_name']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e($row['status']) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['next_maintenance']
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- Reservation Status Report -->

    <div class="col-lg-5">

        <div
            class="card p-3 report-section h-100"
            id="reservationStatusReport"
        >

            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">

                <div>

                    <h5 class="mb-1">
                        Reservation Status
                    </h5>

                    <p class="small text-muted mb-0">
                        Summary by reservation status.
                    </p>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary no-print"
                    onclick="printReport(
                        'reservationStatusReport',
                        'Reservation Status Report',
                        true
                    )"
                >
                    <i class="bi bi-printer me-1"></i>

                    Print
                </button>

            </div>

            <div class="report-print-header">
                Reservation Status Report
            </div>

            <div class="report-date-range">
                Period:
                <?= e($fromDate) ?>
                to
                <?= e($toDate) ?>
            </div>

            <div class="table-responsive">

                <table class="table table-bordered align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                Status
                            </th>

                            <th>
                                Total
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($reservationStatuses)): ?>

                            <tr>

                                <td
                                    colspan="2"
                                    class="text-center text-muted py-4"
                                >
                                    No reservations found.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($reservationStatuses as $row): ?>

                                <tr>

                                    <td>
                                        <?= e($row['status']) ?>
                                    </td>

                                    <td>
                                        <?= e($row['total']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- Detailed Reservations Report -->

    <div class="col-12">

        <div
            class="card p-3 report-section"
            id="reservationsReport"
        >

            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">

                <div>

                    <h5 class="mb-1">
                        Detailed Reservations
                    </h5>

                    <p class="small text-muted mb-0">
                        All reservation requests during the selected period.
                    </p>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary no-print"
                    onclick="printReport(
                        'reservationsReport',
                        'Detailed Reservations Report',
                        true
                    )"
                >
                    <i class="bi bi-printer me-1"></i>

                    Print
                </button>

            </div>

            <div class="report-print-header">
                Detailed Reservations Report
            </div>

            <div class="report-date-range">
                Period:
                <?= e($fromDate) ?>
                to
                <?= e($toDate) ?>
            </div>

            <div class="table-responsive">

                <table class="table table-bordered table-sm align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                ID
                            </th>

                            <th>
                                User
                            </th>

                            <th>
                                Type
                            </th>

                            <th>
                                Item
                            </th>

                            <th>
                                Quantity
                            </th>

                            <th>
                                Date
                            </th>

                            <th>
                                Time
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Purpose
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($reservations)): ?>

                            <tr>

                                <td
                                    colspan="9"
                                    class="text-center text-muted py-4"
                                >
                                    No reservations found during this period.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($reservations as $row): ?>

                                <tr>

                                    <td>
                                        #<?= e($row['id']) ?>
                                    </td>

                                    <td>

                                        <?= e($row['user_name']) ?>

                                        <div class="small text-muted">
                                            <?= e($row['user_email']) ?>
                                        </div>

                                    </td>

                                    <td>
                                        <?= e($row['type']) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['item_name']
                                            ?: 'Unknown item'
                                        ) ?>
                                    </td>

                                    <td>

                                        <?php if ($row['type'] === 'Material'): ?>

                                            <?= e(
                                                $row['quantity'] .
                                                ' ' .
                                                $row['unit']
                                            ) ?>

                                        <?php else: ?>

                                            1

                                        <?php endif; ?>

                                    </td>

                                    <td>
                                        <?= e($row['date_needed']) ?>
                                    </td>

                                    <td>
                                        <?= e($row['time_slot']) ?>
                                    </td>

                                    <td>
                                        <?= e($row['status']) ?>
                                    </td>

                                    <td>
                                        <?= e($row['purpose']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <!-- Users Report -->

    <div class="col-12">

        <div
            class="card p-3 report-section"
            id="usersReport"
        >

            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">

                <div>

                    <h5 class="mb-1">
                        Users Report
                    </h5>

                    <p class="small text-muted mb-0">
                        Users registered during the selected period.
                    </p>

                </div>

                <button
                    type="button"
                    class="btn btn-outline-secondary no-print"
                    onclick="printReport(
                        'usersReport',
                        'Users Report',
                        true
                    )"
                >
                    <i class="bi bi-printer me-1"></i>

                    Print
                </button>

            </div>

            <div class="report-print-header">
                Users Report
            </div>

            <div class="report-date-range">
                Period:
                <?= e($fromDate) ?>
                to
                <?= e($toDate) ?>
            </div>

            <div class="table-responsive">

                <table class="table table-bordered table-sm align-middle mb-0">

                    <thead>

                        <tr>

                            <th>
                                Name
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Contact
                            </th>

                            <th>
                                Role
                            </th>

                            <th>
                                College
                            </th>

                            <th>
                                Department
                            </th>

                            <th>
                                Specialization
                            </th>

                            <th>
                                Research Title
                            </th>

                            <th>
                                Active
                            </th>

                            <th>
                                Registration Date
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php if (empty($users)): ?>

                            <tr>

                                <td
                                    colspan="10"
                                    class="text-center text-muted py-4"
                                >
                                    No users registered during this period.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach ($users as $row): ?>

                                <tr>

                                    <td>
                                        <?= e($row['name']) ?>
                                    </td>

                                    <td>
                                        <?= e($row['email']) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['contact_number']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e($row['role']) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['college']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['department']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['specialization']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $row['research_title']
                                            ?: '-'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= $row['active']
                                            ? 'Yes'
                                            : 'No'
                                        ?>
                                    </td>

                                    <td>
                                        <?= e($row['created_at']) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>

<style>

    /*
    |--------------------------------------------------------------------------
    | Hidden Elements on Screen
    |--------------------------------------------------------------------------
    */

    .report-print-header,
    .report-date-range {
        display: none;
    }

    /*
    |--------------------------------------------------------------------------
    | General Print Style
    |--------------------------------------------------------------------------
    */

    @media print {

        .no-print,
        header,
        nav,
        aside,
        .sidebar {
            display: none !important;
        }

        body {
            background: #ffffff !important;
        }

        .card {
            box-shadow: none !important;
            border: 0 !important;
        }

        table {
            width: 100% !important;
            border-collapse: collapse !important;
        }

        th,
        td {
            border: 1px solid #333333 !important;
            padding: 7px !important;
            color: #000000 !important;
        }

        .report-print-header,
        .report-date-range {
            display: block;
        }

    }

</style>

<script>

    /*
    |--------------------------------------------------------------------------
    | Print One Report Only
    |--------------------------------------------------------------------------
    */

    function printReport(
        reportId,
        reportTitle,
        includeDateRange
    ) {

        const report =
            document.getElementById(reportId);

        if (!report) {
            return;
        }

        const printWindow =
            window.open(
                '',
                '_blank',
                'width=1200,height=800'
            );

        if (!printWindow) {

            alert(
                'Please allow pop-ups to print the report.'
            );

            return;
        }

        const fromDate =
            <?= json_encode($fromDate) ?>;

        const toDate =
            <?= json_encode($toDate) ?>;

        const printedAt =
            new Date().toLocaleString();

        const dateRangeHtml =
            includeDateRange
                ? `
                    <div class="report-information">
                        <strong>Period:</strong>
                        ${fromDate} to ${toDate}
                    </div>
                `
                : `
                    <div class="report-information">
                        <strong>Report type:</strong>
                        Current inventory status
                    </div>
                `;

        const reportCopy =
            report.cloneNode(true);

        reportCopy
            .querySelectorAll('.no-print')
            .forEach(function (element) {
                element.remove();
            });

        reportCopy
            .querySelectorAll('.report-print-header')
            .forEach(function (element) {
                element.remove();
            });

        reportCopy
            .querySelectorAll('.report-date-range')
            .forEach(function (element) {
                element.remove();
            });

        printWindow.document.write(`
            <!DOCTYPE html>

            <html lang="en">

            <head>

                <meta charset="UTF-8">

                <title>${reportTitle}</title>

                <style>

                    @page {
                        size: landscape;
                        margin: 12mm;
                    }

                    * {
                        box-sizing: border-box;
                    }

                    body {
                        margin: 0;
                        color: #111111;
                        font-family:
                            Arial,
                            Helvetica,
                            sans-serif;
                        font-size: 12px;
                    }

                    .print-header {
                        margin-bottom: 22px;
                        padding-bottom: 14px;
                        border-bottom: 2px solid #0A8A4B;
                        display:flex;
                        justify-content:space-between;
                        align-items:flex-start;
                    }

                    .system-header {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 14px;
                        margin-bottom: 12px;
                    }

                    .system-logo {
                        width: 68px;
                        height: 68px;
                        object-fit: contain;
                    }

                    .system-brand {
                        text-align: left;
                    }

                    .print-header h1 {
                        margin: 10px 0 8px;
                        font-size: 24px;
                    }

                    .system-name {
                        margin-bottom: 3px;
                        color: #0A8A4B;
                        font-size: 22px;
                        font-weight: bold;
                    }

                    .system-subtitle {
                        color: #444444;
                        font-size: 11px;
                        line-height: 1.35;
                    }

                    .report-information {
                        margin-top: 5px;
                    }

                    .printed-date {
                        margin-top: 5px;
                        color: #555555;
                    }

                    .card {
                        border: 0;
                    }

                    h5 {
                        display: none;
                    }

                    p {
                        display: none;
                    }

                    .table-responsive {
                        overflow: visible;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                    }

                    th,
                    td {
                        border: 1px solid #444444;
                        padding: 7px;
                        text-align: left;
                        vertical-align: top;
                        word-break: break-word;
                    }

                    th {
                        background: #edf3fb;
                        font-weight: bold;
                    }

                    tr {
                        page-break-inside: avoid;
                    }

                    .text-muted {
                        color: #555555;
                    }

                    .small {
                        font-size: 10px;
                    }

                    
                    .system-header{display:flex;align-items:center;gap:12px;}
                    .logo{width:70px;height:70px;object-fit:contain;}
                    .system-subtitle{font-size:11px;color:#666;}
                    .report-title{text-align:right;}

                    .print-footer {
                        margin-top: 18px;
                        padding-top: 8px;
                        border-top: 1px solid #cccccc;
                        color: #666666;
                        font-size: 10px;
                        display:flex;
                        justify-content:space-between;
                        align-items:flex-start;
                    }

                </style>

            </head>

            <body>

                <div class="print-header">

                    <div class="system-header">
                        <img
                            src="<?= BASE_URL ?>/assets/images/kau-logo.png"
                            alt="LabSphere Logo"
                            class="system-logo"
                        >

                        <div class="system-brand">
                            <div class="system-name">LabSphere</div>
                            <div class="system-subtitle">Laboratory Management System</div>
                            <div class="system-subtitle">Joint Supervision Program • King Abdulaziz University</div>
                        </div>
                    </div>

                    <h1>
                        ${reportTitle}
                    </h1>

                    ${dateRangeHtml}

                    <div class="printed-date">
                        <strong>Printed:</strong>
                        ${printedAt}
                    </div>

                </div>

                ${reportCopy.innerHTML}

                <div class="print-footer">
                    Generated by LabSphere Laboratory Management System
                </div>

            </body>

            </html>
        `);

        printWindow.document.close();

        printWindow.focus();

        setTimeout(
            function () {
                printWindow.print();
                printWindow.close();
            },
            300
        );
    }

</script>

<?php require '../../includes/footer.php'; ?>