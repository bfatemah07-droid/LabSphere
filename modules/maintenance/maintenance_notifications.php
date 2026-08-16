<?php

function create_maintenance_due_notifications(PDO $pdo): void
{
    try {
        $currentUser = user();

        if (
            !$currentUser
            || !in_array($currentUser['role'] ?? '', ['Supervisor', 'Admin'], true)
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Detect Tracking Table Columns
        |--------------------------------------------------------------------------
        */

        $trackingColumns = [];

        $columnRows = $pdo->query(
            'SHOW COLUMNS FROM maintenance_notification_tracking'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columnRows as $columnRow) {
            $trackingColumns[$columnRow['Field']] = true;
        }

        $usesNotificationKey = isset($trackingColumns['notification_key']);
        $usesNotificationType = isset($trackingColumns['notification_type']);
        $usesNotificationDate = isset($trackingColumns['notification_date']);
        $usesSentAt = isset($trackingColumns['sent_at']);

        /*
         * The function stops safely if the tracking table does not have
         * either supported structure.
         */
        if (
            !$usesNotificationKey
            && !($usesNotificationType && $usesNotificationDate)
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Equipment and Latest Acknowledgement
        |--------------------------------------------------------------------------
        */

        $equipmentRows = $pdo->query(
            'SELECT
                equipment.id,
                equipment.name,
                equipment.maintenance_start_date,
                equipment.last_maintenance,
                equipment.next_maintenance,
                laboratories.name AS laboratory_name,
                latest_ack.confirmed_at,
                latest_ack.next_due_date
             FROM equipment
             LEFT JOIN laboratories
                ON laboratories.id = equipment.lab_id
             LEFT JOIN (
                SELECT ma.*
                FROM maintenance_acknowledgments ma
                INNER JOIN (
                    SELECT equipment_id, MAX(id) AS latest_id
                    FROM maintenance_acknowledgments
                    GROUP BY equipment_id
                ) latest
                    ON latest.latest_id = ma.id
             ) latest_ack
                ON latest_ack.equipment_id = equipment.id
             ORDER BY equipment.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        if (!$equipmentRows) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Recipients
        |--------------------------------------------------------------------------
        */

        $recipientIds = $pdo->query(
            "SELECT id
             FROM users
             WHERE role IN ('Admin', 'Supervisor')"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (!$recipientIds) {
            return;
        }

        $insertNotification = $pdo->prepare(
            'INSERT INTO notifications
                (user_id, title, message, type, is_read)
             VALUES (?, ?, ?, ?, 0)'
        );

        $today = new DateTimeImmutable('today');

        foreach ($equipmentRows as $equipment) {
            $equipmentId = (int) $equipment['id'];
            $dueDate = null;

            $candidateDueDates = [];

            if (!empty($equipment['next_due_date'])) {
                $candidateDueDates[] = new DateTimeImmutable($equipment['next_due_date']);
            }

            if (!empty($equipment['next_maintenance'])) {
                $candidateDueDates[] = new DateTimeImmutable($equipment['next_maintenance']);
            }

            if (empty($candidateDueDates) && !empty($equipment['confirmed_at'])) {
                $candidateDueDates[] = (new DateTimeImmutable($equipment['confirmed_at']))
                    ->modify('+6 months');
            }

            if (empty($candidateDueDates) && !empty($equipment['last_maintenance'])) {
                $candidateDueDates[] = (new DateTimeImmutable($equipment['last_maintenance']))
                    ->modify('+6 months');
            }

            if (empty($candidateDueDates) && !empty($equipment['maintenance_start_date'])) {
                $candidateDueDates[] = (new DateTimeImmutable($equipment['maintenance_start_date']))
                    ->modify('+6 months');
            }

            if ($candidateDueDates) {
                usort(
                    $candidateDueDates,
                    static fn (DateTimeImmutable $first, DateTimeImmutable $second): int
                        => $first <=> $second
                );

                $dueDate = $candidateDueDates[0];
            }

            if ($dueDate === null) {
                continue;
            }

            $equipmentName = trim((string) ($equipment['name'] ?? 'Equipment'));
            $laboratoryName = trim((string) ($equipment['laboratory_name'] ?? ''));

            $locationText = $laboratoryName !== ''
                ? ' in ' . $laboratoryName
                : '';

            if ($dueDate < $today) {
                $daysOverdue = (int) $dueDate->diff($today)->days;
                $notificationType = 'overdue';
                $notificationKey = 'overdue_' . $dueDate->format('Y-m-d');

                $title = 'Maintenance Inspection Overdue';
                $message = $equipmentName
                    . $locationText
                    . ' is overdue for its six-month inspection by '
                    . $daysOverdue
                    . ' day(s). Due date: '
                    . $dueDate->format('Y-m-d')
                    . '.';

                $storedType = 'maintenance_overdue';
            } else {
                $daysLeft = (int) $today->diff($dueDate)->days;

                if ($daysLeft > 14) {
                    continue;
                }

                $notificationType = 'due_soon';
                $notificationKey = 'due_soon_' . $dueDate->format('Y-m-d');

                $title = 'Maintenance Inspection Due Soon';
                $message = $equipmentName
                    . $locationText
                    . ' requires its six-month inspection in '
                    . $daysLeft
                    . ' day(s). Due date: '
                    . $dueDate->format('Y-m-d')
                    . '.';

                $storedType = 'maintenance_due';
            }

            /*
            |--------------------------------------------------------------------------
            | Duplicate Check
            |--------------------------------------------------------------------------
            */

            if ($usesNotificationKey) {
                $check = $pdo->prepare(
                    'SELECT id
                     FROM maintenance_notification_tracking
                     WHERE equipment_id = ?
                       AND notification_key = ?
                     LIMIT 1'
                );

                $check->execute([
                    $equipmentId,
                    $notificationKey
                ]);
            } else {
                $check = $pdo->prepare(
                    'SELECT id
                     FROM maintenance_notification_tracking
                     WHERE equipment_id = ?
                       AND notification_type = ?
                       AND notification_date = ?
                     LIMIT 1'
                );

                $check->execute([
                    $equipmentId,
                    $notificationType,
                    $dueDate->format('Y-m-d')
                ]);
            }

            if ($check->fetch()) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Insert Notifications and Tracking
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();

            try {
                foreach ($recipientIds as $recipientId) {
                    $insertNotification->execute([
                        (int) $recipientId,
                        $title,
                        $message,
                        $storedType
                    ]);
                }

                if ($usesNotificationKey) {
                    if ($usesSentAt) {
                        $track = $pdo->prepare(
                            'INSERT INTO maintenance_notification_tracking
                                (equipment_id, notification_key, sent_at)
                             VALUES (?, ?, NOW())'
                        );
                    } else {
                        $track = $pdo->prepare(
                            'INSERT INTO maintenance_notification_tracking
                                (equipment_id, notification_key)
                             VALUES (?, ?)'
                        );
                    }

                    $track->execute([
                        $equipmentId,
                        $notificationKey
                    ]);
                } else {
                    $track = $pdo->prepare(
                        'INSERT INTO maintenance_notification_tracking
                            (equipment_id, notification_type, notification_date)
                         VALUES (?, ?, ?)'
                    );

                    $track->execute([
                        $equipmentId,
                        $notificationType,
                        $dueDate->format('Y-m-d')
                    ]);
                }

                $pdo->commit();

            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }

    } catch (Throwable $exception) {
        /*
         * Notification generation must never stop the website.
         */
        return;
    }
}