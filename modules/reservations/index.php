<?php
require '../../config/database.php';
require '../../includes/auth.php';
require_login();

function reservation_status_class(string $status): string
{
    return match ($status) {
        'Approved' => 'status-approved',
        'In Use' => 'status-in-use',
        'Completed' => 'status-completed',
        'Rejected' => 'status-rejected',
        default => 'status-pending',
    };
}

function reservation_type_icon(string $type): string
{
    return match ($type) {
        'Equipment' => 'bi-cpu',
        'Material' => 'bi-box-seam',
        'Laboratory' => 'bi-building',
        'Supply' => 'bi-box2-heart',
        'Storage Space' => 'bi-archive',
        default => 'bi-calendar-check',
    };
}

$isRegularUser = in_array(user()['role'] ?? '', ['User', 'Student'], true);



function material_request_to_stock_unit(float $quantity, ?string $requestUnit, ?string $stockUnit): float
{
    $requestUnit = (string)$requestUnit;
    $stockUnit = (string)$stockUnit;

    // Materials are stored in base units in the materials table:
    // Liquid => L, Solid => g. Reservation requests use mL or mg.
    if ($requestUnit === 'mL' && $stockUnit === 'L') {
        return $quantity / 1000;
    }

    if ($requestUnit === 'mg' && $stockUnit === 'g') {
        return $quantity / 1000;
    }

    // Backward compatibility if an older request already used the stock unit.
    if ($requestUnit === $stockUnit) {
        return $quantity;
    }

    return -1;
}

function storage_reservation_quantity_in_samples(float $quantity, ?string $unit): int
{
    $factor = match ((string)$unit) {
        'Sample', 'Sample(s)' => 1,
        'Box', 'Box(es)' => 100,
        'Rack', 'Rack(s)' => 1200,
        'Shelf', 'Shelf/Shelves' => 4800,

        // Legacy values from requests created before the standardized unit model.
        'Space(s)', 'Container(s)', '' => 1,
        default => 0,
    };

    return $factor > 0
        ? max(0, (int)round($quantity * $factor))
        : 0;
}



/* Approve or reject a complete reservation group. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role(['Supervisor', 'Admin']);
    verify_csrf();

    $groupKey = trim($_POST['group_key'] ?? '');
    $action = trim($_POST['action'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($action, ['Approved', 'Rejected'], true)) {
        flash('danger', 'Invalid reservation action.');
        header('Location: index.php');
        exit;
    }
    if ($groupKey === '') {
        flash('danger', 'Reservation group was not found.');
        header('Location: index.php');
        exit;
    }
    if ($action === 'Rejected' && $reason === '') {
        flash('danger', 'Please enter a rejection reason.');
        header('Location: index.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            "SELECT * FROM reservations
             WHERE COALESCE(reservation_group, CONCAT('LEGACY-', id)) = ?
             FOR UPDATE"
        );
        $statement->execute([$groupKey]);
        $groupRows = $statement->fetchAll();

        if (!$groupRows) {
            throw new RuntimeException('This reservation no longer exists.');
        }
        foreach ($groupRows as $row) {
            if ($row['status'] !== 'Pending') {
                throw new RuntimeException('This reservation has already been processed.');
            }
        }

        // Update stock or capacity when a request is approved.
        if ($action === 'Approved') {
            foreach ($groupRows as $row) {
                $requestedQuantity = (float) $row['quantity'];

                if ($row['type'] === 'Material') {
                    $materialStatement = $pdo->prepare(
                        'SELECT id, available_quantity, unit
                         FROM materials
                         WHERE id = ?
                         FOR UPDATE'
                    );
                    $materialStatement->execute([(int)$row['item_id']]);
                    $material = $materialStatement->fetch();

                    if (!$material || $requestedQuantity <= 0) {
                        throw new RuntimeException('The requested material is no longer available.');
                    }

                    $requestedInStockUnit = material_request_to_stock_unit(
                        $requestedQuantity,
                        $row['unit'] ?? null,
                        $material['unit'] ?? null
                    );

                    if ($requestedInStockUnit <= 0) {
                        throw new RuntimeException(
                            'The requested material unit does not match the material stock unit.'
                        );
                    }

                    if ((float)$material['available_quantity'] < $requestedInStockUnit) {
                        throw new RuntimeException(
                            'There is not enough material stock to approve this request.'
                        );
                    }

                    $materialStatement = $pdo->prepare(
                        'UPDATE materials
                         SET available_quantity = GREATEST(0, available_quantity - ?)
                         WHERE id = ?'
                    );
                    $materialStatement->execute([
                        $requestedInStockUnit,
                        (int)$row['item_id']
                    ]);
                }

                if ($row['type'] === 'Supply') {
                    $stockStatement = $pdo->prepare('SELECT id,quantity FROM supplies WHERE id=? FOR UPDATE');
                    $stockStatement->execute([(int) $row['item_id']]);
                    $supply = $stockStatement->fetch();

                    if (!$supply || $requestedQuantity <= 0 || (float) $supply['quantity'] < $requestedQuantity) {
                        throw new RuntimeException('There is not enough supply stock to approve this request.');
                    }

                    $stockStatement = $pdo->prepare('UPDATE supplies SET quantity=quantity-? WHERE id=?');
                    $stockStatement->execute([$requestedQuantity, (int) $row['item_id']]);
                }

                if ($row['type'] === 'Storage Space') {
                    $requestedStorageSamples = storage_reservation_quantity_in_samples(
                        (float)$row['quantity'],
                        $row['unit'] ?? null
                    );

                    $storageStatement = $pdo->prepare(
                        'SELECT id,capacity,used_capacity,status
                         FROM storage_spaces
                         WHERE id=?
                         FOR UPDATE'
                    );
                    $storageStatement->execute([(int) $row['item_id']]);
                    $storage = $storageStatement->fetch();

                    $available = $storage
                        ? max(0, (int) $storage['capacity'] - (int) $storage['used_capacity'])
                        : 0;

                    if (!$storage || $requestedStorageSamples <= 0 || $storage['status'] === 'Under Maintenance' || $available < $requestedStorageSamples) {
                        throw new RuntimeException('There is not enough available storage capacity to approve this request.');
                    }

                    $newUsed = (int) $storage['used_capacity'] + $requestedStorageSamples;
                    $newStatus = $newUsed >= (int) $storage['capacity'] ? 'Full' : 'Partially Available';

                    $storageStatement = $pdo->prepare(
                        'UPDATE storage_spaces
                         SET used_capacity=?, status=?
                         WHERE id=?'
                    );
                    $storageStatement->execute([$newUsed, $newStatus, (int) $row['item_id']]);
                }
            }
        }

        $update = $pdo->prepare(
            "UPDATE reservations
             SET status=?, rejection_reason=?
             WHERE COALESCE(reservation_group, CONCAT('LEGACY-', id))=? AND status='Pending'"
        );
        $update->execute([$action, $action === 'Rejected' ? $reason : null, $groupKey]);

        if ($update->rowCount() !== count($groupRows)) {
            throw new RuntimeException('This reservation has already been processed.');
        }

        $firstId = (int) $groupRows[0]['id'];
        $requestCode = 'RQ-' . str_pad((string) $firstId, 4, '0', STR_PAD_LEFT);
        $notificationTitle = $action === 'Approved' ? 'Reservation Approved' : 'Reservation Rejected';
        $notificationMessage = $action === 'Approved'
            ? 'Your reservation ' . $requestCode . ' has been approved successfully.'
            : 'Your reservation ' . $requestCode . ' has been rejected.' . ($reason !== '' ? ' Reason: ' . $reason : '');
        $notification = $pdo->prepare(
            'INSERT INTO notifications (user_id,title,message,type,is_read) VALUES (?,?,?,?,0)'
        );
        $notification->execute([
            (int) $groupRows[0]['user_id'],
            $notificationTitle,
            $notificationMessage,
            $action === 'Approved' ? 'success' : 'danger',
        ]);

        audit($pdo, 'STATUS', 'Reservations', $requestCode . ' group ' . $action);
        $pdo->commit();
        flash('success', 'The complete reservation request was updated successfully.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception->getMessage());
    }

    header('Location: index.php');
    exit;
}

$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');
$search = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$allowedStatuses = ['Pending', 'Approved', 'In Use', 'Completed', 'Rejected'];
$allowedTypes = ['Equipment', 'Material', 'Laboratory', 'Supply', 'Storage Space'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = '';
if (!in_array($typeFilter, $allowedTypes, true)) $typeFilter = '';

$sql = "SELECT r.*,u.name AS user_name,
        CASE WHEN r.type='Equipment' THEN e.name WHEN r.type='Material' THEN m.name
             WHEN r.type='Laboratory' THEN l.name WHEN r.type='Supply' THEN s.name
             WHEN r.type='Storage Space' THEN ss.name ELSE NULL END AS item_name
        FROM reservations r
        JOIN users u ON u.id=r.user_id
        LEFT JOIN equipment e ON r.type='Equipment' AND e.id=r.item_id
        LEFT JOIN materials m ON r.type='Material' AND m.id=r.item_id
        LEFT JOIN laboratories l ON r.type='Laboratory' AND l.id=r.laboratory_id
        LEFT JOIN supplies s ON r.type='Supply' AND s.id=r.item_id
        LEFT JOIN storage_spaces ss ON r.type='Storage Space' AND ss.id=r.item_id
        WHERE 1=1";
$params = [];
if ($isRegularUser) { $sql .= ' AND r.user_id=?'; $params[] = (int) user()['id']; }
if ($statusFilter !== '') { $sql .= ' AND r.status=?'; $params[] = $statusFilter; }
if ($typeFilter !== '') { $sql .= ' AND r.type=?'; $params[] = $typeFilter; }
if ($dateFrom !== '') { $sql .= ' AND r.date_needed>=?'; $params[] = $dateFrom; }
if ($dateTo !== '') { $sql .= ' AND r.date_needed<=?'; $params[] = $dateTo; }
if ($search !== '') {
    $sql .= " AND (u.name LIKE ? OR e.name LIKE ? OR m.name LIKE ? OR l.name LIKE ? OR s.name LIKE ? OR ss.name LIKE ? OR r.purpose LIKE ? OR CAST(r.id AS CHAR) LIKE ? OR r.reservation_group LIKE ?)";
    $v = '%' . $search . '%'; for ($i=0; $i<9; $i++) $params[] = $v;
}
$sql .= ' ORDER BY r.id DESC';
$statement = $pdo->prepare($sql); $statement->execute($params); $rows = $statement->fetchAll();

$groups = [];
foreach ($rows as $row) {
    $key = !empty($row['reservation_group']) ? $row['reservation_group'] : 'LEGACY-' . $row['id'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key'=>$key, 'first_id'=>(int)$row['id'], 'user_id'=>(int)$row['user_id'],
            'user_name'=>$row['user_name'], 'item_name'=>$row['item_name'], 'type'=>$row['type'],
            'quantity'=>$row['quantity'], 'unit'=>$row['unit'], 'sample_type'=>$row['sample_type'] ?? null, 'purpose'=>$row['purpose'],
            'status'=>$row['status'], 'rejection_reason'=>$row['rejection_reason'], 'rows'=>[]
        ];
    }
    $groups[$key]['first_id'] = min($groups[$key]['first_id'], (int)$row['id']);
    $groups[$key]['rows'][] = $row;
}
foreach ($groups as &$group) {
    usort($group['rows'], fn($a,$b) => [$a['date_needed'],$a['time_slot']] <=> [$b['date_needed'],$b['time_slot']]);
    $statuses = array_unique(array_column($group['rows'], 'status'));
    $group['status'] = count($statuses) === 1 ? $statuses[0] : 'Mixed';
    $group['dates'] = array_values(array_unique(array_column($group['rows'], 'date_needed')));
    $group['slot_count'] = count(array_filter(array_column($group['rows'], 'time_slot')));
}
unset($group);

$page_title = $isRegularUser ? 'My Reservations' : 'Reservations';
require '../../includes/header.php';
?>
<style>
.reservation-tabs{display:inline-flex;flex-wrap:wrap;gap:5px;padding:5px;background:#eaf0f7;border-radius:12px}.reservation-tab{border:0;border-radius:9px;padding:8px 15px;color:#56657a;text-decoration:none;font-weight:600;font-size:.9rem}.reservation-tab.active{background:#fff;color:#172033;box-shadow:0 2px 8px rgba(15,23,42,.08)}.reservation-filter-card,.reservation-table-card{border:1px solid #e2e7ee;border-radius:16px}.reservation-table-card{overflow:hidden}.reservation-table{margin-bottom:0}.reservation-table thead th{background:#f8fafc;color:#64748b;font-size:.76rem;text-transform:uppercase;letter-spacing:.03em;padding:.9rem 1rem;white-space:nowrap}.reservation-table tbody td{padding:.95rem 1rem;vertical-align:middle;border-color:#edf1f5}.request-code{font-weight:700;color:#172033;white-space:nowrap}.item-cell{min-width:220px}.item-icon{width:35px;height:35px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;color:#0d6efd;background:#edf4ff;flex:0 0 35px}.reservation-status{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-size:.78rem;font-weight:700;white-space:nowrap}.reservation-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.status-pending{color:#b66a00;background:#fff4dd}.status-approved{color:#079455;background:#e8f7ef}.status-in-use{color:#0d6efd;background:#e8f1ff}.status-completed{color:#087a55;background:#e6f4ef}.status-rejected{color:#d92d20;background:#feecec}.slot-chip{display:inline-flex;padding:5px 9px;border:1px solid #dce4ed;border-radius:999px;background:#f8fafc;font-size:.82rem;margin:3px}.details-row td{background:#fbfdff}.date-heading{font-weight:700;color:#344054;margin:8px 0 3px}.toggle-details{white-space:nowrap}@media(max-width:1199.98px){.reservation-table-card{overflow-x:auto}.reservation-table{min-width:1100px}}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4"><div><h2 class="mb-1"><?= $isRegularUser?'My Reservations':'Reservations' ?></h2><p class="text-muted mb-0">Each submission appears once, with all selected dates and time slots grouped together.</p></div><a href="create.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Reservation</a></div>
<div class="reservation-tabs mb-3">
<?php foreach ([''=>'All','Pending'=>'Pending','Approved'=>'Approved','In Use'=>'In Use','Completed'=>'Completed','Rejected'=>'Rejected'] as $v=>$label): $q=$_GET; $q['status']=$v; if($v==='')unset($q['status']); ?>
<a href="index.php<?= $q?'?'.e(http_build_query($q)):'' ?>" class="reservation-tab <?= $statusFilter===$v?'active':'' ?>"><?= e($label) ?></a><?php endforeach; ?>
</div>
<div class="card p-3 reservation-filter-card mb-4"><form method="get"><?php if($statusFilter!==''): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?><div class="row g-2 align-items-end"><div class="col-xl-4"><label class="form-label">Search</label><input type="search" name="q" class="form-control" placeholder="Request ID, item, user or purpose" value="<?= e($search) ?>"></div><div class="col-md-4 col-xl-2"><label class="form-label">Type</label><select name="type" class="form-select"><option value="">All types</option><?php foreach($allowedTypes as $t): ?><option value="<?= e($t) ?>" <?= $typeFilter===$t?'selected':'' ?>><?= e($t) ?></option><?php endforeach; ?></select></div><div class="col-md-4 col-xl-2"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>"></div><div class="col-md-4 col-xl-2"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>"></div><div class="col-xl-2"><div class="d-flex gap-2"><button class="btn btn-outline-primary flex-fill"><i class="bi bi-search"></i></button><a href="index.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a></div></div></div></form></div>
<div class="card reservation-table-card"><div class="table-responsive"><table class="table reservation-table"><thead><tr><th>Request ID</th><?php if(!$isRegularUser): ?><th>User</th><?php endif; ?><th>Item</th><th>Type</th><th>Dates</th><th>Time</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if(empty($groups)): ?><tr><td colspan="<?= $isRegularUser?7:8 ?>" class="text-center py-5"><i class="bi bi-calendar2-x display-6 text-muted"></i><div class="mt-3 fw-semibold">No reservations found</div></td></tr><?php endif; ?>
<?php foreach($groups as $group): $detailsId='details-'.preg_replace('/[^A-Za-z0-9_-]/','',$group['key']); $dateText=count($group['dates'])===1?$group['dates'][0]:$group['dates'][0].' → '.end($group['dates']); $canLog=in_array($group['status'],['Approved','In Use','Completed'],true); ?>
<tr><td><span class="request-code">RQ-<?= str_pad((string)$group['first_id'],4,'0',STR_PAD_LEFT) ?></span><div class="small text-muted"><?= count($group['rows']) ?> record<?= count($group['rows'])===1?'':'s' ?></div></td><?php if(!$isRegularUser): ?><td><?= e($group['user_name']) ?></td><?php endif; ?><td class="item-cell"><div class="d-flex align-items-center gap-2"><span class="item-icon"><i class="bi <?= e(reservation_type_icon($group['type'])) ?>"></i></span><div class="fw-semibold"><?= e($group['item_name']??'Deleted item') ?></div></div></td><td><?= e($group['type']) ?></td><td><?= e($dateText) ?><div class="small text-muted"><?= count($group['dates']) ?> day<?= count($group['dates'])===1?'':'s' ?></div></td><td><?= $group['slot_count']>0?e($group['slot_count']).' time slot'.($group['slot_count']===1?'':'s'):'—' ?></td><td><span class="reservation-status <?= e(reservation_status_class($group['status'])) ?>"><?= e($group['status']) ?></span></td><td><div class="d-flex flex-wrap gap-1"><button type="button" class="btn btn-sm btn-outline-secondary toggle-details" data-bs-toggle="collapse" data-bs-target="#<?= e($detailsId) ?>"><i class="bi bi-eye me-1"></i>Details</button><?php if(in_array(user()['role'],['Supervisor','Admin'],true)&&$group['status']==='Pending'): ?><button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#actionModal" data-group="<?= e($group['key']) ?>" data-action="Approved" data-code="RQ-<?= str_pad((string)$group['first_id'],4,'0',STR_PAD_LEFT) ?>">Approve</button><button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#actionModal" data-group="<?= e($group['key']) ?>" data-action="Rejected" data-code="RQ-<?= str_pad((string)$group['first_id'],4,'0',STR_PAD_LEFT) ?>">Reject</button><?php elseif($canLog): ?><a href="log_sheet.php?id=<?= (int)$group['first_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pen me-1"></i>Log Sheet</a><?php elseif($group['status']==='Rejected'): ?><span class="small text-danger align-self-center"><?= e($group['rejection_reason']?:'Rejected') ?></span><?php endif; ?></div></td></tr>
<tr class="collapse details-row" id="<?= e($detailsId) ?>"><td colspan="<?= $isRegularUser?7:8 ?>"><div class="p-2"><div class="fw-bold mb-2">Reservation details</div><?php if (!empty($group['sample_type'])): ?><div class="small text-muted mb-2"><strong>Sample type:</strong> <?= e($group['sample_type']) ?></div><?php endif; ?><div class="small text-muted mb-2"><strong>Purpose:</strong> <?= e($group['purpose']) ?></div><?php $byDate=[]; foreach($group['rows'] as $r)$byDate[$r['date_needed']][]=$r; foreach($byDate as $d=>$dateRows): ?><div class="date-heading"><i class="bi bi-calendar3 me-1"></i><?= e($d) ?></div><?php foreach($dateRows as $r): ?><span class="slot-chip"><?= !empty($r['time_slot'])?e($r['time_slot']):e($r['quantity'].' '.$r['unit']) ?></span><?php endforeach; endforeach; ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div>
<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><div class="modal-header"><h5 class="modal-title" id="actionTitle">Update reservation</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="group_key" id="modalGroup"><input type="hidden" name="action" id="modalAction"><p class="mb-3" id="actionMessage"></p><div id="reasonArea" style="display:none"><label class="form-label">Rejection reason</label><textarea name="reason" id="modalReason" class="form-control" rows="3"></textarea></div><div class="alert alert-light border mt-3 mb-0">This action applies to every date and time slot in the request.</div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn" id="confirmAction">Confirm</button></div></form></div></div></div>
<script>document.getElementById('actionModal')?.addEventListener('show.bs.modal',event=>{const b=event.relatedTarget,a=b.dataset.action,code=b.dataset.code;document.getElementById('modalGroup').value=b.dataset.group;document.getElementById('modalAction').value=a;document.getElementById('actionTitle').textContent=a==='Approved'?'Approve reservation':'Reject reservation';document.getElementById('actionMessage').textContent=`${a==='Approved'?'Approve':'Reject'} ${code} and all of its dates and time slots?`;const reason=document.getElementById('reasonArea'),input=document.getElementById('modalReason'),confirm=document.getElementById('confirmAction');reason.style.display=a==='Rejected'?'block':'none';input.required=a==='Rejected';input.value='';confirm.textContent=a==='Approved'?'Approve request':'Reject request';confirm.className='btn '+(a==='Approved'?'btn-success':'btn-danger')});</script>
<?php require '../../includes/footer.php'; ?>