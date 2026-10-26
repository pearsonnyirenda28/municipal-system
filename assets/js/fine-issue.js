/**
 * assets/js/fine-issue.js
 * Upgrades the Issue a Fine form to work offline, the same way meter
 * reading capture already did. If this script fails to load for any
 * reason, the form still works exactly as before (a normal server-side
 * POST).
 *
 * Sync Now pushes EVERY pending record on this device, not just fines -
 * the shared queue in offline-queue.js routes each record to its own
 * endpoint (save_reading / mark_visit / save_fine). The counter and
 * messages below show the total pending, not just the fine-type subset,
 * so the number you see matches what Sync Now will actually push.
 */

const fineForm = document.getElementById('fineForm');
const btnSaveFine = document.getElementById('btnSaveFine');
const btnSyncFines = document.getElementById('btnSyncFines');
const pendingFineCountEl = document.getElementById('pendingFineCount');
const offlineFineNotice = document.getElementById('offlineFineNotice');
const pendingFineNotice = document.getElementById('pendingFineNotice');
const fineFormAlert = document.getElementById('fineFormAlert');

function showFineAlert(message, type) {
    fineFormAlert.innerHTML = `<div class="alert ${type}">${message}</div>`;
}

/**
 * Refresh the "X pending" counter and the notice above the form. Shows
 * the TOTAL across every queued type (readings, visit statuses, fines),
 * because that is what Sync Now will push. If there is more than just
 * fines waiting, the notice says so, so a user who only came here to
 * issue a fine isn't surprised by a larger number.
 */
async function refreshPendingCount() {
    const total = await countPending();
    const fines = await countPending('fine');

    pendingFineCountEl.textContent = total;
    pendingFineNotice.style.display = total > 0 ? 'block' : 'none';

    if (total > 0) {
        const others = total - fines;
        let text = `${total} record(s) waiting to be synced.`;
        if (fines > 0 && others > 0) {
            text += ` (${fines} fine${fines === 1 ? '' : 's'}, ${others} reading/visit record${others === 1 ? '' : 's'}.)`;
        } else if (fines === 0 && others > 0) {
            text += ` All are meter-reading or visit-status records captured on this device.`;
        }
        pendingFineNotice.textContent = text;
    }
}

function updateFineOfflineBanner() {
    const reachable = typeof window.getConnectivityStatus === 'function'
        ? window.getConnectivityStatus() === 'reachable'
        : navigator.onLine;
    offlineFineNotice.style.display = reachable ? 'none' : 'block';
}
window.addEventListener('online', () => { updateFineOfflineBanner(); syncAllPending(); });
window.addEventListener('offline', updateFineOfflineBanner);
window.addEventListener('connectivitychange', updateFineOfflineBanner);
window.addEventListener('queuesynced', () => { refreshPendingCount(); });

if (fineForm) {
    fineForm.addEventListener('submit', async function (event) {
        event.preventDefault();

        const offenderName = document.getElementById('offender_name').value.trim();
        const offenceType = document.getElementById('offence_type').value;
        const houseNumber = document.getElementById('house_number').value.trim();
        const vehiclePlate = document.getElementById('vehicle_plate').value.trim();
        const amount = document.getElementById('amount').value;
        const caseIdField = document.getElementById('case_id');

        // Same rules as validateFineInput() in fines/issue.php.
        if (!offenderName) { showFineAlert('Offender name is required.', 'error'); return; }
        if (!offenceType) { showFineAlert('Please select an offence type.', 'error'); return; }
        if (!amount || isNaN(amount) || parseFloat(amount) <= 0) {
            showFineAlert('Fine amount must be a number greater than zero.', 'error'); return;
        }
        if (!houseNumber && !vehiclePlate) {
            showFineAlert('Provide either a house number or a vehicle plate.', 'error'); return;
        }

        const payload = {
            clientRef: makeClientRef(),
            offenderName, offenceType, houseNumber, vehiclePlate,
            amount: parseFloat(amount),
            caseId: caseIdField ? parseInt(caseIdField.value, 10) : null,
            dateIssued: new Date().toISOString().slice(0, 19).replace('T', ' '),
        };

        btnSaveFine.disabled = true;
        await saveFineRecord(payload);
        btnSaveFine.disabled = false;
        await refreshPendingCount();
    });
}

/**
 * Version 4.5: local-first, same reasoning as meter-reading.js -
 * queue on this device FIRST (that is what "saved" means from here),
 * then try an opportunistic push only when connectivity.js's real
 * reachability check says the server actually answers right now.
 * Whether the server flags this as a possible duplicate is - like the
 * meter-reading "abnormal" warning - only knowable once the queued
 * record actually reaches it, so that surfaces from the sync result
 * (reportDuplicateFines() below) rather than at save-time.
 */
async function saveFineRecord(payload) {
    try {
        await queueRecord('fine', payload, window.CURRENT_USER_ID);
    } catch (err) {
        showFineAlert(`Could not save this fine on this device (${err.message}). Please try again.`, 'error');
        console.error('Local save failed:', err);
        return;
    }

    showFineAlert('Fine saved on this device and queued to sync.', 'success');
    fineForm.reset();

    const reachable = typeof window.getConnectivityStatus === 'function'
        && window.getConnectivityStatus() === 'reachable';
    if (reachable && typeof window.syncOfflineQueue === 'function') {
        window.syncOfflineQueue().then(reportDuplicateFines); // opportunistic - doesn't block the UI
    }
}

function reportDuplicateFines(result) {
    if (result.duplicateFines && result.duplicateFines.length) {
        showFineAlert(`<em>Flagged for admin review as a possible duplicate: ${result.duplicateFines.join(', ')}.</em>`, 'warning');
    }
}

/**
 * Push EVERY pending record on this device to its correct endpoint.
 * syncOfflineQueue() walks the whole queue and routes each record by
 * type - fines to save_fine.php, readings to save_reading.php, visit
 * statuses to mark_visit.php. Called by the Sync Now button, by the
 * 'online' event, and on page load when the browser reports a
 * connection.
 */
async function syncAllPending() {
    const reachable = typeof window.getConnectivityStatus === 'function'
        ? window.getConnectivityStatus() === 'reachable'
        : navigator.onLine;
    if (!reachable) {
        showFineAlert('The server is not reachable right now - nothing can be synced.', 'warning');
        return;
    }

    const originalLabel = btnSyncFines ? btnSyncFines.innerHTML : '';
    if (btnSyncFines) {
        btnSyncFines.disabled = true;
        btnSyncFines.innerHTML = 'Syncing...';
    }

    try {
        const result = await syncOfflineQueue();
        await refreshPendingCount();
        reportDuplicateFines(result);

        if (result.failed > 0) {
            showFineAlert(
                `${result.synced} synced, <strong>${result.failed} failed</strong>` +
                `${result.skipped ? `, ${result.skipped} skipped` : ''}. ` +
                `Open the browser console (F12) for the exact error from the server.`,
                'error'
            );
        } else if (result.synced > 0) {
            showFineAlert(`${result.synced} record(s) synced successfully.`, 'success');
        } else if (result.skipped > 0) {
            showFineAlert(`${result.skipped} record(s) have already failed too many times and were skipped. ` +
                          `See the console for details.`, 'error');
        } else {
            showFineAlert('Nothing to sync - the queue is already empty.', 'success');
        }
    } finally {
        if (btnSyncFines) {
            btnSyncFines.disabled = false;
            btnSyncFines.innerHTML = originalLabel;
        }
    }
}

if (btnSyncFines) {
    btnSyncFines.addEventListener('click', syncAllPending);
}

updateFineOfflineBanner();
refreshPendingCount();
if (navigator.onLine) syncAllPending();