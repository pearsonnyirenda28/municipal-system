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
    offlineNotice.style.display = navigator.onLine ? 'none' : 'block';
}
window.addEventListener('online', () => { updateOfflineBanner(); doSync(); });
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

/** Try the server first, fall back to the offline queue on any failure. */
async function saveReading(payload) {
    try {
        if (navigator.onLine) {
            const response = await fetch('/municipal-system/api/save_reading.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Server rejected the request');
            }
            showAlert(`Reading for house ${payload.houseNumber} saved successfully.` +
                (result.abnormal ? ' <strong>Warning: this reading looks unusually high.</strong>' : ''),
                result.abnormal ? 'warning' : 'success');
        } else {
            await queueRecord('reading', payload, window.CURRENT_USER_ID);
            showAlert(`No connection - reading for house ${payload.houseNumber} saved on this device ` +
                      `and will sync automatically once you are back online.`, 'warning');
        }
    } catch (err) {
        await queueRecord('reading', payload, window.CURRENT_USER_ID);
        showAlert(`Could not reach the server - reading for house ${payload.houseNumber} saved on this ` +
                  `device instead. It will sync automatically. (${err.message})`, 'warning');
        console.warn('Server save failed, queued offline instead:', err.message);
    }
}

/**
 * Push EVERY pending record on this device to its correct endpoint,
 * then report the outcome. Called by the Sync Now button, by the
 * 'online' event, and on page load when the browser reports a
 * connection.
 */
async function doSync() {
    if (!navigator.onLine) {
        showAlert('You are offline - nothing can be synced right now.', 'warning');
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