/**
 * assets/js/meter-reading.js
 * Real, working version of the sample code documented in Chapter 5,
 * Sections 2.4 and 3.2.
 */

const houseSelect = document.getElementById('houseSelect');
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

houseSelect.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    previousField.value = opt.dataset.previous || '';
});

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

btnSave.addEventListener('click', async function () {
    const houseId = houseSelect.value;
    const houseNumber = houseSelect.options[houseSelect.selectedIndex]?.dataset.houseNumber || '';
    const previous = parseFloat(previousField.value);
    const current = parseFloat(currentField.value);

    if (!houseId) {
        showAlert('Please select a house.', 'error');
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
        houseId: parseInt(houseId, 10),
        houseNumber,
        previous,
        current,
        readerId: window.CURRENT_USER_ID,
        dateTaken: new Date().toISOString().slice(0, 10),
        synced: false,
    };

    await saveReading(record);
    currentField.value = '';
    houseSelect.value = '';
    previousField.value = '';
    await refreshPendingCount();
});

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
            break; // stop trying if the connection drops again mid-sync
        }
    }
    await refreshPendingCount();
}

btnSync.addEventListener('click', syncPendingReadings);

// Initial state on page load
updateOfflineBanner();
refreshPendingCount();
if (navigator.onLine) syncPendingReadings();
