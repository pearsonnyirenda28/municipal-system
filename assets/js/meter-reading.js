/**
 * assets/js/meter-reading.js
 * Version 3.0: the page is a guided round now, not a free house picker,
 * so the "current house" comes from window.CURRENT_HOUSE (set below from
 * the #meterConfig data attributes rendered by capture.php - not an
 * inline <script> block, since the app's CSP (script-src 'self', no
 * unsafe-inline) blocks those) instead of a <select>'s chosen option.
 * Saving a reading auto-advances to the next house in the round.
 */

(function () {
    const cfg = document.getElementById('meterConfig');
    window.CURRENT_USER_ID = cfg ? parseInt(cfg.dataset.userId, 10) : null;
    window.CSRF_TOKEN = cfg ? cfg.dataset.csrfToken : null;
    window.CURRENT_HOUSE = cfg ? JSON.parse(cfg.dataset.currentHouse || 'null') : null;
})();

const previousField = document.getElementById('previousReading');
const currentField = document.getElementById('currentReading');
const btnSave = document.getElementById('btnSave');
const btnSync = document.getElementById('btnSync');
const pendingCountEl = document.getElementById('pendingCount');
const offlineNotice = document.getElementById('offlineNotice');
const pendingNotice = document.getElementById('pendingNotice');
const formAlert = document.getElementById('formAlert');

function showAlert(message, type) {
    formAlert.innerHTML = `<div class="alert ${type}">${message}</div>`;
}

function clearFieldError() {
    currentField.classList.remove('field-error');
}

async function refreshPendingCount() {
    const count = await countPendingReadings();
    pendingCountEl.textContent = count;
    pendingNotice.style.display = count > 0 ? 'block' : 'none';
    if (count > 0) {
        pendingNotice.textContent = `${count} reading(s) captured offline are waiting to be synced.`;
    }
}

function updateOfflineBanner() {
    offlineNotice.style.display = navigator.onLine ? 'none' : 'block';
}
window.addEventListener('online', () => { updateOfflineBanner(); syncPendingReadings(); });
window.addEventListener('offline', updateOfflineBanner);

if (btnSave) {
    btnSave.addEventListener('click', async function () {
        const house = window.CURRENT_HOUSE;
        const previous = parseFloat(previousField.value);
        const current = parseFloat(currentField.value);

        if (!house) {
            showAlert('No house is loaded on this page.', 'error');
            return;
        }
        if (isNaN(current)) {
            currentField.classList.add('field-error');
            showAlert('Please enter a valid numeric reading.', 'error');
            return;
        }
        if (current < previous) {
            currentField.classList.add('field-error');
            showAlert('Current reading cannot be lower than the previous reading.', 'error');
            return;
        }
        clearFieldError();

        const record = {
            clientRef: makeClientRef(),
            houseId: house.id,
            houseNumber: house.number,
            previous,
            current,
            readerId: window.CURRENT_USER_ID,
            dateTaken: new Date().toISOString().slice(0, 10),
            synced: false,
        };

        await saveReading(record);
        await refreshPendingCount();

        // Guided round: move on to the next house automatically. A short
        // pause so the reader actually sees the confirmation message
        // before the page navigates away.
        if (house.nextHouseId) {
            setTimeout(() => {
                window.location.href = '?house_id=' + house.nextHouseId;
            }, 900);
        } else {
            showAlert('Reading saved. That was the last house in today\'s round.', 'success');
        }
    });
}

/**
 * Matches Chapter 5, Section 3.2: try the server first, fall back to
 * IndexedDB (and to the field-visible offline banner) on any failure.
 */
async function saveReading(record) {
    try {
        if (navigator.onLine) {
            const response = await fetch('/municipal-system/api/save_reading.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify(record),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Server rejected the request');
            }
            record.synced = true;
            await saveOfflineReading(record); // kept for the reader's own history/audit trail
            showAlert(`Reading for house ${record.houseNumber} saved successfully.` +
                (result.abnormal ? ' <strong>Warning: this reading looks unusually high.</strong>' : ''),
                result.abnormal ? 'warning' : 'success');
        } else {
            await saveOfflineReading(record);
            showAlert(`No connection - reading for house ${record.houseNumber} saved on this device ` +
                      `and will sync automatically once you are back online.`, 'warning');
        }
    } catch (err) {
        await saveOfflineReading(record);
        showAlert(`Could not reach the server - reading for house ${record.houseNumber} saved on this ` +
                  `device instead. It will sync automatically. (${err.message})`, 'warning');
        console.warn('Server save failed, stored locally instead:', err.message);
    }
}

async function syncPendingReadings() {
    if (!navigator.onLine) return;
    const pending = await getUnsyncedReadings();
    let lastError = null;
    for (const record of pending) {
        try {
            const response = await fetch('/municipal-system/api/save_reading.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify(record),
            });
            const result = await response.json();
            if (response.ok && result.success) {
                await markReadingSynced(record.clientRef);
            }
        } catch (err) {
            console.warn('Sync attempt failed for', record.clientRef, err.message);
            lastError = err.message;
            break; // stop trying if the connection drops again mid-sync
        }
    }
    await refreshPendingCount();

    // Improvement 17: surface a persistent sync problem as a server-side
    // notification (visible to Admin/MeterSupervisor), not just a
    // console warning nobody but this device will ever see.
    if (lastError) {
        const stillPending = await countPendingReadings();
        fetch('/municipal-system/api/report_sync_failure.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
            credentials: 'same-origin',
            body: JSON.stringify({ pendingCount: stillPending, reason: lastError }),
        }).catch(() => {}); // best-effort - don't let a failed report itself throw
    }
}

if (btnSync) {
    btnSync.addEventListener('click', syncPendingReadings);
}

// Initial state on page load
updateOfflineBanner();
refreshPendingCount();
if (navigator.onLine) syncPendingReadings();
