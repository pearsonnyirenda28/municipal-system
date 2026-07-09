<?php
/**
 * meter-readings/capture.php
 * "Capture Meter Reading" and "Capture Data Offline" use cases.
 *
 * Design note: this page itself must be loaded once while online (it is a
 * normal PHP page, not yet a fully installable offline app-shell / service
 * worker - see Chapter 7, Section 5.0, Future Plans, for that next step).
 * Once loaded, every house's last reading is embedded directly into the
 * page as JSON, so the "Save Reading" action below works completely
 * offline afterwards, exactly as designed in Activity 4, Section 6.4.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireRole(['Admin', 'MeterReader']);

$db = getDb();

// Every house plus its most recent reading, embedded for offline use.
$houses = $db->query(
    'SELECT h.house_id, h.house_number, h.ward,
            COALESCE(
                (SELECT current_reading FROM MeterReadings r
                 WHERE r.house_id = h.house_id
                 ORDER BY r.date_taken DESC, r.reading_id DESC LIMIT 1),
                0
            ) AS last_reading
     FROM Houses h
     ORDER BY h.house_number'
)->fetchAll();

require __DIR__ . '/../includes/header.php';
?>

<h1>Capture Meter Reading</h1>
<p class="subtitle">Works even if you lose signal partway through your round - readings are saved
    to this device and uploaded automatically once you are back online.</p>

<div id="offlineNotice" class="alert warning" style="display:none;">
    You are currently offline. This reading will be saved on this device and synced automatically
    once your connection is back.
</div>
<div id="pendingNotice" class="alert warning" style="display:none;"></div>
<div id="formAlert"></div>

<div class="form-panel">
    <label for="houseSelect">House number</label>
    <select id="houseSelect">
        <option value="">-- Select a house --</option>
        <?php foreach ($houses as $h): ?>
            <option value="<?= (int) $h['house_id'] ?>"
                    data-previous="<?= htmlspecialchars((string) $h['last_reading']) ?>"
                    data-house-number="<?= htmlspecialchars($h['house_number']) ?>">
                <?= htmlspecialchars($h['house_number']) ?> (<?= htmlspecialchars($h['ward']) ?>)
            </option>
        <?php endforeach; ?>
    </select>

    <label for="previousReading">Previous reading (m&sup3;)</label>
    <input type="text" id="previousReading" readonly>

    <label for="currentReading">Current reading (m&sup3;)</label>
    <input type="number" id="currentReading" step="0.01">

    <button id="btnSave" class="btn amber" type="button">Save Reading</button>
    <button id="btnSync" class="btn secondary" type="button">Sync Now (<span id="pendingCount">0</span> pending)</button>
</div>

<script>
window.CURRENT_USER_ID = <?= (int) currentUser()['id'] ?>;
window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
</script>
<script src="/municipal-system/assets/js/offline-db.js"></script>
<script src="/municipal-system/assets/js/meter-reading.js"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
