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
    offlineFineNotice.style.display = navigator.onLine ? 'none' : 'block';
}
window.addEventListener('online', () => { updateFineOfflineBanner(); syncAllPending(); });
window.addEventListener('offline', updateFineOfflineBanner);

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

async function saveFineRecord(payload) {
    try {
        if (navigator.onLine) {
            const response = await fetch('/municipal-system/api/save_fine.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Server rejected the request');
            }
            showFineAlert(`Fine <strong>${result.fine_number}</strong> saved successfully.` +
                (result.duplicate_flagged ? ' <em>(Flagged for admin review as a possible duplicate.)</em>' : ''),
                'success');
            fineForm.reset();
        } else {
            await queueRecord('fine', payload, window.CURRENT_USER_ID);
            showFineAlert('No connection - this fine has been saved on this device and will sync ' +
                          'automatically once you are back online.', 'warning');
            fineForm.reset();
        }
    } catch (err) {
        await queueRecord('fine', payload, window.CURRENT_USER_ID);
        showFineAlert(`Could not reach the server - the fine was saved on this device instead. ` +
                      `It will sync automatically. (${err.message})`, 'warning');
        fineForm.reset();
        console.warn('Server save failed, queued offline instead:', err.message);
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
    if (!navigator.onLine) {
        showFineAlert('You are offline - nothing can be synced right now.', 'warning');
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