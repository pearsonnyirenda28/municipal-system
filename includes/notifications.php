<?php
/**
 * includes/notifications.php
 * Improvement 17: Notifications.
 *
 * Deliberately no Notifications table / read-tracking: every notification
 * here is a live count derived from data that already has its own "done"
 * state (a fine becomes Paid, a damaged meter becomes Fixed, a reading
 * gets reviewed) - so there's nothing to separately mark as read, and no
 * risk of a stale notification outliving the thing it was about.
 * Sync failures are the one event-based exception, sourced from the
 * ActivityLogs entries api/report_sync_failure.php writes.
 *
 * @return array<int, array{type:string, message:string, link:string, count:int}>
 */
function getNotifications(PDO $db, array $user): array
{
    $role = $user['role'];
    $notifications = [];

    $seesPolice = in_array($role, ['Admin', 'PoliceSupervisor', 'Officer', 'Clerk'], true);
    $seesMeter  = in_array($role, ['Admin', 'MeterSupervisor', 'MeterReader'], true);

    if ($seesPolice) {
        $sevenDaysAgo = DB_DRIVER === 'mysql' ? 'DATE_SUB(CURDATE(), INTERVAL 7 DAY)' : "date('now', '-7 days')";
        $unpaidOld = (int) $db->query(
            "SELECT COUNT(*) FROM Fines WHERE payment_status = 'Unpaid' AND date_issued <= {$sevenDaysAgo}"
        )->fetchColumn();
        if ($unpaidOld > 0) {
            $notifications[] = [
                'type' => 'unpaid_fines',
                'message' => "{$unpaidOld} fine(s) unpaid for more than 7 days",
                'link' => '/municipal-system/fines/list.php?status=Unpaid',
                'count' => $unpaidOld,
            ];
        }
    }

    if ($seesMeter) {
        $abnormal = (int) $db->query(
            "SELECT COUNT(*) FROM MeterReadings WHERE is_abnormal = 1 AND " . dbWithinLast('date_taken', 14, 'DAY')
        )->fetchColumn();
        if ($abnormal > 0) {
            $notifications[] = [
                'type' => 'abnormal_readings',
                'message' => "{$abnormal} abnormal meter reading(s) in the last 14 days",
                'link' => '/municipal-system/meter-readings/list.php?abnormal=1',
                'count' => $abnormal,
            ];
        }

        $pendingRepairs = (int) $db->query(
            "SELECT COUNT(*) FROM DamagedMeters WHERE repair_status != 'Fixed'"
        )->fetchColumn();
        if ($pendingRepairs > 0) {
            $notifications[] = [
                'type' => 'pending_repairs',
                'message' => "{$pendingRepairs} damaged meter report(s) awaiting repair",
                'link' => '/municipal-system/damaged-meters/list.php',
                'count' => $pendingRepairs,
            ];
        }

        if (in_array($role, ['Admin', 'MeterSupervisor'], true)) {
            $concatExpr = DB_DRIVER === 'mysql' ? "CONCAT(house_id, '|', date_taken)" : "house_id || '|' || date_taken";
            $duplicates = (int) $db->query(
                "SELECT COUNT(DISTINCT {$concatExpr}) FROM MeterReadings WHERE is_duplicate_flagged = 1 AND duplicate_resolved_by IS NULL"
            )->fetchColumn();
            if ($duplicates > 0) {
                $notifications[] = [
                    'type' => 'duplicate_readings',
                    'message' => "{$duplicates} duplicate reading group(s) awaiting review",
                    'link' => '/municipal-system/meter-readings/duplicates.php',
                    'count' => $duplicates,
                ];
            }

            $syncFailures = (int) $db->query(
                "SELECT COUNT(*) FROM ActivityLogs WHERE action_description LIKE 'SYNC_FAILURE:%' AND " . dbWithinLast('date_time', 1, 'DAY')
            )->fetchColumn();
            if ($syncFailures > 0) {
                $notifications[] = [
                    'type' => 'sync_failures',
                    'message' => "{$syncFailures} synchronization failure(s) reported in the last 24 hours",
                    'link' => '/municipal-system/users/login_history.php',
                    'count' => $syncFailures,
                ];
            }
        }
    }

    return $notifications;
}

/** Renders a bell icon with a badge count and a click-to-open dropdown listing each notification. */
function renderNotificationBell(array $notifications, int $count, string $idSuffix): string
{
    $dropdownId = 'notifDropdown-' . $idSuffix;
    $html = '<div class="notif-bell-wrap">';
    $html .= '<button type="button" class="notif-bell" onclick="'
           . "document.getElementById('{$dropdownId}').classList.toggle('open')\" aria-label=\"Notifications\">"
           . '&#128276;'
           . ($count > 0 ? '<span class="notif-badge">' . ($count > 99 ? '99+' : $count) . '</span>' : '')
           . '</button>';
    $html .= '<div id="' . $dropdownId . '" class="notif-dropdown">';
    if (!$notifications) {
        $html .= '<div class="notif-empty">No notifications right now.</div>';
    } else {
        foreach ($notifications as $n) {
            $html .= '<a href="' . htmlspecialchars($n['link']) . '" class="notif-item">' . htmlspecialchars($n['message']) . '</a>';
        }
    }
    $html .= '</div></div>';
    return $html;
}
