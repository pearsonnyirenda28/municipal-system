/**
 * assets/js/status-actions.js
 * Version 3.5: the Skip/Vacant/Locked/Illegal Connection/Revisit Required
 * buttons on the guided navigator (capture.php) now go through the same
 * offline queue as meter readings, instead of a plain form POST that
 * would just fail with no connection.
 */

const statusButtonsWrap = document.getElementById('statusButtons');

if (statusButtonsWrap) {
    const houseId = parseInt(statusButtonsWrap.dataset.houseId, 10);
    const nextHouseIdRaw = statusButtonsWrap.dataset.nextHouseId;
    const nextHouseId = nextHouseIdRaw ? parseInt(nextHouseIdRaw, 10) : null;

    statusButtonsWrap.querySelectorAll('.status-btn').forEach(function (button) {
        button.addEventListener('click', async function () {
            const status = button.dataset.status;
            statusButtonsWrap.querySelectorAll('.status-btn').forEach(b => b.disabled = true);

            const payload = {
                clientRef: makeClientRef(),
                houseId,
                status,
                notes: null,
                visitDate: new Date().toISOString().slice(0, 10),
            };

            await saveVisitStatus(payload);

            if (nextHouseId) {
                setTimeout(() => { window.location.href = '?house_id=' + nextHouseId; }, 700);
            } else {
                setTimeout(() => { window.location.href = 'capture.php'; }, 700);
            }
        });
    });
}

/**
 * Version 4.5: local-first, same reasoning as meter-reading.js and
 * fine-issue.js - queue on this device FIRST (that is what "saved"
 * means from here), then try an opportunistic push only when
 * connectivity.js's real reachability check says the server actually
 * answers right now.
 */
async function saveVisitStatus(payload) {
    const alertBox = document.getElementById('formAlert');
    try {
        await queueRecord('visitStatus', payload, window.CURRENT_USER_ID);
    } catch (err) {
        if (alertBox) alertBox.innerHTML = `<div class="alert error">Could not save this status on this ` +
            `device (${err.message}). Please try again.</div>`;
        console.error('Local save failed:', err);
        return;
    }

    if (alertBox) alertBox.innerHTML = `<div class="alert success">Status saved on this device and queued to sync.</div>`;

    const reachable = typeof window.getConnectivityStatus === 'function'
        && window.getConnectivityStatus() === 'reachable';
    if (reachable && typeof window.syncOfflineQueue === 'function') {
        window.syncOfflineQueue(); // opportunistic - doesn't block navigation to the next house
    }
}
