/**
 * assets/js/fine-issue.js
 * Version 3.5: upgrades the Issue a Fine form to work offline, the same
 * way meter reading capture already did. If this script fails to load
 * for any reason, the form still works exactly as before (a normal
 * server-side POST) - this only intercepts submission when it runs.
 */

const fineForm = document.getElementById('fineForm');
const btnSaveFine = document.getElementById('btnSaveFine');
const btnSyncFines = document.getElementById('btnSyncFines');
const pendingFineCountEl = document.getElementById('pendingFineCount');
const offlineFineNotice = document.getElementById('offlineFineNotice');
const pendingFineNotice = document.getElementById('pendingFineNotice');
const fineFormAlert = document.getElementById('fineFormAlert');

// Read config values securely from data attributes to satisfy CSP
const configEl = document.getElementById('fineAppConfig');
const currentUserId = configEl ? parseInt(configEl.dataset.currentUserId, 10) : 0;
const csrfToken = configEl ? configEl.dataset.csrfToken : '';

function showFineAlert(message, type) {
    fineFormAlert.innerHTML = `<div class="alert ${type}">${message}</div>`;
}

async function refreshPendingFineCount() {
    const count = await countPending('fine');
    pendingFineCountEl.textContent = count;
    pendingFineNotice.style.display = count > 0 ? 'block' : 'none';
    if (count > 0) {
        pendingFineNotice.textContent = `${count} fine(s) issued offline are waiting to be synced.`;
    }
}

function updateFineOfflineBanner() {
    offlineFineNotice.style.display = navigator.onLine ? 'none' : 'block';
}
window.addEventListener('online', () => { updateFineOfflineBanner(); syncFines(); });
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

        // Same rules as validateFineInput() in fines/issue.php (Chapter 5, Section 2.2).
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
        await refreshPendingFineCount();
    });
}

async function saveFineRecord(payload) {
    try {
        if (navigator.onLine) {
            const response = await fetch('/municipal-system/api/save_fine.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
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
            await queueRecord('fine', payload, currentUserId);
            showFineAlert('No connection - this fine has been saved on this device and will sync ' +
                'automatically once you are back online.', 'warning');
            fineForm.reset();
        }
    } catch (err) {
        await queueRecord('fine', payload, currentUserId);
        showFineAlert(`Could not reach the server - the fine was saved on this device instead. ` +
            `It will sync automatically. (${err.message})`, 'warning');
        fineForm.reset();
        console.warn('Server save failed, queued offline instead:', err.message);
    }
}

async function syncFines() {
    if (!navigator.onLine) return;
    await syncOfflineQueue();
    await refreshPendingFineCount();
}

if (btnSyncFines) {
    btnSyncFines.addEventListener('click', syncFines);
}

updateFineOfflineBanner();
refreshPendingFineCount();
if (navigator.onLine) syncFines();