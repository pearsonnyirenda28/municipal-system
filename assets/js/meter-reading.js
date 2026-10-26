/**
 * assets/js/meter-reading.js
 * Capture page logic. The "current house" comes from window.CURRENT_HOUSE
 * (set server-side in capture.php). Reading capture, visit-status marks
 * and any fines issued on this device all route through the shared
 * offline queue in offline-queue.js.
 *
 * Sync Now pushes EVERY pending record on this device, not just meter
 * readings - syncOfflineQueue() routes each record to its own endpoint
 * (save_reading / mark_visit / save_fine). The counter shows the total
 * pending across all types, matching what the button will push.
 */

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

/**
 * Combined "pending" figure across every queued type (readings, visit
 * statuses, fines) - one number, matching what Sync Now will push.
 */
async function refreshPendingCount() {
    const count = await countPending();
    pendingCountEl.textContent = count;
    pendingNotice.style.display = count > 0 ? 'block' : 'none';
    if (count > 0) {
        pendingNotice.textContent = `${count} record(s) captured offline are waiting to be synced.`;
    }
}

function updateOfflineBanner() {
    const reachable = typeof window.getConnectivityStatus === 'function'
        ? window.getConnectivityStatus() === 'reachable'
        : navigator.onLine;
    offlineNotice.style.display = reachable ? 'none' : 'block';
}
window.addEventListener('online', () => { updateOfflineBanner(); doSync(); });
window.addEventListener('offline', updateOfflineBanner);
window.addEventListener('connectivitychange', updateOfflineBanner);
window.addEventListener('queuesynced', () => { refreshPendingCount(); });

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

        const payload = {
            clientRef: makeClientRef(),
            houseId: house.id,
            houseNumber: house.number,
            previous,
            current,
            dateTaken: new Date().toISOString().slice(0, 10),
        };

        await saveReading(payload);
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
 * Version 4.5: local-first. Every reading is committed to this
 * device's local store FIRST, synchronously with the save action -
 * that IS "saved" from here on (the durability guarantee: a
 * successful save means safely persisted on this device, not
 * necessarily synced yet). Only once that has succeeded does this try
 * an opportunistic push, and only when connectivity.js's own
 * reachability check says the server actually answers right now - not
 * navigator.onLine, which just means some network interface is up.
 * Previously this tried the network FIRST and only queued locally on
 * failure or while navigator.onLine was already false - a crash, a
 * closed tab, or simply a slow connection during that network attempt
 * could lose a reading that was never durably written anywhere.
 * Because of this, whether a reading looks abnormally high is no
 * longer something this function can report immediately - that check
 * compares against this house's recent average and only happens
 * server-side, once the queued record actually reaches it (see
 * doSync() below, which is where that warning now surfaces).
 */
async function saveReading(payload) {
    try {
        await queueRecord('reading', payload, window.CURRENT_USER_ID);
    } catch (err) {
        // The local store itself failed (IndexedDB unavailable, private
        // browsing, storage full, ...) - there is nowhere left to fall
        // back to, so this reading genuinely was not saved.
        showAlert(`Could not save this reading on this device (${err.message}). Please try again.`, 'error');
        console.error('Local save failed:', err);
        return;
    }

    showAlert(`Reading for house ${payload.houseNumber} saved on this device and queued to sync.`, 'success');

    const reachable = typeof window.getConnectivityStatus === 'function'
        && window.getConnectivityStatus() === 'reachable';
    if (reachable && typeof window.syncOfflineQueue === 'function') {
        window.syncOfflineQueue().then(reportAbnormalReadings); // opportunistic - doesn't block the UI
    }
}

/** Shared by the opportunistic sync in saveReading() and the explicit Sync Now button below - just the abnormal-reading warning, since each caller already has its own synced/failed messaging. */
function reportAbnormalReadings(result) {
    if (result.abnormalReadings && result.abnormalReadings.length) {
        showAlert(`<strong>Warning:</strong> reading(s) for house ${result.abnormalReadings.join(', ')} ` +
                  `looked unusually high compared to recent consumption.`, 'warning');
    }
}

/**
 * Push EVERY pending record on this device to its correct endpoint,
 * then report the outcome. Called by the Sync Now button, by the
 * 'online' event, and on page load when the browser reports a
 * connection.
 */
async function doSync() {
    const reachable = typeof window.getConnectivityStatus === 'function'
        ? window.getConnectivityStatus() === 'reachable'
        : navigator.onLine;
    if (!reachable) {
        showAlert('The server is not reachable right now - nothing can be synced.', 'warning');
        return;
    }

    const originalLabel = btnSync ? btnSync.innerHTML : '';
    if (btnSync) {
        btnSync.disabled = true;
        btnSync.innerHTML = 'Syncing...';
    }

    try {
        const result = await syncOfflineQueue();
        await refreshPendingCount();
        reportAbnormalReadings(result);

        if (result.failed > 0) {
            showAlert(
                `${result.synced} synced, <strong>${result.failed} failed</strong>` +
                `${result.skipped ? `, ${result.skipped} skipped` : ''}. ` +
                `Open the browser console (F12) for the exact error from the server.`,
                'error'
            );
        } else if (result.synced > 0) {
            showAlert(`${result.synced} record(s) synced successfully.`, 'success');
        } else if (result.skipped > 0) {
            showAlert(`${result.skipped} record(s) have already failed too many times and were skipped. ` +
                      `See the console for details.`, 'error');
        } else {
            showAlert('Nothing to sync - the queue is already empty.', 'success');
        }
    } finally {
        if (btnSync) {
            btnSync.disabled = false;
            btnSync.innerHTML = originalLabel;
        }
    }
}

if (btnSync) {
    btnSync.addEventListener('click', doSync);
}

// Initial state on page load
updateOfflineBanner();
refreshPendingCount();
if (navigator.onLine) doSync();