<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

$timeSlots = [
    '08:00 - 09:00' => '8:00 AM - 9:00 AM',
    '09:00 - 10:00' => '9:00 AM - 10:00 AM',
    '10:00 - 11:00' => '10:00 AM - 11:00 AM',
    '11:00 - 12:00' => '11:00 AM - 12:00 PM',
    '12:00 - 13:00' => '12:00 PM - 1:00 PM',
    '13:00 - 14:00' => '1:00 PM - 2:00 PM'
];


function material_request_to_stock_unit(float $quantity, ?string $requestUnit, ?string $stockUnit): float
{
    $requestUnit = (string)$requestUnit;
    $stockUnit = (string)$stockUnit;

    if ($requestUnit === 'mL' && $stockUnit === 'L') {
        return $quantity / 1000;
    }

    if ($requestUnit === 'mg' && $stockUnit === 'g') {
        return $quantity / 1000;
    }

    if ($requestUnit === $stockUnit) {
        return $quantity;
    }

    return -1;
}

/* AJAX: return unavailable slots for equipment or laboratory. */
if (($_GET['ajax'] ?? '') === 'booked_slots') {
    header('Content-Type: application/json; charset=utf-8');

    $type = trim($_GET['type'] ?? '');
    $selectionId = (int) ($_GET['selection_id'] ?? 0);
    $date = trim($_GET['date'] ?? '');

    if (!in_array($type, ['Equipment', 'Laboratory'], true) || $selectionId <= 0 || $date === '') {
        echo json_encode(['success' => false, 'booked_slots' => []]);
        exit;
    }

    $booked = [];

    if ($type === 'Equipment') {
        $s = $pdo->prepare('SELECT lab_id FROM equipment WHERE id = ? LIMIT 1');
        $s->execute([$selectionId]);
        $equipmentRow = $s->fetch();

        if (!$equipmentRow) {
            echo json_encode(['success' => false, 'booked_slots' => []]);
            exit;
        }

        $s = $pdo->prepare("SELECT DISTINCT time_slot FROM reservations
                            WHERE type='Equipment' AND item_id=? AND date_needed=?
                            AND status IN ('Pending','Approved') AND time_slot IS NOT NULL");
        $s->execute([$selectionId, $date]);
        $booked = $s->fetchAll(PDO::FETCH_COLUMN);

        $labId = !empty($equipmentRow['lab_id']) ? (int) $equipmentRow['lab_id'] : 0;
        if ($labId > 0) {
            $s = $pdo->prepare("SELECT DISTINCT time_slot FROM reservations
                                WHERE type='Laboratory' AND laboratory_id=? AND date_needed=?
                                AND status IN ('Pending','Approved') AND time_slot IS NOT NULL");
            $s->execute([$labId, $date]);
            $booked = array_merge($booked, $s->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    if ($type === 'Laboratory') {
        $s = $pdo->prepare("SELECT DISTINCT time_slot FROM reservations
                            WHERE type='Laboratory' AND laboratory_id=? AND date_needed=?
                            AND status IN ('Pending','Approved') AND time_slot IS NOT NULL");
        $s->execute([$selectionId, $date]);
        $booked = $s->fetchAll(PDO::FETCH_COLUMN);

        $s = $pdo->prepare("SELECT DISTINCT r.time_slot
                            FROM reservations r
                            INNER JOIN equipment e ON e.id = r.item_id
                            WHERE r.type='Equipment' AND e.lab_id=? AND r.date_needed=?
                            AND r.status IN ('Pending','Approved') AND r.time_slot IS NOT NULL");
        $s->execute([$selectionId, $date]);
        $booked = array_merge($booked, $s->fetchAll(PDO::FETCH_COLUMN));
    }

    echo json_encode([
        'success' => true,
        'booked_slots' => array_values(array_unique(array_filter($booked)))
    ]);
    exit;
}

$selectedType = $_GET['type'] ?? $_POST['type'] ?? 'Equipment';
if (!in_array($selectedType, ['Equipment', 'Laboratory', 'Material', 'Supply'], true)) {
    $selectedType = 'Equipment';
}
$selectedSelectionId = (int) ($_GET['item_id'] ?? $_GET['selection_id'] ?? $_POST['selection_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $type = trim($_POST['type'] ?? '');
    $selectionId = (int) ($_POST['selection_id'] ?? 0);
    $date = trim($_POST['date_needed'] ?? '');
    $timeSlot = trim($_POST['time_slot'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $reserveEntireLab = ($_POST['reserve_entire_lab'] ?? '') === '1';
    $bookingItemsJson = trim($_POST['booking_items'] ?? '');

    $itemId = $type === 'Laboratory' ? null : $selectionId;
    $laboratoryId = $type === 'Laboratory' ? $selectionId : null;
    $bookingItems = [];

    if (in_array($type, ['Material', 'Supply'], true)) {
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $unit = $type === 'Material' ? trim($_POST['unit'] ?? '') : null;
        $timeSlot = null;
    } else {
        $quantity = 1;
        $unit = null;

        $decodedItems = json_decode($bookingItemsJson, true);
        if (is_array($decodedItems)) {
            foreach ($decodedItems as $bookingItem) {
                $bookingDate = trim((string) ($bookingItem['date'] ?? ''));
                $bookingSlot = trim((string) ($bookingItem['time_slot'] ?? ''));
                $key = $bookingDate . '|' . $bookingSlot;
                if ($bookingDate !== '' && $bookingSlot !== '') {
                    $bookingItems[$key] = [
                        'date' => $bookingDate,
                        'time_slot' => $bookingSlot,
                    ];
                }
            }
            $bookingItems = array_values($bookingItems);
        }

        // Backward-compatible fallback for a single date and time.
        if (empty($bookingItems) && $date !== '' && $timeSlot !== '') {
            $bookingItems[] = ['date' => $date, 'time_slot' => $timeSlot];
        }
    }

    $redirect = 'create.php?type=' . urlencode($type) . '&selection_id=' . $selectionId;

    if (!in_array($type, ['Equipment', 'Laboratory', 'Material', 'Supply'], true)) {
        flash('danger', 'Please select a valid reservation type.');
        header('Location: create.php'); exit;
    }
    if ($selectionId <= 0) {
        flash('danger', 'Please select an item or laboratory.');
        header('Location: create.php?type=' . urlencode($type)); exit;
    }
    if ($purpose === '') {
        flash('danger', 'Please enter the purpose of the reservation.');
        header('Location: ' . $redirect); exit;
    }
    if ($type === 'Laboratory' && !$reserveEntireLab) {
        flash('danger', 'Please confirm that you want to reserve the entire laboratory.');
        header('Location: ' . $redirect); exit;
    }

    if (in_array($type, ['Equipment', 'Laboratory'], true)) {
        if (empty($bookingItems)) {
            flash('danger', 'Please select at least one date and time slot.');
            header('Location: ' . $redirect); exit;
        }

        foreach ($bookingItems as $bookingItem) {
            $bookingDate = $bookingItem['date'];
            $bookingSlot = $bookingItem['time_slot'];
            $dateObject = DateTime::createFromFormat('Y-m-d', $bookingDate);

            if (!$dateObject || $dateObject->format('Y-m-d') !== $bookingDate || $bookingDate < date('Y-m-d')) {
                flash('danger', 'One of the selected reservation dates is invalid.');
                header('Location: ' . $redirect); exit;
            }
            if (!array_key_exists($bookingSlot, $timeSlots)) {
                flash('danger', 'One of the selected time slots is invalid.');
                header('Location: ' . $redirect); exit;
            }
        }
    } else {
        $dateObject = DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateObject || $dateObject->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
            flash('danger', 'Please select a valid required date.');
            header('Location: ' . $redirect); exit;
        }
    }

    if (in_array($type, ['Material', 'Supply'], true)) {
        if ($quantity <= 0) {
            flash('danger', 'Please enter a valid quantity.');
            header('Location: ' . $redirect); exit;
        }
        if ($type === 'Material' && !in_array($unit, ['mL', 'mg'], true)) {
            flash('danger', 'Please select a valid material unit.');
            header('Location: ' . $redirect); exit;
        }
    }

    $selectedRecord = null;
    if ($type === 'Equipment') {
        $s = $pdo->prepare("SELECT id,name,lab_id,hourly_price FROM equipment
                            WHERE id=? AND status='Available' LIMIT 1");
        $s->execute([$selectionId]);
        $selectedRecord = $s->fetch();
    } elseif ($type === 'Laboratory') {
        $s = $pdo->prepare('SELECT id,name,code,capacity FROM laboratories WHERE id=? LIMIT 1');
        $s->execute([$selectionId]);
        $selectedRecord = $s->fetch();
    } elseif ($type === 'Material') {
        $s = $pdo->prepare('SELECT id,name,available_quantity,unit FROM materials
                            WHERE id=? AND available_quantity>0 LIMIT 1');
        $s->execute([$selectionId]);
        $selectedRecord = $s->fetch();
    } else {
        $s = $pdo->prepare('SELECT id,name,quantity,unit FROM supplies
                            WHERE id=? AND quantity>0 LIMIT 1');
        $s->execute([$selectionId]);
        $selectedRecord = $s->fetch();
        if ($selectedRecord) {
            $unit = $selectedRecord['unit'];
        }
    }

    if (!$selectedRecord) {
        flash('danger', 'The selected item or laboratory is not available.');
        header('Location: create.php'); exit;
    }

    if ($type === 'Material') {
        $requestedInStockUnit = material_request_to_stock_unit(
            $quantity,
            $unit,
            $selectedRecord['unit'] ?? null
        );

        if ($requestedInStockUnit <= 0) {
            flash('danger', 'The selected unit does not match this material.');
            header('Location: ' . $redirect); exit;
        }

        if ($requestedInStockUnit > (float)$selectedRecord['available_quantity']) {
            flash(
                'danger',
                'The requested quantity is greater than the available material stock.'
            );
            header('Location: ' . $redirect); exit;
        }
    }
    if ($type === 'Supply' && $quantity > (float) $selectedRecord['quantity']) {
        flash('danger', 'The requested quantity is greater than the available supply stock.');
        header('Location: ' . $redirect); exit;
    }

    // Recheck every selected date and time on the server to prevent double booking.
    if ($type === 'Equipment') {
        $equipmentConflict = $pdo->prepare("SELECT id FROM reservations
            WHERE type='Equipment' AND item_id=? AND date_needed=? AND time_slot=?
            AND status IN ('Pending','Approved') LIMIT 1");
        $laboratoryConflict = $pdo->prepare("SELECT id FROM reservations
            WHERE type='Laboratory' AND laboratory_id=? AND date_needed=? AND time_slot=?
            AND status IN ('Pending','Approved') LIMIT 1");
        $equipmentLabId = !empty($selectedRecord['lab_id']) ? (int) $selectedRecord['lab_id'] : 0;

        foreach ($bookingItems as $bookingItem) {
            $equipmentConflict->execute([$selectionId, $bookingItem['date'], $bookingItem['time_slot']]);
            if ($equipmentConflict->fetch()) {
                flash('danger', $bookingItem['date'] . ' — ' . $timeSlots[$bookingItem['time_slot']] . ' is no longer available.');
                header('Location: ' . $redirect); exit;
            }

            if ($equipmentLabId > 0) {
                $laboratoryConflict->execute([$equipmentLabId, $bookingItem['date'], $bookingItem['time_slot']]);
                if ($laboratoryConflict->fetch()) {
                    flash('danger', $bookingItem['date'] . ' — ' . $timeSlots[$bookingItem['time_slot']] . ' is unavailable because the laboratory is reserved.');
                    header('Location: ' . $redirect); exit;
                }
            }
        }
    }

    if ($type === 'Laboratory') {
        $laboratoryConflict = $pdo->prepare("SELECT id FROM reservations
            WHERE type='Laboratory' AND laboratory_id=? AND date_needed=? AND time_slot=?
            AND status IN ('Pending','Approved') LIMIT 1");
        $equipmentConflict = $pdo->prepare("SELECT r.id FROM reservations r
            INNER JOIN equipment e ON e.id = r.item_id
            WHERE r.type='Equipment' AND e.lab_id=? AND r.date_needed=? AND r.time_slot=?
            AND r.status IN ('Pending','Approved') LIMIT 1");

        foreach ($bookingItems as $bookingItem) {
            $laboratoryConflict->execute([$selectionId, $bookingItem['date'], $bookingItem['time_slot']]);
            if ($laboratoryConflict->fetch()) {
                flash('danger', $bookingItem['date'] . ' — ' . $timeSlots[$bookingItem['time_slot']] . ' is no longer available.');
                header('Location: ' . $redirect); exit;
            }

            $equipmentConflict->execute([$selectionId, $bookingItem['date'], $bookingItem['time_slot']]);
            if ($equipmentConflict->fetch()) {
                flash('danger', $bookingItem['date'] . ' — ' . $timeSlots[$bookingItem['time_slot']] . ' cannot be reserved because equipment inside the laboratory is already booked.');
                header('Location: ' . $redirect); exit;
            }
        }
    }

    try {
        $pdo->beginTransaction();

        // One group code connects all dates/time slots submitted in this request.
        $reservationGroup = 'GRP-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $insertReservation = $pdo->prepare('INSERT INTO reservations
            (reservation_group,user_id,type,item_id,laboratory_id,quantity,unit,time_slot,date_needed,purpose)
            VALUES (?,?,?,?,?,?,?,?,?,?)');

        $createdIds = [];
        if (in_array($type, ['Equipment', 'Laboratory'], true)) {
            foreach ($bookingItems as $bookingItem) {
                $insertReservation->execute([
                    $reservationGroup, user()['id'], $type, $itemId, $laboratoryId, 1,
                    null, $bookingItem['time_slot'], $bookingItem['date'], $purpose
                ]);
                $createdIds[] = (int) $pdo->lastInsertId();
            }
        } else {
            $insertReservation->execute([
                $reservationGroup, user()['id'], $type, $itemId, $laboratoryId, $quantity,
                $unit, null, $date, $purpose
            ]);
            $createdIds[] = (int) $pdo->lastInsertId();
        }

        $firstReservationId = $createdIds[0];
        $requestCode = 'RQ-' . str_pad((string) $firstReservationId, 4, '0', STR_PAD_LEFT);
        $requesterName = trim((string) (user()['name'] ?? 'A user'));
        $itemName = trim((string) ($selectedRecord['name'] ?? $type));
        $reservationCount = count($createdIds);

        $recipientStatement = $pdo->query(
            "SELECT id FROM users WHERE role IN ('Admin', 'Supervisor')"
        );
        $recipientIds = $recipientStatement->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($recipientIds)) {
            $notificationStatement = $pdo->prepare(
                'INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)'
            );

            $notificationTitle = $reservationCount > 1
                ? 'New Multi-Date Reservation Request'
                : 'New Reservation Request';
            $notificationMessage = $requesterName
                . ' submitted ' . $requestCode
                . ' for ' . $type . ': ' . $itemName
                . ($reservationCount > 1 ? '. Selected slots: ' . $reservationCount : '. Required date: ' . $date)
                . '.';

            foreach ($recipientIds as $recipientId) {
                $notificationStatement->execute([
                    (int) $recipientId,
                    $notificationTitle,
                    $notificationMessage,
                    'reservation'
                ]);
            }
        }

        audit(
            $pdo,
            'CREATE',
            'Reservations',
            $requestCode . ' - ' . $type . ' #' . $selectionId . ' (' . $reservationCount . ' record(s))'
        );

        $pdo->commit();

        flash(
            'success',
            $reservationCount > 1
                ? $reservationCount . ' reservation time slots were submitted successfully.'
                : ($type === 'Laboratory'
                    ? 'Laboratory reservation submitted successfully.'
                    : 'Reservation submitted successfully.')
        );

        header('Location: index.php');
        exit;

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash('danger', 'An error occurred while submitting the reservation.');
        header('Location: ' . $redirect);
        exit;
    }
}
$equipment = $pdo->query("SELECT e.id,e.name,e.lab_id,e.hourly_price,l.name AS lab_name
                          FROM equipment e LEFT JOIN laboratories l ON l.id=e.lab_id
                          WHERE e.status='Available' ORDER BY e.name")->fetchAll();
$laboratories = $pdo->query('SELECT id,name,code,capacity,location FROM laboratories ORDER BY name')->fetchAll();
$materials = $pdo->query('SELECT id,name,unit,available_quantity FROM materials
                          WHERE available_quantity>0 ORDER BY name')->fetchAll();
$supplies = $pdo->query('SELECT id,name,unit,quantity FROM supplies
                         WHERE quantity>0 ORDER BY name')->fetchAll();

$page_title = 'New Reservation';
require '../../includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-xl-10">
    <div class="d-flex align-items-center mb-4">
        <a href="index.php" class="btn btn-outline-secondary me-3" title="Back to reservations">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div>
            <h2 class="mb-1">New Reservation</h2>
            <p class="text-muted mb-0">Reserve equipment, request materials or supplies, or book an entire laboratory.</p>
        </div>
    </div>

    <form method="post" id="reservationForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="date_needed" id="date_needed">
        <input type="hidden" name="time_slot" id="time_slot">
        <input type="hidden" name="booking_items" id="booking_items">

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card p-4 h-100">
                    <h5 class="mb-3">Reservation Details</h5>

                    <div class="mb-3">
                        <label for="type" class="form-label">Reservation Type</label>
                        <select class="form-select" name="type" id="type" required>
                            <option value="Equipment" <?= $selectedType==='Equipment'?'selected':'' ?>>Reserve Equipment</option>
                            <option value="Laboratory" <?= $selectedType==='Laboratory'?'selected':'' ?>>Reserve Entire Laboratory</option>
                            <option value="Material" <?= $selectedType==='Material'?'selected':'' ?>>Request Material</option>
                            <option value="Supply" <?= $selectedType==='Supply'?'selected':'' ?>>Request Supply</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="selection" class="form-label" id="selectionLabel">Equipment</label>
                        <select class="form-select" name="selection_id" id="selection" required>
                            <?php foreach ($equipment as $item): ?>
                                <option
                                    data-type="Equipment"
                                    data-price="<?= e($item['hourly_price'] ?? '') ?>"
                                    data-lab-id="<?= e($item['lab_id'] ?? '') ?>"
                                    data-lab-name="<?= e($item['lab_name'] ?? '') ?>"
                                    value="<?= $item['id'] ?>"
                                    <?= ($selectedType==='Equipment' && $selectedSelectionId===(int)$item['id'])?'selected':'' ?>
                                >
                                    <?= e($item['name']) ?><?= !empty($item['lab_name']) ? ' — '.e($item['lab_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>

                            <?php foreach ($laboratories as $lab): ?>
                                <option
                                    data-type="Laboratory"
                                    data-code="<?= e($lab['code']) ?>"
                                    data-capacity="<?= e($lab['capacity']) ?>"
                                    data-location="<?= e($lab['location'] ?? '') ?>"
                                    value="<?= $lab['id'] ?>"
                                    <?= ($selectedType==='Laboratory' && $selectedSelectionId===(int)$lab['id'])?'selected':'' ?>
                                >
                                    <?= e($lab['name']) ?> (<?= e($lab['code']) ?>)
                                </option>
                            <?php endforeach; ?>

                            <?php foreach ($materials as $item): ?>
                                <option
                                    data-type="Material"
                                    data-unit="<?= e($item['unit']) ?>"
                                    data-available="<?= e($item['available_quantity']) ?>"
                                    value="<?= $item['id'] ?>"
                                    <?= ($selectedType==='Material' && $selectedSelectionId===(int)$item['id'])?'selected':'' ?>
                                >
                                    <?= e($item['name']) ?>
                                </option>
                            <?php endforeach; ?>

                            <?php foreach ($supplies as $item): ?>
                                <option
                                    data-type="Supply"
                                    data-unit="<?= e($item['unit']) ?>"
                                    data-available="<?= e($item['quantity']) ?>"
                                    value="<?= $item['id'] ?>"
                                    <?= ($selectedType==='Supply' && $selectedSelectionId===(int)$item['id'])?'selected':'' ?>
                                >
                                    <?= e($item['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="supply-preview mb-3" id="supplyPreview" style="display:none;">
                        <div class="flex-grow-1">
                            <div class="small text-muted">Selected Supply</div>
                            <div class="fw-bold" id="supplyPreviewName">—</div>
                            <div class="supply-stock mt-2">
                                <i class="bi bi-boxes me-1"></i>
                                <span id="supplyPreviewAvailable">0</span> available
                            </div>
                        </div>
                    </div>

                    <div class="price-box mb-3" id="priceBox" style="display:none;">
                        <div class="price-icon"><i class="bi bi-cash-coin"></i></div>
                        <div>
                            <div class="small text-muted">Price per hour</div>
                            <div class="price-value"><span id="hourlyPrice">0.00</span> SAR</div>
                        </div>
                    </div>

                    <div class="laboratory-box mb-3" id="laboratoryBox" style="display:none;">
                        <div class="d-flex align-items-start gap-3">
                            <div class="laboratory-icon"><i class="bi bi-building"></i></div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold mb-1">Entire Laboratory Reservation</div>
                                <div class="small text-muted" id="laboratoryDetails"></div>
                            </div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" value="1"
                                   id="reserve_entire_lab" name="reserve_entire_lab" checked>
                            <label class="form-check-label" for="reserve_entire_lab">
                                I understand that this reserves the entire laboratory and blocks all equipment inside it during the selected time.
                            </label>
                        </div>
                    </div>

                    <div id="stockFields" style="display:none;">
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-12 col-xl-6">
                                <label for="quantity" class="form-label">Quantity</label>
                                <div class="quantity-control">
                                    <button type="button" class="btn btn-outline-secondary quantity-btn" id="decreaseQuantity" aria-label="Decrease quantity">−</button>
                                    <input class="form-control text-center" type="number" id="quantity" name="quantity"
                                           min="0.01" step="0.01" placeholder="0">
                                    <button type="button" class="btn btn-outline-secondary quantity-btn" id="increaseQuantity" aria-label="Increase quantity">+</button>
                                </div>
                                <div class="invalid-feedback d-block" id="quantityError" style="display:none!important;"></div>
                            </div>
                            <div class="col-md-6 col-lg-12 col-xl-6" id="unitField">
                                <label for="unit" class="form-label">Unit</label>
                                <select class="form-select" id="unit" name="unit">
                                    <option value="">Select unit</option>
                                    <option value="mL">Milliliter (mL)</option>
                                    <option value="mg">Milligram (mg)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="alert alert-light border py-2 mb-0" id="availableQuantityText"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3" id="materialDateSection" style="display:none;">
                        <label for="material_date" class="form-label">Required Date</label>
                        <input type="date" id="material_date" class="form-control" min="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mt-3">
                        <label for="purpose" class="form-label">Purpose of Use</label>
                        <textarea class="form-control" id="purpose" name="purpose" rows="4"
                                  placeholder="e.g. DNA extraction experiment" required></textarea>
                    </div>

                    <div class="reservation-summary mt-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="fw-bold">Reservation Summary</div>
                            <i class="bi bi-clipboard-check text-primary"></i>
                        </div>
                        <div id="supplySummaryDetails" style="display:none;">
                            <div class="summary-row"><span>Supply</span><strong id="summarySupplyName">—</strong></div>
                            <div class="summary-row"><span>Available</span><strong id="summaryAvailable">—</strong></div>
                            <div class="summary-row"><span>Requested</span><strong id="summaryRequested">—</strong></div>
                            <div class="summary-row"><span>Date</span><strong id="summaryDate">—</strong></div>
                            <div class="summary-row"><span>Purpose</span><strong id="summaryPurpose">—</strong></div>
                        </div>
                        <div class="fw-semibold" id="summaryText">Select a date and time.</div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mt-4">
                        <i class="bi bi-calendar-check me-1"></i> Submit Request
                    </button>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card p-4 h-100" id="calendarBookingSection">
                    <div class="calendar-header">
                        <button type="button" class="btn btn-light calendar-navigation" id="previousMonth">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <h5 class="mb-0" id="calendarMonthTitle"></h5>
                        <button type="button" class="btn btn-light calendar-navigation" id="nextMonth">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                    </div>

                    <div class="calendar-weekdays">
                        <div>Sun</div><div>Mon</div><div>Tue</div><div>Wed</div>
                        <div>Thu</div><div>Fri</div><div>Sat</div>
                    </div>
                    <div class="calendar-grid" id="calendarGrid"></div>
                    <div id="timeBookingArea">
                    <hr class="my-4">

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                        <div>
                            <h5 class="mb-1">Available Times</h5>
                            <div class="small text-muted" id="selectedDateText">Select a date from the calendar.</div>
                        </div>
                        <div class="calendar-legend">
                            <span><i class="available-dot"></i> Available</span>
                            <span><i class="booked-dot"></i> Booked</span>
                        </div>
                    </div>

                    <div class="booking-actions d-flex flex-wrap gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-primary" id="selectAllTimes" disabled>
                            <i class="bi bi-check2-all me-1"></i> Reserve Full Available Day
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearDaySelection" disabled>
                            Clear Day Times
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="removeSelectedDay" disabled>
                            Remove Day
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-dark" id="clearAllSelections" disabled>
                            Clear All Days
                        </button>
                    </div>

                    <div class="time-slots-grid">
                        <?php foreach ($timeSlots as $value => $label): ?>
                            <button type="button" class="time-slot" data-slot="<?= e($value) ?>" disabled>
                                <i class="bi bi-clock me-1"></i><?= e($label) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="alert alert-info mt-3 mb-0" id="calendarInstruction">
                        Select a date to view the available reservation times.
                    </div>
                    </div>
                </div>

                <div class="card p-5 text-center h-100" id="materialInformation" style="display:none;">
                    <div class="material-calendar-icon"><i class="bi bi-box-seam"></i></div>
                    <h4 id="stockRequestTitle">Material Request</h4>
                    <p class="text-muted mb-0" id="stockRequestDescription">
                        Material requests do not require a time slot. Select the required date, quantity and unit from the reservation form.
                    </p>
                </div>
            </div>
        </div>
    </form>
</div>
</div>

<style>
.calendar-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
.calendar-navigation{width:42px;height:42px;border:1px solid #e1e6ed}
.calendar-weekdays,.calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.calendar-weekdays{margin-bottom:8px;color:#778394;font-size:13px;font-weight:600;text-align:center}
.calendar-day{min-height:58px;border:1px solid #e2e7ee;border-radius:9px;background:#fff;color:#26374b;transition:.15s}
.calendar-day:hover:not(:disabled){border-color:#0d6efd;background:#f0f6ff}
.calendar-day.selected{border-color:#0d6efd;background:#0d6efd;color:#fff}
.calendar-day.today:not(.selected){border-color:#0d6efd;color:#0d6efd;font-weight:700}
.calendar-day:disabled{border-color:#edf0f3;background:#f5f6f8;color:#b1b8c1;cursor:not-allowed}
.calendar-empty{min-height:58px}
.time-slots-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.time-slot{padding:13px;border:1px solid #cfd8e5;border-radius:9px;background:#fff;color:#34465b;font-weight:600;transition:.15s}
.time-slot:hover:not(:disabled){border-color:#0d6efd;background:#f0f6ff;color:#0d6efd}
.time-slot.selected{border-color:#0d6efd;background:#0d6efd;color:#fff}
.time-slot.booked,.time-slot:disabled.booked{border-color:#e5e7eb;background:#eef0f3;color:#9ca4ae;text-decoration:line-through;cursor:not-allowed}
.calendar-legend{display:flex;flex-wrap:wrap;gap:12px;color:#6c7887;font-size:12px}
.calendar-legend span{display:flex;align-items:center;gap:5px}
.available-dot,.booked-dot{width:9px;height:9px;display:inline-block;border-radius:50%}
.available-dot{background:#198754}.booked-dot{background:#adb5bd}
.price-box{display:flex;align-items:center;gap:12px;padding:13px;border:1px solid #cfe1ff;border-radius:10px;background:#f2f7ff}
.price-icon,.laboratory-icon{width:42px;height:42px;display:flex;flex-shrink:0;align-items:center;justify-content:center;border-radius:10px;color:#fff;font-size:20px}
.price-icon{background:#0d6efd}.laboratory-icon{background:#6f42c1}
.price-value{color:#0d6efd;font-size:20px;font-weight:700}
.laboratory-box{padding:13px;border:1px solid #ded3f5;border-radius:10px;background:#f7f3ff}
.reservation-summary{padding:15px;border:1px solid #e1e7ef;border-radius:12px;background:#f8fafc}
.summary-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;padding:7px 0;border-top:1px solid #e7edf4;font-size:13px}
.summary-row span{color:#64748b}.summary-row strong{text-align:right;color:#172033;overflow-wrap:anywhere}
.supply-preview{display:flex;align-items:center;gap:14px;padding:14px;border:1px solid #cfe1ff;border-radius:12px;background:#f5f9ff}
.supply-stock{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#e8f7ef;color:#087a55;font-size:12px;font-weight:700}
.quantity-control{display:grid;grid-template-columns:42px 1fr 42px;gap:7px}
.quantity-btn{font-size:20px;line-height:1;padding:0}
.material-calendar-icon{width:80px;height:80px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border-radius:50%;background:#edf5ff;color:#0d6efd;font-size:38px}
.calendar-day.active-date{outline:3px solid rgba(13,110,253,.22);outline-offset:2px}
.summary-booking-day{display:flex;flex-direction:column;gap:2px;padding:8px 0;border-top:1px solid #e7edf4}
.summary-booking-day span{font-size:12px;color:#64748b;line-height:1.45}
.summary-total{font-size:13px;font-weight:700;color:#0d6efd}
.booking-actions .btn{white-space:nowrap}
@media(max-width:767.98px){.calendar-weekdays,.calendar-grid{gap:4px}.calendar-day,.calendar-empty{min-height:45px;font-size:13px}.time-slots-grid{grid-template-columns:1fr}}
</style>

<script>
const typeSelect=document.getElementById('type');
const selectionSelect=document.getElementById('selection');
const selectionLabel=document.getElementById('selectionLabel');
const stockFields=document.getElementById('stockFields');
const quantityInput=document.getElementById('quantity');
const unitSelect=document.getElementById('unit');
const unitField=document.getElementById('unitField');
const materialDateInput=document.getElementById('material_date');
const materialDateSection=document.getElementById('materialDateSection');
const calendarBookingSection=document.getElementById('calendarBookingSection');
const materialInformation=document.getElementById('materialInformation');
const stockRequestTitle=document.getElementById('stockRequestTitle');
const stockRequestDescription=document.getElementById('stockRequestDescription');
const priceBox=document.getElementById('priceBox');
const hourlyPriceText=document.getElementById('hourlyPrice');
const laboratoryBox=document.getElementById('laboratoryBox');
const laboratoryDetails=document.getElementById('laboratoryDetails');
const reserveEntireLabCheckbox=document.getElementById('reserve_entire_lab');
const dateNeededInput=document.getElementById('date_needed');
const timeSlotInput=document.getElementById('time_slot');
const bookingItemsInput=document.getElementById('booking_items');
const calendarGrid=document.getElementById('calendarGrid');
const calendarMonthTitle=document.getElementById('calendarMonthTitle');
const selectedDateText=document.getElementById('selectedDateText');
const summaryText=document.getElementById('summaryText');
const calendarInstruction=document.getElementById('calendarInstruction');
const timeBookingArea=document.getElementById('timeBookingArea');
const availableQuantityText=document.getElementById('availableQuantityText');
const purposeInput=document.getElementById('purpose');
const supplyPreview=document.getElementById('supplyPreview');
const supplyPreviewName=document.getElementById('supplyPreviewName');
const supplyPreviewAvailable=document.getElementById('supplyPreviewAvailable');
const decreaseQuantity=document.getElementById('decreaseQuantity');
const increaseQuantity=document.getElementById('increaseQuantity');
const quantityError=document.getElementById('quantityError');
const supplySummaryDetails=document.getElementById('supplySummaryDetails');
const summarySupplyName=document.getElementById('summarySupplyName');
const summaryAvailable=document.getElementById('summaryAvailable');
const summaryRequested=document.getElementById('summaryRequested');
const summaryDate=document.getElementById('summaryDate');
const summaryPurpose=document.getElementById('summaryPurpose');
const selectAllTimesButton=document.getElementById('selectAllTimes');
const clearDayButton=document.getElementById('clearDaySelection');
const removeDayButton=document.getElementById('removeSelectedDay');
const clearAllButton=document.getElementById('clearAllSelections');
const timeSlotButtons=Array.from(document.querySelectorAll('.time-slot'));
const slotLabels={
'08:00 - 09:00':'8:00 AM - 9:00 AM','09:00 - 10:00':'9:00 AM - 10:00 AM',
'10:00 - 11:00':'10:00 AM - 11:00 AM','11:00 - 12:00':'11:00 AM - 12:00 PM',
'12:00 - 13:00':'12:00 PM - 1:00 PM','13:00 - 14:00':'1:00 PM - 2:00 PM'};

const today=new Date(); today.setHours(0,0,0,0);
let displayedMonth=new Date(today.getFullYear(),today.getMonth(),1);
let activeDate='';
let selectedBookings={};
let unavailableByDate={};

function formatDateValue(d){return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`}
function formatReadableDate(v){return v?new Date(v+'T00:00:00').toLocaleDateString('en-US',{weekday:'short',year:'numeric',month:'short',day:'numeric'}):''}
function ensureDate(date){if(!selectedBookings[date])selectedBookings[date]=[]}
function selectedSlotCount(){return Object.values(selectedBookings).reduce((total,slots)=>total+slots.length,0)}
function cleanEmptyDates(){Object.keys(selectedBookings).forEach(date=>{if(!selectedBookings[date].length)delete selectedBookings[date]})}
function buildBookingItems(){
    const items=[];
    Object.keys(selectedBookings).sort().forEach(date=>{
        selectedBookings[date].forEach(timeSlot=>items.push({date,time_slot:timeSlot}));
    });
    return items;
}
function syncBookingInputs(){
    const items=buildBookingItems();
    bookingItemsInput.value=JSON.stringify(items);
    if(items.length){dateNeededInput.value=items[0].date;timeSlotInput.value=items[0].time_slot}
    else if(['Equipment','Laboratory'].includes(typeSelect.value)){dateNeededInput.value='';timeSlotInput.value=''}
}

function renderCalendar(){
    calendarGrid.innerHTML='';
    const year=displayedMonth.getFullYear(),month=displayedMonth.getMonth();
    calendarMonthTitle.textContent=displayedMonth.toLocaleDateString('en-US',{month:'long',year:'numeric'});
    const firstDayIndex=new Date(year,month,1).getDay();
    const daysInMonth=new Date(year,month+1,0).getDate();
    for(let i=0;i<firstDayIndex;i++){const e=document.createElement('div');e.className='calendar-empty';calendarGrid.appendChild(e)}
    for(let day=1;day<=daysInMonth;day++){
        const d=new Date(year,month,day);d.setHours(0,0,0,0);
        const value=formatDateValue(d),b=document.createElement('button');
        b.type='button';b.className='calendar-day';b.textContent=day;b.dataset.date=value;
        if(d<today)b.disabled=true;
        if(d.getTime()===today.getTime())b.classList.add('today');
        if(selectedBookings[value]?.length)b.classList.add('selected');
        if(activeDate===value)b.classList.add('active-date');
        b.addEventListener('click',()=>{
            activeDate=value;
            if(['Equipment','Laboratory'].includes(typeSelect.value))ensureDate(value);
            renderCalendar();
            if(['Equipment','Laboratory'].includes(typeSelect.value))loadBookedSlots();
            else{dateNeededInput.value=value;updateSummary()}
        });
        calendarGrid.appendChild(b);
    }
}

document.getElementById('previousMonth').addEventListener('click',()=>{
    const prev=new Date(displayedMonth.getFullYear(),displayedMonth.getMonth()-1,1);
    const current=new Date(today.getFullYear(),today.getMonth(),1);
    if(prev<current)return; displayedMonth=prev;renderCalendar();
});
document.getElementById('nextMonth').addEventListener('click',()=>{displayedMonth=new Date(displayedMonth.getFullYear(),displayedMonth.getMonth()+1,1);renderCalendar()});

function resetTimeSlots(){
    timeSlotButtons.forEach(b=>{
        b.disabled=activeDate==='';
        b.classList.remove('selected','booked');
        b.innerHTML='<i class="bi bi-clock me-1"></i>'+slotLabels[b.dataset.slot];
    });
    updateActionButtons();
}

function updateActionButtons(){
    const hasActiveDate=activeDate!=='';
    const availableCount=timeSlotButtons.filter(b=>!b.disabled&&!b.classList.contains('booked')).length;
    selectAllTimesButton.disabled=!hasActiveDate||availableCount===0;
    clearDayButton.disabled=!hasActiveDate||!(selectedBookings[activeDate]?.length);
    removeDayButton.disabled=!hasActiveDate||!Object.prototype.hasOwnProperty.call(selectedBookings,activeDate);
    clearAllButton.disabled=selectedSlotCount()===0;
}

async function loadBookedSlots(){
    resetTimeSlots();
    const type=typeSelect.value;
    if(!activeDate||!['Equipment','Laboratory'].includes(type)||!selectionSelect.value){
        selectedDateText.textContent='Select a date from the calendar.';return;
    }
    selectedDateText.textContent=formatReadableDate(activeDate);
    calendarInstruction.className='alert alert-light border mt-3 mb-0';
    calendarInstruction.textContent='Loading available times...';
    try{
        const response=await fetch(`create.php?ajax=booked_slots&type=${encodeURIComponent(type)}&selection_id=${encodeURIComponent(selectionSelect.value)}&date=${encodeURIComponent(activeDate)}`);
        const result=await response.json(); if(!result.success)throw new Error();
        const booked=result.booked_slots||[];
        unavailableByDate[activeDate]=booked;
        const selectedForDate=selectedBookings[activeDate]||[];
        selectedBookings[activeDate]=selectedForDate.filter(slot=>!booked.includes(slot));

        timeSlotButtons.forEach(b=>{
            const isBooked=booked.includes(b.dataset.slot);
            const isSelected=selectedBookings[activeDate]?.includes(b.dataset.slot);
            b.disabled=isBooked;
            b.classList.toggle('booked',isBooked);
            b.classList.toggle('selected',!isBooked&&isSelected);
            if(isBooked)b.innerHTML='<i class="bi bi-lock-fill me-1"></i>'+slotLabels[b.dataset.slot]+' — Booked';
        });
        const count=timeSlotButtons.filter(b=>!b.disabled).length;
        calendarInstruction.className=count?'alert alert-success mt-3 mb-0':'alert alert-warning mt-3 mb-0';
        calendarInstruction.textContent=count?`${count} time slot(s) available on this date.`:'All time slots are booked on this date.';
        syncBookingInputs();renderCalendar();updateActionButtons();updateSummary();
    }catch(e){
        calendarInstruction.className='alert alert-danger mt-3 mb-0';
        calendarInstruction.textContent='Unable to load available times. Please refresh the page.';
    }
}

timeSlotButtons.forEach(b=>b.addEventListener('click',()=>{
    if(b.disabled||!activeDate)return;
    ensureDate(activeDate);
    const slots=selectedBookings[activeDate];
    const index=slots.indexOf(b.dataset.slot);
    if(index>=0)slots.splice(index,1);else slots.push(b.dataset.slot);
    b.classList.toggle('selected',index<0);
    syncBookingInputs();renderCalendar();updateActionButtons();updateSummary();
}));

selectAllTimesButton.addEventListener('click',()=>{
    if(!activeDate)return;
    ensureDate(activeDate);
    selectedBookings[activeDate]=timeSlotButtons.filter(b=>!b.disabled).map(b=>b.dataset.slot);
    timeSlotButtons.forEach(b=>b.classList.toggle('selected',!b.disabled));
    syncBookingInputs();renderCalendar();updateActionButtons();updateSummary();
});
clearDayButton.addEventListener('click',()=>{
    if(!activeDate)return;
    selectedBookings[activeDate]=[];
    timeSlotButtons.forEach(b=>b.classList.remove('selected'));
    syncBookingInputs();renderCalendar();updateActionButtons();updateSummary();
});
removeDayButton.addEventListener('click',()=>{
    if(!activeDate)return;
    delete selectedBookings[activeDate];
    timeSlotButtons.forEach(b=>b.classList.remove('selected'));
    syncBookingInputs();renderCalendar();updateActionButtons();updateSummary();
});
clearAllButton.addEventListener('click',()=>{
    selectedBookings={};
    timeSlotButtons.forEach(b=>b.classList.remove('selected'));
    syncBookingInputs();renderCalendar();updateActionButtons();updateSummary();
});

function updateSelections(){
    const type=typeSelect.value,current=selectionSelect.options[selectionSelect.selectedIndex];let first=null,still=false;
    Array.from(selectionSelect.options).forEach(o=>{const match=o.dataset.type===type;o.hidden=!match;o.disabled=!match;if(match&&!first)first=o;if(match&&o===current)still=true});
    if(!still&&first)selectionSelect.value=first.value;updateInterface();
}

function updateInterface(){
    const type=typeSelect.value;
    const isMaterial=type==='Material';
    const isSupply=type==='Supply';
    const isStockRequest=isMaterial||isSupply;
    const isLab=type==='Laboratory';

    selectionLabel.textContent=type==='Equipment'?'Equipment':(isLab?'Laboratory':(isSupply?'Supply':'Material'));
    stockFields.style.display=isStockRequest?'block':'none';
    materialDateSection.style.display=isStockRequest?'block':'none';
    calendarBookingSection.style.display=isStockRequest?'none':'block';
    materialInformation.style.display=isStockRequest?'block':'none';
    if(isStockRequest){
        stockRequestTitle.textContent=isSupply?'Supply Request':'Material Request';
        stockRequestDescription.textContent=isSupply
            ?'Supply requests do not require a time slot. Select the required date and quantity from the reservation form.'
            :'Material requests do not require a time slot. Select the required date, quantity and unit from the reservation form.';
    }
    timeBookingArea.style.display='block';
    laboratoryBox.style.display=isLab?'block':'none';
    supplyPreview.style.display=isSupply?'flex':'none';

    quantityInput.required=isStockRequest;
    unitField.style.display=isMaterial?'block':'none';
    unitSelect.required=isMaterial;
    materialDateInput.required=isStockRequest;
    reserveEntireLabCheckbox.required=isLab;

    if(isMaterial){
        selectedBookings={};activeDate='';bookingItemsInput.value='';timeSlotInput.value='';priceBox.style.display='none';updateStockInfo();
    }else if(isSupply){
        selectedBookings={};activeDate='';bookingItemsInput.value='';timeSlotInput.value='';priceBox.style.display='none';
        dateNeededInput.value=materialDateInput.value;updateStockInfo();
    }else{
        quantityInput.value='';unitSelect.value='';materialDateInput.value='';
        updatePrice();updateLabInfo();if(activeDate)loadBookedSlots();
    }
    updateSummary();
}

function updatePrice(){
    const o=selectionSelect.options[selectionSelect.selectedIndex],price=o?o.dataset.price:'';
    if(typeSelect.value==='Equipment'&&price!==undefined&&price!==''){priceBox.style.display='flex';hourlyPriceText.textContent=Number(price).toFixed(2)}
    else{priceBox.style.display='none';hourlyPriceText.textContent='0.00'}
}

function updateLabInfo(){
    const o=selectionSelect.options[selectionSelect.selectedIndex];
    if(typeSelect.value!=='Laboratory'||!o){laboratoryDetails.textContent='';return}
    laboratoryDetails.textContent=`Code: ${o.dataset.code||'-'} · Capacity: ${o.dataset.capacity||'-'} · Location: ${o.dataset.location||'Not specified'}`;
}

function updateStockInfo(){
    const o=selectionSelect.options[selectionSelect.selectedIndex];
    if(!o||!['Material','Supply'].includes(o.dataset.type))return;
    const available=Number(o.dataset.available||0);
    const unit=o.dataset.unit||'';
    availableQuantityText.textContent=`Available quantity: ${available} ${unit}`;
    quantityInput.max=String(available);
    quantityInput.step=o.dataset.type==='Supply'?'1':'0.01';
    quantityInput.min=o.dataset.type==='Supply'?'1':'0.01';
    if(o.dataset.type==='Material'&&['mL','mg'].includes(unit))unitSelect.value=unit;
    if(o.dataset.type==='Supply'){
        unitSelect.value='';supplyPreviewName.textContent=o.textContent.trim();supplyPreviewAvailable.textContent=`${available} ${unit}`.trim();
    }
    validateQuantity();
}

function validateQuantity(){
    if(!['Material','Supply'].includes(typeSelect.value)){
        quantityInput.setCustomValidity('');quantityError.style.setProperty('display','none','important');return true;
    }
    const o=selectionSelect.options[selectionSelect.selectedIndex];
    const available=Number(o?.dataset.available||0),value=Number(quantityInput.value||0);let message='';
    if(value<=0)message='Please enter a quantity greater than zero.';
    else if(value>available)message=`Maximum available quantity is ${available} ${o?.dataset.unit||''}.`;
    else if(typeSelect.value==='Supply'&&!Number.isInteger(value))message='Supply quantity must be a whole number.';
    quantityInput.setCustomValidity(message);quantityInput.classList.toggle('is-invalid',message!=='');quantityError.textContent=message;
    quantityError.style.setProperty('display',message?'block':'none','important');return message==='';
}

function changeQuantity(direction){
    if(!['Material','Supply'].includes(typeSelect.value))return;
    const step=typeSelect.value==='Supply'?1:0.01,min=typeSelect.value==='Supply'?1:0.01,max=Number(quantityInput.max||Infinity);
    let current=Number(quantityInput.value||0);current=current>0?current:min;
    const next=Math.min(max,Math.max(min,current+(direction*step)));
    quantityInput.value=typeSelect.value==='Supply'?String(Math.round(next)):next.toFixed(2);
    validateQuantity();updateSummary();
}

decreaseQuantity.addEventListener('click',()=>changeQuantity(-1));
increaseQuantity.addEventListener('click',()=>changeQuantity(1));
quantityInput.addEventListener('input',()=>{validateQuantity();updateSummary()});
purposeInput.addEventListener('input',updateSummary);
materialDateInput.addEventListener('change',()=>{dateNeededInput.value=materialDateInput.value;updateSummary()});

function updateSummary(){
    const o=selectionSelect.options[selectionSelect.selectedIndex],name=o?o.textContent.trim():'No selection';
    const isSupply=typeSelect.value==='Supply';
    supplySummaryDetails.style.display=isSupply?'block':'none';summaryText.style.display=isSupply?'none':'block';
    if(isSupply){
        const unit=o?.dataset.unit||'';
        summarySupplyName.textContent=name;summaryAvailable.textContent=`${o?.dataset.available||0} ${unit}`.trim();
        summaryRequested.textContent=quantityInput.value?`${quantityInput.value} ${unit}`.trim():'—';
        summaryDate.textContent=materialDateInput.value?formatReadableDate(materialDateInput.value):'—';summaryPurpose.textContent=purposeInput.value.trim()||'—';return;
    }
    if(typeSelect.value==='Material'){
        summaryText.textContent=materialDateInput.value?`${name} — ${formatReadableDate(materialDateInput.value)}`:`${name}: select the required date.`;return;
    }

    cleanEmptyDates();syncBookingInputs();
    const dates=Object.keys(selectedBookings).sort();
    const totalSlots=selectedSlotCount();
    if(!dates.length){summaryText.textContent=`${name}: select one or more dates and times.`;return}

    const lines=dates.map(date=>{
        const slots=selectedBookings[date];
        const labels=slots.map(slot=>slotLabels[slot]).join(', ');
        return `<div class="summary-booking-day"><strong>${formatReadableDate(date)}</strong><span>${labels}</span></div>`;
    }).join('');
    summaryText.innerHTML=`<div class="mb-2">${typeSelect.value==='Laboratory'?'Entire laboratory':'Equipment'}: <strong>${name}</strong></div>${lines}<div class="summary-total mt-2">${dates.length} day(s) · ${totalSlots} time slot(s)</div>`;
}

document.getElementById('reservationForm').addEventListener('submit',e=>{
    if(['Material','Supply'].includes(typeSelect.value)&&!validateQuantity()){e.preventDefault();quantityInput.focus();return}
    if(['Equipment','Laboratory'].includes(typeSelect.value)){
        cleanEmptyDates();syncBookingInputs();
        if(selectedSlotCount()===0){e.preventDefault();alert('Please select at least one date and time slot.');return}
    }else if(typeSelect.value==='Material'){
        dateNeededInput.value=materialDateInput.value;
    }else if(typeSelect.value==='Supply'){
        if(!materialDateInput.value){e.preventDefault();alert('Please select the required date.');return}
        dateNeededInput.value=materialDateInput.value;
    }
    if(typeSelect.value==='Laboratory'&&!reserveEntireLabCheckbox.checked){e.preventDefault();alert('Please confirm the entire laboratory reservation.')}
});

typeSelect.addEventListener('change',()=>{
    activeDate='';selectedBookings={};unavailableByDate={};dateNeededInput.value='';timeSlotInput.value='';bookingItemsInput.value='';
    updateSelections();resetTimeSlots();renderCalendar();
});
selectionSelect.addEventListener('change',()=>{
    selectedBookings={};unavailableByDate={};bookingItemsInput.value='';timeSlotInput.value='';
    updatePrice();updateLabInfo();updateStockInfo();updateSummary();renderCalendar();
    if(['Equipment','Laboratory'].includes(typeSelect.value)&&activeDate)loadBookedSlots();
});
document.addEventListener('DOMContentLoaded',()=>{renderCalendar();updateSelections();updatePrice();updateLabInfo();updateStockInfo();resetTimeSlots();updateActionButtons()});
</script>

<?php require '../../includes/footer.php'; ?>