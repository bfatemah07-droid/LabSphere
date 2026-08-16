<?php

require 'config/database.php';
require 'includes/auth.php';

require_login();

$currentUser = user();
$currentRole = (string) ($currentUser['role'] ?? '');
$currentUserId = (int) ($currentUser['id'] ?? 0);

$isRegularUser = in_array(
    $currentRole,
    ['User', 'Student'],
    true
);

function dashboard_count(
    PDO $pdo,
    string $sql,
    array $params = []
): int {
    try {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    } catch (PDOException $exception) {
        return 0;
    }
}

function dashboard_table_exists(
    PDO $pdo,
    string $table
): bool {
    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?'
        );

        $statement->execute([$table]);

        return (bool) $statement->fetchColumn();
    } catch (PDOException $exception) {
        return false;
    }
}


if ($isRegularUser) {

$userName = trim((string) ($currentUser['name'] ?? 'User'));
$firstName = preg_split('/\s+/', $userName)[0] ?? $userName;


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$availableEquipment = dashboard_count(
    $pdo,
    "SELECT COUNT(*)
     FROM equipment
     WHERE status = 'Available'"
);

$materialsInStock = dashboard_count(
    $pdo,
    'SELECT COUNT(*)
     FROM materials
     WHERE quantity > 0'
);

$pendingRequests = dashboard_count(
    $pdo,
    "SELECT COUNT(*)
     FROM reservations
     WHERE user_id = ?
       AND status = 'Pending'",
    [$currentUserId]
);

$rejectedRequests = dashboard_count(
    $pdo,
    "SELECT COUNT(*)
     FROM reservations
     WHERE user_id = ?
       AND status = 'Rejected'",
    [$currentUserId]
);

/*
|--------------------------------------------------------------------------
| Recent reservations and notifications
|--------------------------------------------------------------------------
*/

$reservationSql = "
    SELECT
        r.id,
        r.type,
        r.status,
        r.quantity,
        r.unit,
        r.date_needed,
        r.purpose,
        r.created_at,
        CASE
            WHEN r.type = 'Equipment' THEN e.name
            WHEN r.type = 'Material' THEN m.name
            WHEN r.type = 'Supply' THEN s.name
            WHEN r.type = 'Laboratory' THEN l.name
            ELSE NULL
        END AS item_name
    FROM reservations r
    LEFT JOIN equipment e
        ON r.type = 'Equipment'
       AND e.id = r.item_id
    LEFT JOIN materials m
        ON r.type = 'Material'
       AND m.id = r.item_id
    LEFT JOIN supplies s
        ON r.type = 'Supply'
       AND s.id = r.item_id
    LEFT JOIN laboratories l
        ON r.type = 'Laboratory'
       AND l.id = r.laboratory_id
    WHERE r.user_id = ?
    ORDER BY r.id DESC
    LIMIT 3
";

try {
    $statement = $pdo->prepare($reservationSql);
    $statement->execute([$currentUserId]);

    $recentReservations = $statement->fetchAll();
} catch (PDOException $exception) {
    $recentReservations = [];
}

$notifications = [];

foreach ($recentReservations as $reservation) {

    $status = (string) ($reservation['status'] ?? '');
    $type = strtolower((string) ($reservation['type'] ?? 'reservation'));
    $itemName = $reservation['item_name'] ?: 'Item';

    if ($status === 'Approved') {
        $notifications[] = [
            'icon' => 'bi-check-lg',
            'class' => 'notification-approved',
            'text' => 'Your ' . $type . ' reservation for ' .
                $itemName . ' was approved.',
            'meta' => 'Approved'
        ];
    } elseif ($status === 'Rejected') {
        $notifications[] = [
            'icon' => 'bi-x-lg',
            'class' => 'notification-rejected',
            'text' => 'Your ' . $type . ' request for ' .
                $itemName . ' was rejected.',
            'meta' => 'Rejected'
        ];
    } elseif ($status === 'Pending') {
        $notifications[] = [
            'icon' => 'bi-hourglass-split',
            'class' => 'notification-pending',
            'text' => 'Your reservation for ' .
                $itemName . ' is pending supervisor approval.',
            'meta' => 'Pending approval'
        ];
    } elseif ($status === 'In Use') {
        $notifications[] = [
            'icon' => 'bi-play-circle',
            'class' => 'notification-active',
            'text' => 'Your reservation for ' .
                $itemName . ' is currently in use.',
            'meta' => 'In use'
        ];
    } elseif ($status === 'Completed') {
        $notifications[] = [
            'icon' => 'bi-check2-circle',
            'class' => 'notification-completed',
            'text' => 'Your reservation for ' .
                $itemName . ' has been completed.',
            'meta' => 'Completed'
        ];
    }
}

$page_title = 'Dashboard';

require 'includes/header.php';

?>

<style>
    .user-dashboard {
        --dashboard-border: #dde5ef;
        --dashboard-text: #071b3c;
        --dashboard-muted: #62738e;
    }

    .user-dashboard-heading h2 {
        margin-bottom: 0.2rem;
        color: var(--dashboard-text);
        font-weight: 800;
    }

    .user-dashboard-heading p {
        margin-bottom: 0;
        color: var(--dashboard-muted);
    }

    .user-stat-card,
    .user-dashboard-panel,
    .quick-access-card {
        border: 1px solid var(--dashboard-border);
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 5px 17px rgba(15, 35, 65, 0.045);
    }

    .user-stat-card {
        min-height: 160px;
        padding: 1.25rem;
        transition:
            transform 0.18s ease,
            box-shadow 0.18s ease;
    }

    .user-stat-card:hover,
    .quick-access-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 24px rgba(15, 35, 65, 0.08);
    }

    .user-stat-icon {
        width: 44px;
        height: 44px;
        margin-bottom: 1.1rem;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .stat-blue {
        color: #0875b6;
        background: #e5f4fd;
    }

    .stat-green {
        color: #079455;
        background: #e6f7ef;
    }

    .stat-amber {
        color: #b56c00;
        background: #fff3dc;
    }

    .stat-red {
        color: #d92d20;
        background: #feecec;
    }

    .user-stat-value {
        color: var(--dashboard-text);
        font-size: 1.75rem;
        font-weight: 800;
        line-height: 1;
    }

    .user-stat-label {
        margin-top: 0.55rem;
        color: var(--dashboard-muted);
        font-size: 0.9rem;
    }

    .user-dashboard-panel {
        height: 100%;
        overflow: hidden;
    }

    .user-panel-header {
        min-height: 62px;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--dashboard-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }

    .user-panel-header h5 {
        margin: 0;
        color: var(--dashboard-text);
        font-weight: 800;
    }

    .quick-access-grid {
        padding: 1.25rem;
    }

    .quick-access-card {
        min-height: 195px;
        padding: 1.5rem 1rem;
        color: inherit;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        transition:
            transform 0.18s ease,
            box-shadow 0.18s ease;
    }

    .quick-access-icon {
        width: 68px;
        height: 68px;
        margin-bottom: 1rem;
        border-radius: 18px;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
    }

    .quick-equipment {
        background: linear-gradient(135deg, #2563eb, #0ea5e9);
    }

    .quick-materials {
        background: linear-gradient(135deg, #059669, #18b7c9);
    }

    .quick-supplies {
        background: linear-gradient(135deg, #7c3aed, #3b5bdb);
    }

    .quick-storage {
        background: linear-gradient(135deg, #0ea5e9, #2563eb);
    }

    .quick-access-title {
        color: var(--dashboard-text);
        font-size: 1.05rem;
        font-weight: 800;
    }

    .quick-access-description {
        margin-top: 0.35rem;
        color: var(--dashboard-muted);
        font-size: 0.88rem;
        text-align: center;
    }

    .notification-row {
        min-height: 88px;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid var(--dashboard-border);
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
    }

    .notification-row:last-child {
        border-bottom: 0;
    }

    .notification-icon {
        width: 42px;
        height: 42px;
        flex: 0 0 42px;
        border-radius: 11px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .notification-approved,
    .notification-completed {
        color: #079455;
        background: #e8f7ef;
    }

    .notification-rejected {
        color: #d92d20;
        background: #feecec;
    }

    .notification-pending {
        color: #b56c00;
        background: #fff3dc;
    }

    .notification-active {
        color: #0d6efd;
        background: #eaf2ff;
    }

    .notification-message {
        color: var(--dashboard-text);
        line-height: 1.35;
    }

    .notification-meta {
        margin-top: 0.2rem;
        color: #8190a5;
        font-size: 0.78rem;
    }

    .user-empty-state {
        padding: 3.5rem 1.25rem;
        color: var(--dashboard-muted);
        text-align: center;
    }

    .recent-reservations-section {
        margin-top: 2rem;
    }

    .recent-reservations-title {
        margin-bottom: 1rem;
        color: var(--dashboard-text);
        font-weight: 800;
    }

    .recent-reservations-card {
        border: 1px solid var(--dashboard-border);
        border-radius: 18px;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 5px 17px rgba(15, 35, 65, 0.045);
    }

    .recent-reservations-table {
        min-width: 980px;
        margin-bottom: 0;
    }

    .recent-reservations-table thead th {
        padding: 0.9rem 1rem;
        color: #62738e;
        background: #ffffff;
        border-bottom: 1px solid var(--dashboard-border);
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .recent-reservations-table tbody td {
        padding: 0.95rem 1rem;
        color: var(--dashboard-text);
        border-color: var(--dashboard-border);
        vertical-align: middle;
    }

    .reservation-request-id,
    .reservation-item-name {
        font-weight: 700;
    }

    .reservation-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .reservation-status::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: currentColor;
    }

    .reservation-pending {
        color: #b56c00;
        background: #fff3dc;
    }

    .reservation-approved {
        color: #079455;
        background: #e8f7ef;
    }

    .reservation-rejected {
        color: #d92d20;
        background: #feecec;
    }

    .reservation-active {
        color: #0d6efd;
        background: #eaf2ff;
    }

    .reservation-completed {
        color: #6f42c1;
        background: #f2ecff;
    }

    .log-sheet-button {
        border-radius: 9px;
        font-weight: 600;
        white-space: nowrap;
    }

    .no-reservations-row td {
        padding: 3rem 1rem !important;
        color: var(--dashboard-muted) !important;
        text-align: center;
    }

    @media (max-width: 575.98px) {
        .user-stat-card {
            min-height: 140px;
        }

        .quick-access-card {
            min-height: 165px;
        }
    }
</style>

<div class="user-dashboard">

    <div class="user-dashboard-heading mb-4">

        <h2>
            Welcome back, <?= e($firstName) ?>
        </h2>

        <p>
            Here's what's happening with your lab reservations today.
        </p>

    </div>

    <div class="row g-3 mb-4">

        <div class="col-12 col-sm-6 col-xl-3">

            <a
                href="modules/equipment/index.php"
                class="text-decoration-none"
            >

                <div class="user-stat-card h-100">

                    <div class="user-stat-icon stat-blue">
                        <i class="bi bi-cpu"></i>
                    </div>

                    <div class="user-stat-value">
                        <?= (int) $availableEquipment ?>
                    </div>

                    <div class="user-stat-label">
                        Equipment Available
                    </div>

                </div>

            </a>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <a
                href="modules/materials/index.php"
                class="text-decoration-none"
            >

                <div class="user-stat-card h-100">

                    <div class="user-stat-icon stat-green">
                        <i class="bi bi-droplet"></i>
                    </div>

                    <div class="user-stat-value">
                        <?= (int) $materialsInStock ?>
                    </div>

                    <div class="user-stat-label">
                        Materials In Stock
                    </div>

                </div>

            </a>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <a
                href="modules/reservations/index.php?status=Pending"
                class="text-decoration-none"
            >

                <div class="user-stat-card h-100">

                    <div class="user-stat-icon stat-amber">
                        <i class="bi bi-hourglass-split"></i>
                    </div>

                    <div class="user-stat-value">
                        <?= (int) $pendingRequests ?>
                    </div>

                    <div class="user-stat-label">
                        Pending Requests
                    </div>

                </div>

            </a>

        </div>

        <div class="col-12 col-sm-6 col-xl-3">

            <a
                href="modules/reservations/index.php?status=Rejected"
                class="text-decoration-none"
            >

                <div class="user-stat-card h-100">

                    <div class="user-stat-icon stat-red">
                        <i class="bi bi-x-lg"></i>
                    </div>

                    <div class="user-stat-value">
                        <?= (int) $rejectedRequests ?>
                    </div>

                    <div class="user-stat-label">
                        Rejected Requests
                    </div>

                </div>

            </a>

        </div>

    </div>

    <div class="row g-4">

        <div class="col-xl-8">

            <div class="user-dashboard-panel">

                <div class="user-panel-header">

                    <h5>Quick access</h5>

                </div>

                <div class="quick-access-grid">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <a
                                href="modules/equipment/index.php"
                                class="quick-access-card"
                            >

                                <div class="quick-access-icon quick-equipment">
                                    <i class="bi bi-cpu"></i>
                                </div>

                                <div class="quick-access-title">
                                    Equipment
                                </div>

                                <div class="quick-access-description">
                                    Browse and reserve lab instruments
                                </div>

                            </a>

                        </div>

                        <div class="col-md-6">

                            <a
                                href="modules/materials/index.php"
                                class="quick-access-card"
                            >

                                <div class="quick-access-icon quick-materials">
                                    <i class="bi bi-droplet"></i>
                                </div>

                                <div class="quick-access-title">
                                    Materials
                                </div>

                                <div class="quick-access-description">
                                    Request gases, liquids and solids
                                </div>

                            </a>

                        </div>

                        <div class="col-md-6">

                            <a
                                href="modules/supplies/index.php"
                                class="quick-access-card"
                            >

                                <div class="quick-access-icon quick-supplies">
                                    <i class="bi bi-box-seam"></i>
                                </div>

                                <div class="quick-access-title">
                                    Supplies
                                </div>

                                <div class="quick-access-description">
                                    Browse countable laboratory supplies
                                </div>

                            </a>

                        </div>

                        <div class="col-md-6">

                            <a
                                href="modules/labs/index.php"
                                class="quick-access-card"
                            >

                                <div class="quick-access-icon quick-storage">
                                    <i class="bi bi-archive"></i>
                                </div>

                                <div class="quick-access-title">
                                    Laboratories
                                </div>

                                <div class="quick-access-description">
                                    View available laboratories and spaces
                                </div>

                            </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-4">

            <div class="user-dashboard-panel">

                <div class="user-panel-header">

                    <h5>Recent notifications</h5>

                    <a
                        href="modules/reservations/index.php"
                        class="text-decoration-none"
                    >
                        View all
                    </a>

                </div>

                <?php if (empty($notifications)): ?>

                    <div class="user-empty-state">

                        <i class="bi bi-bell fs-2 d-block mb-2"></i>

                        No recent reservation notifications.

                    </div>

                <?php else: ?>

                    <?php foreach (
                        array_slice($notifications, 0, 5)
                        as $notification
                    ): ?>

                        <div class="notification-row">

                            <div class="notification-icon <?= e($notification['class']) ?>">
                                <i class="bi <?= e($notification['icon']) ?>"></i>
                            </div>

                            <div>

                                <div class="notification-message">
                                    <?= e($notification['text']) ?>
                                </div>

                                <div class="notification-meta">
                                    <?= e($notification['meta']) ?>
                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </div>

    </div>

    <section class="recent-reservations-section">

        <h5 class="recent-reservations-title">
            My recent reservations
        </h5>

        <div class="recent-reservations-card">

            <div class="table-responsive">

                <table class="table recent-reservations-table align-middle">

                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Item</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Date needed</th>
                            <th>Time slot</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (empty($recentReservations)): ?>

                            <tr class="no-reservations-row">
                                <td colspan="8">
                                    <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>
                                    لا يوجد حجوزات سابقة
                                </td>
                            </tr>

                        <?php else: ?>

                            <?php foreach ($recentReservations as $reservation): ?>

                                <?php
                                $reservationStatus = (string) (
                                    $reservation['status'] ?? 'Pending'
                                );

                                $reservationStatusClass = match (
                                    $reservationStatus
                                ) {
                                    'Approved' => 'reservation-approved',
                                    'Rejected' => 'reservation-rejected',
                                    'In Use' => 'reservation-active',
                                    'Completed' => 'reservation-completed',
                                    default => 'reservation-pending'
                                };

                                $reservationQuantity = rtrim(
                                    rtrim(
                                        number_format(
                                            (float) (
                                                $reservation['quantity'] ?? 0
                                            ),
                                            2,
                                            '.',
                                            ''
                                        ),
                                        '0'
                                    ),
                                    '.'
                                );

                                $showLogSheet = in_array(
                                    $reservationStatus,
                                    ['Approved', 'In Use', 'Completed'],
                                    true
                                );
                                ?>

                                <tr>

                                    <td class="reservation-request-id">
                                        RQ-<?= e(
                                            str_pad(
                                                (string) $reservation['id'],
                                                4,
                                                '0',
                                                STR_PAD_LEFT
                                            )
                                        ) ?>
                                    </td>

                                    <td class="reservation-item-name">
                                        <?= e(
                                            $reservation['item_name']
                                                ?: 'Deleted item'
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= e($reservation['type']) ?>
                                    </td>

                                    <td>
                                        <?= e($reservationQuantity) ?>
                                        <?= e($reservation['unit'] ?? '') ?>
                                    </td>

                                    <td>
                                        <?= e(
                                            $reservation['date_needed']
                                                ?: '—'
                                        ) ?>
                                    </td>

                                    <td class="text-muted">
                                        —
                                    </td>

                                    <td>
                                        <span class="reservation-status <?= e($reservationStatusClass) ?>">
                                            <?= e($reservationStatus) ?>
                                        </span>
                                    </td>

                                    <td class="text-end">

                                        <?php if ($showLogSheet): ?>

                                            <a
                                                href="modules/reservations/log_sheet.php?id=<?= (int) $reservation['id'] ?>"
                                                class="btn btn-sm btn-outline-primary log-sheet-button"
                                            >
                                                <i class="bi bi-file-earmark-text me-1"></i>
                                                Log Sheet
                                            </a>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </section>

</div>

<?php

} else {

$userName = trim((string)($currentUser['name'] ?? 'User'));



$pendingMaterial = dashboard_count(
    $pdo,
    "SELECT COUNT(*) FROM reservations WHERE type='Material' AND status='Pending'"
);
$pendingEquipment = dashboard_count(
    $pdo,
    "SELECT COUNT(*) FROM reservations WHERE type='Equipment' AND status='Pending'"
);
$pendingSupply = dashboard_count(
    $pdo,
    "SELECT COUNT(*) FROM reservations WHERE type='Supply' AND status='Pending'"
);
$approvedReservations = dashboard_count(
    $pdo,
    "SELECT COUNT(*) FROM reservations WHERE status='Approved'"
);
$rejectedReservations = dashboard_count(
    $pdo,
    "SELECT COUNT(*) FROM reservations WHERE status='Rejected'"
);
$lowStockSupplies = dashboard_count(
    $pdo,
    'SELECT COUNT(*) FROM supplies WHERE quantity <= low_stock_threshold'
);

$maintenanceNeedingAction = 0;
if (dashboard_table_exists($pdo, 'maintenance')) {
    $maintenanceNeedingAction = dashboard_count(
        $pdo,
        "SELECT COUNT(*) FROM maintenance WHERE status NOT IN ('Completed','Closed')"
    );
}

$waitingSql = "
    SELECT
        r.id,
        r.type,
        r.quantity,
        r.unit,
        r.date_needed,
        r.status,
        r.purpose,
        u.name AS user_name,
        u.email AS user_email,
        CASE
            WHEN r.type = 'Equipment' THEN e.name
            WHEN r.type = 'Material' THEN m.name
            WHEN r.type = 'Supply' THEN s.name
            WHEN r.type = 'Laboratory' THEN l.name
            ELSE NULL
        END AS item_name
    FROM reservations r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN equipment e ON r.type = 'Equipment' AND e.id = r.item_id
    LEFT JOIN materials m ON r.type = 'Material' AND m.id = r.item_id
    LEFT JOIN supplies s ON r.type = 'Supply' AND s.id = r.item_id
    LEFT JOIN laboratories l ON r.type = 'Laboratory' AND l.id = r.laboratory_id
    WHERE r.status = 'Pending'
    ORDER BY r.id DESC
    LIMIT 6
";

try {
    $waitingRows = $pdo->query($waitingSql)->fetchAll();
} catch (PDOException $exception) {
    $waitingRows = [];
}

$notifications = [];

foreach (array_slice($waitingRows, 0, 4) as $row) {
    $notifications[] = [
        'icon' => 'bi-inbox',
        'class' => 'notification-blue',
        'text' => 'New ' . strtolower((string)$row['type']) . ' request from ' .
            ($row['user_name'] ?? 'User') . ' — ' . ($row['item_name'] ?? 'Item') . '.',
        'meta' => 'Needs review'
    ];
}

try {
    $lowStockRows = $pdo->query(
        'SELECT name, quantity, unit
         FROM supplies
         WHERE quantity <= low_stock_threshold
         ORDER BY quantity ASC
         LIMIT 3'
    )->fetchAll();

    foreach ($lowStockRows as $supply) {
        $notifications[] = [
            'icon' => 'bi-exclamation-triangle',
            'class' => 'notification-warning',
            'text' => ($supply['name'] ?? 'Supply') . ' is low on stock (' .
                rtrim(rtrim(number_format((float)($supply['quantity'] ?? 0), 2, '.', ''), '0'), '.') .
                ' ' . ($supply['unit'] ?? '') . ' remaining).',
            'meta' => 'Stock alert'
        ];
    }
} catch (PDOException $exception) {
    // Keep dashboard working even if the stock query is unavailable.
}

$page_title = 'Dashboard';
require 'includes/header.php';
?>

<style>
.dashboard-hero h2{font-weight:800;color:#172033}.dashboard-hero p{color:#64748b}
.dashboard-stat-card{border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 5px 18px rgba(15,23,42,.04);transition:.18s ease}
.dashboard-stat-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(15,23,42,.08)}
.dashboard-stat-icon{width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;margin-bottom:18px}
.icon-amber{background:#fff4df;color:#b86a00}.icon-blue{background:#eaf2ff;color:#0d6efd}.icon-green{background:#e8f7ef;color:#079455}.icon-red{background:#feecec;color:#d92d20}.icon-purple{background:#f2ecff;color:#6f42c1}
.dashboard-stat-value{font-size:1.85rem;line-height:1;font-weight:800;color:#111827}.dashboard-stat-label{color:#64748b;font-size:.9rem;margin-top:8px}
.dashboard-panel{border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;background:#fff;box-shadow:0 5px 18px rgba(15,23,42,.04)}
.dashboard-panel-header{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid #e8edf3}.dashboard-panel-title{font-weight:800;color:#172033;margin:0}
.dashboard-table{margin:0}.dashboard-table thead th{background:#f8fafc;color:#64748b;text-transform:uppercase;font-size:.72rem;letter-spacing:.04em;border-bottom:1px solid #e2e8f0;padding:13px 16px;white-space:nowrap}.dashboard-table tbody td{padding:15px 16px;vertical-align:middle;border-color:#edf1f5}
.dashboard-user-name{font-weight:700;color:#172033}.dashboard-user-email{color:#7a8799;font-size:.78rem}.dashboard-status{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#fff4dd;color:#b66a00;font-size:.78rem;font-weight:700}.dashboard-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}
.review-btn{border-radius:9px;font-weight:600}.notification-item{display:flex;gap:12px;padding:16px 20px;border-bottom:1px solid #edf1f5}.notification-item:last-child{border-bottom:0}.notification-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:0 0 40px}.notification-blue{background:#eaf2ff;color:#0d6efd}.notification-warning{background:#fff4dd;color:#b66a00}.notification-text{color:#172033;line-height:1.35}.notification-meta{font-size:.78rem;color:#8a96a6;margin-top:3px}.empty-dashboard{padding:36px 20px;text-align:center;color:#7b8798}
@media(max-width:1199.98px){.dashboard-table{min-width:860px}}
</style>

<div class="dashboard-hero mb-4">
    <h2 class="mb-1">Hey <?= e($userName) ?> <span aria-hidden="true">👋</span></h2>
    <p class="mb-0">Here is what needs your attention today.</p>
</div>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['Pending material requests', $pendingMaterial, 'bi-droplet', 'icon-amber'],
        ['Pending equipment requests', $pendingEquipment, 'bi-cpu', 'icon-amber'],
        ['Pending supply requests', $pendingSupply, 'bi-box-seam', 'icon-blue'],
        ['Approved reservations', $approvedReservations, 'bi-check-lg', 'icon-green'],
        ['Rejected reservations', $rejectedReservations, 'bi-x-lg', 'icon-red'],
        ['Low-stock supplies', $lowStockSupplies, 'bi-exclamation-triangle', 'icon-purple'],
    ];

    if ($maintenanceNeedingAction > 0) {
        $cards[5] = ['Maintenance needing action', $maintenanceNeedingAction, 'bi-tools', 'icon-purple'];
    }
    ?>

    <?php foreach ($cards as $card): ?>
        <div class="col-12 col-sm-6 col-xl-4 col-xxl-2">
            <div class="dashboard-stat-card p-3 h-100">
                <div class="dashboard-stat-icon <?= e($card[3]) ?>">
                    <i class="bi <?= e($card[2]) ?>"></i>
                </div>
                <div class="dashboard-stat-value"><?= (int)$card[1] ?></div>
                <div class="dashboard-stat-label"><?= e($card[0]) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <div class="dashboard-panel h-100">
            <div class="dashboard-panel-header">
                <h5 class="dashboard-panel-title">Waiting on you</h5>
                <a href="modules/reservations/index.php?status=Pending" class="text-decoration-none fw-semibold">View all</a>
            </div>

            <div class="table-responsive">
                <table class="table dashboard-table align-middle">
                    <thead>
                    <tr>
                        <th>User</th>
                        <th>Item</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>Needed</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($waitingRows)): ?>
                        <tr>
                            <td colspan="7" class="empty-dashboard">
                                <i class="bi bi-check2-circle fs-2 d-block mb-2"></i>
                                No pending requests right now.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($waitingRows as $row): ?>
                            <tr>
                                <td>
                                    <div class="dashboard-user-name"><?= e($row['user_name']) ?></div>
                                    <div class="dashboard-user-email"><?= e($row['user_email'] ?? '') ?></div>
                                </td>
                                <td><?= e($row['item_name'] ?? 'Deleted item') ?></td>
                                <td><?= e($row['type']) ?></td>
                                <td>
                                    <?= e(rtrim(rtrim(number_format((float)$row['quantity'], 2, '.', ''), '0'), '.')) ?>
                                    <?= e($row['unit'] ?? '') ?>
                                </td>
                                <td><?= e($row['date_needed']) ?></td>
                                <td><span class="dashboard-status"><?= e($row['status']) ?></span></td>
                                <td>
                                    <a href="modules/reservations/index.php?status=Pending" class="btn btn-sm btn-outline-primary review-btn">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="dashboard-panel h-100">
            <div class="dashboard-panel-header">
                <h5 class="dashboard-panel-title">Notifications</h5>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="empty-dashboard">
                    <i class="bi bi-bell fs-2 d-block mb-2"></i>
                    No New Notifications.
                </div>
            <?php else: ?>
                <?php foreach (array_slice($notifications, 0, 6) as $notification): ?>
                    <div class="notification-item">
                        <div class="notification-icon <?= e($notification['class']) ?>">
                            <i class="bi <?= e($notification['icon']) ?>"></i>
                        </div>
                        <div>
                            <div class="notification-text"><?= e($notification['text']) ?></div>
                            <div class="notification-meta"><?= e($notification['meta']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php

}

require 'includes/footer.php';
?>