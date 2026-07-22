<?php
/**
 * meter-readings/duplicates.php
 * Improvement 2: Duplicate Record Detection - admin/supervisor interface.
 *
 * api/save_reading.php already detects when a house has more than one
 * reading logged for the same date (a common field-sync scenario: two
 * readers cover the same house, or a reader's offline queue re-sends
 * after a device swap) and flags every reading in that group via
 * is_duplicate_flagged, without rejecting anyone's work. This screen is
 * where that gets resolved: readings are shown grouped by house + date,
 * and a reviewer either keeps one and discards the rest, or dismisses
 * the flag entirely (both readings were legitimate, e.g. a corrected
 * re-read).
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterSupervisor']);

$db = getDb();
$user = currentUser();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $action = $_POST['action'] ?? '';
    $groupIds = array_filter(array_map('intval', explode(',', $_POST['group_ids'] ?? '')));

    if (!$action || !$groupIds) {
        $error = 'Invalid request.';
    } else {
        try {
            $db->beginTransaction();
            $now = date('Y-m-d H:i:s');

            if ($action === 'dismiss') {
                // Not actually duplicates - clear the flag on every reading
                // in the group, keep them all.
                $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
                $stmt = $db->prepare(
                    "UPDATE MeterReadings SET is_duplicate_flagged = 0, duplicate_resolved_by = ?, duplicate_resolved_at = ?
                     WHERE reading_id IN ({$placeholders})"
                );
                $stmt->execute(array_merge([$user['id'], $now], $groupIds));
                logActivity($user['id'], 'Dismissed duplicate flag on meter reading(s) ' . implode(', ', $groupIds) . ' (kept all as legitimate)');
                $message = 'Marked as not a duplicate. All readings in this group were kept.';

            } elseif ($action === 'keep') {
                $keepId = (int) ($_POST['keep_id'] ?? 0);
                $discardIds = array_diff($groupIds, [$keepId]);

                if (!$keepId || !in_array($keepId, $groupIds, true)) {
                    throw new RuntimeException('Please choose which reading to keep.');
                }

                if ($discardIds) {
                    $placeholders = implode(',', array_fill(0, count($discardIds), '?'));
                    $db->prepare("DELETE FROM MeterReadings WHERE reading_id IN ({$placeholders})")
                       ->execute(array_values($discardIds));
                }
                $db->prepare('UPDATE MeterReadings SET is_duplicate_flagged = 0, duplicate_resolved_by = ?, duplicate_resolved_at = ? WHERE reading_id = ?')
                   ->execute([$user['id'], $now, $keepId]);

                logActivity($user['id'], "Resolved duplicate meter reading group: kept reading_id {$keepId}, "
                    . 'discarded ' . (implode(', ', $discardIds) ?: 'none'));
                $message = 'Resolved. Reading #' . $keepId . ' was kept' .
                    ($discardIds ? ' and ' . count($discardIds) . ' duplicate(s) removed.' : '.');
            } else {
                throw new RuntimeException('Unknown action.');
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Group all unresolved flagged readings by house + date.
$rows = $db->query(
    "SELECT r.*, h.house_number, w.ward_name, u.full_name AS reader_name
     FROM MeterReadings r
     JOIN Houses h ON h.house_id = r.house_id
     LEFT JOIN Wards w ON w.ward_id = h.ward_id
     LEFT JOIN Users u ON u.user_id = r.reader_id
     WHERE r.is_duplicate_flagged = 1 AND r.duplicate_resolved_by IS NULL
     ORDER BY r.house_id, r.date_taken, r.reading_id"
)->fetchAll();

$groups = [];
foreach ($rows as $row) {
    $key = $row['house_id'] . '|' . $row['date_taken'];
    $groups[$key][] = $row;
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-actions">
    <div>
        <h1>Duplicate Meter Readings</h1>
        <p class="subtitle"><?= count($groups) ?> group(s) awaiting review.</p>
    </div>
    <a class="btn secondary" href="list.php">Back to Readings</a>
</div>

<?php if ($message): ?><div class="alert success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (!$groups): ?>
    <div class="alert success">No duplicate readings are waiting for review.</div>
<?php endif; ?>

<?php foreach ($groups as $key => $group): $groupIds = array_column($group, 'reading_id'); ?>
    <div class="review-panel">
        <h3 style="margin-top:0;">
            House <?= htmlspecialchars($group[0]['house_number']) ?>
            <?php if ($group[0]['ward_name']): ?> &middot; <?= htmlspecialchars($group[0]['ward_name']) ?><?php endif; ?>
            &middot; <?= htmlspecialchars(date('d M Y', strtotime($group[0]['date_taken']))) ?>
        </h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="group_ids" value="<?= htmlspecialchars(implode(',', $groupIds)) ?>">
            <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr><th>Keep this one?</th><th>Previous</th><th>Current</th><th>Consumption</th><th>Reader</th><th>Captured (client ref)</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($group as $r): ?>
                        <tr>
                            <td><input type="radio" name="keep_id" value="<?= (int) $r['reading_id'] ?>" required></td>
                            <td><?= number_format((float) $r['previous_reading'], 2) ?></td>
                            <td><?= number_format((float) $r['current_reading'], 2) ?></td>
                            <td><?= number_format((float) $r['consumption'], 2) ?> m&sup3;</td>
                            <td><?= htmlspecialchars($r['reader_name'] ?? '-') ?></td>
                            <td style="font-family:monospace;font-size:0.8rem;"><?= htmlspecialchars($r['client_ref'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div style="display:flex;gap:10px;margin-top:12px;">
                <button type="submit" name="action" value="keep" class="btn">Keep Selected, Discard Others</button>
                <button type="submit" name="action" value="dismiss" class="btn secondary"
                        onclick="return confirm('Keep all readings in this group as legitimate (not duplicates)?');">
                    Not a Duplicate - Keep All
                </button>
            </div>
        </form>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
