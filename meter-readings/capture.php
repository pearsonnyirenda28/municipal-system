<?php
/**
 * meter-readings/capture.php
 * Version 3.0 - Guided Field Operations: the meter reading capture page
 * is now a guided navigator through the reader's daily round (every
 * house in their assigned wards), not just a plain house picker.
 *
 * Design note: this page itself must be loaded once while online (it is a
 * normal PHP page, not yet a fully installable offline app-shell / service
 * worker - see the roadmap notes in README.md for that next step). Once
 * loaded, the current house's last reading is embedded directly into the
 * page as JSON, so "Save Reading" works completely offline afterwards.
 * The status buttons (Skip/Vacant/Locked/...) are NOT yet offline-capable
 * - see the note near those buttons below.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();
$user = currentUser();
$today = date('Y-m-d');

// Version 2.5: a MeterReader's round is scoped to their assigned wards.
// Admin gets an unrestricted round (every house) - null means "no scope".
$wardIds = $user['role'] === 'MeterReader' ? getAssignedWardIds($db, (int) $user['id']) : null;
$round = getReaderRound($db, $wardIds, (int) $user['id'], $today);

$statusLabels = [
    'Pending' => 'Pending', 'Completed' => 'Completed', 'Skipped' => 'Skipped',
    'DamagedMeter' => 'Damaged meter', 'Vacant' => 'Vacant', 'Locked' => 'Locked premises',
    'IllegalConnection' => 'Illegal connection', 'RevisitRequired' => 'Revisit required',
];

// ---- Work out which house we're looking at (resume / jump / prev / next) ----
$requestedId = (int) ($_GET['house_id'] ?? 0);
$currentIndex = null;
if ($requestedId) {
    foreach ($round as $i => $h) {
        if ((int) $h['house_id'] === $requestedId) { $currentIndex = $i; break; }
    }
}
if ($currentIndex === null && $round) {
    // Resume logic: jump to the first house that isn't done yet today;
    // if the whole round is complete, land on the first house instead.
    $currentIndex = 0;
    foreach ($round as $i => $h) {
        if ($h['visit_status'] === 'Pending') { $currentIndex = $i; break; }
    }
    // Reflect the resumed house in the URL so it's bookmarkable / back-button-friendly.
    header('Location: capture.php?house_id=' . (int) $round[$currentIndex]['house_id']);
    exit;
}

$current = $currentIndex !== null ? $round[$currentIndex] : null;
$prevHouse = ($currentIndex !== null && $currentIndex > 0) ? $round[$currentIndex - 1] : null;
$nextHouse = ($currentIndex !== null && $currentIndex < count($round) - 1) ? $round[$currentIndex + 1] : null;

$completedCount = count(array_filter($round, fn($h) => $h['visit_status'] !== 'Pending'));

// Previous meter reading for the current house, for the auto-fill field.
$previousReading = 0;
if ($current) {
    $stmt = $db->prepare(
        'SELECT current_reading FROM MeterReadings WHERE house_id = :id ORDER BY date_taken DESC, reading_id DESC LIMIT 1'
    );
    $stmt->execute(['id' => $current['house_id']]);
    $previousReading = $stmt->fetchColumn() ?: 0;
}

require __DIR__ . '/../includes/header.php';
?>

<h1>Capture Meter Reading</h1>
<p class="subtitle">Guided round: work through your assigned houses in order, or jump straight to one.
    Reading capture works offline; the status buttons below currently need a connection.</p>

<div id="offlineNotice" class="alert warning" style="display:none;">
    You are currently offline. This reading will be saved on this device and synced automatically
    once your connection is back.
</div>
<div id="pendingNotice" class="alert warning" style="display:none;"></div>
<div id="formAlert"></div>

<?php if (!$round): ?>
    <div class="alert warning">
        <?= $user['role'] === 'MeterReader'
            ? "You aren't assigned to any ward yet, so there's no round to show. Ask an Administrator to assign you to a ward under Wards &rarr; Assign Readers."
            : "No houses have been added yet." ?>
    </div>
<?php else: ?>

    <div class="form-panel" style="max-width:none;">
        <p style="margin:0 0 8px;font-size:0.9rem;">
            <strong>House <?= $currentIndex + 1 ?> of <?= count($round) ?> today</strong>
            &mdash; <?= $completedCount ?> visited, <?= count($round) - $completedCount ?> remaining
        </p>
        <div style="background:#E1E6ED;border-radius:8px;height:12px;overflow:hidden;margin-bottom:16px;">
            <div style="background:#2C7873;height:100%;width:<?= count($round) ? round($completedCount / count($round) * 100) : 0 ?>%;"></div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
            <?php if ($prevHouse): ?>
                <a class="btn secondary" href="?house_id=<?= (int) $prevHouse['house_id'] ?>">&laquo; Previous</a>
            <?php else: ?>
                <span class="btn secondary" style="opacity:0.4;pointer-events:none;">&laquo; Previous</span>
            <?php endif; ?>
            <?php if ($nextHouse): ?>
                <a class="btn secondary" href="?house_id=<?= (int) $nextHouse['house_id'] ?>">Next &raquo;</a>
            <?php else: ?>
                <span class="btn secondary" style="opacity:0.4;pointer-events:none;">Next &raquo;</span>
            <?php endif; ?>

            <select id="jumpSelect" onchange="if(this.value) window.location.href='?house_id='+this.value;" style="max-width:320px;">
                <option value="">Jump to house...</option>
                <?php foreach ($round as $h): ?>
                    <option value="<?= (int) $h['house_id'] ?>" <?= $current && $current['house_id'] === $h['house_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($h['house_number']) ?><?= $h['street'] ? ', ' . htmlspecialchars($h['street']) : '' ?>
                        &mdash; <?= htmlspecialchars($statusLabels[$h['visit_status']] ?? $h['visit_status']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($current): ?>
            <h2 style="margin-bottom:4px;">
                House <?= htmlspecialchars($current['house_number']) ?><?= $current['street'] ? ', ' . htmlspecialchars($current['street']) : '' ?>
            </h2>
            <p style="margin-top:0;color:#5B6472;">
                <?= htmlspecialchars($current['ward_name'] ?? 'No ward') ?> &mdash;
                <span class="badge <?= $current['visit_status'] === 'Completed' ? 'paid' : ($current['visit_status'] === 'Pending' ? 'unpaid' : 'offline') ?>">
                    <?= htmlspecialchars($statusLabels[$current['visit_status']] ?? $current['visit_status']) ?>
                </span>
            </p>

            <label for="previousReading">Previous reading (m&sup3;)</label>
            <input type="text" id="previousReading" readonly value="<?= htmlspecialchars((string) $previousReading) ?>">

            <label for="currentReading">Current reading (m&sup3;)</label>
            <input type="number" id="currentReading" step="0.01">

            <button id="btnSave" class="btn amber" type="button">Save Reading &amp; Continue</button>
            <button id="btnSync" class="btn secondary" type="button">Sync Now (<span id="pendingCount">0</span> pending)</button>

            <hr style="margin:22px 0;border:none;border-top:1px solid #E1E6ED;">

            <p style="font-weight:bold;margin-bottom:10px;">Not able to read this meter?</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php foreach (['Skipped' => 'Skip', 'Vacant' => 'Vacant', 'Locked' => 'Locked Premises',
                                'IllegalConnection' => 'Illegal Connection', 'RevisitRequired' => 'Revisit Required'] as $statusValue => $label): ?>
                    <form method="post" action="mark_status.php" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="house_id" value="<?= (int) $current['house_id'] ?>">
                        <input type="hidden" name="status" value="<?= $statusValue ?>">
                        <input type="hidden" name="next_house_id" value="<?= $nextHouse ? (int) $nextHouse['house_id'] : '' ?>">
                        <button type="submit" class="btn secondary"><?= $label ?></button>
                    </form>
                <?php endforeach; ?>
                <a class="btn secondary" href="/municipal-system/damaged-meters/report.php?house_id=<?= (int) $current['house_id'] ?>">Report Damaged Meter</a>
            </div>
            <p style="font-size:0.8rem;color:#5B6472;margin-top:10px;">
                These status buttons need a live connection right now - unlike meter readings, they
                aren't yet stored offline for later sync.
            </p>
        <?php endif; ?>
    </div>

<div id="meterConfig" style="display:none"
     data-user-id="<?= (int) $user['id'] ?>"
     data-csrf-token="<?= htmlspecialchars(csrfToken()) ?>"
     data-current-house='<?= htmlspecialchars(json_encode($current ? [
         'id' => (int) $current['house_id'],
         'number' => $current['house_number'],
         'nextHouseId' => $nextHouse ? (int) $nextHouse['house_id'] : null,
     ] : null), ENT_QUOTES) ?>'></div>
<script src="/municipal-system/assets/js/offline-db.js"></script>
<script src="/municipal-system/assets/js/meter-reading.js"></script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
