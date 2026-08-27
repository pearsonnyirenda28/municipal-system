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

async function saveVisitStatus(payload) {
    const alertBox = document.getElementById('formAlert');
    try {
        if (navigator.onLine) {
            const response = await fetch('/municipal-system/api/mark_visit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Server rejected the request');
            }
            if (alertBox) alertBox.innerHTML = `<div class="alert success">Status saved.</div>`;
        } else {
            await queueRecord('visitStatus', payload, window.CURRENT_USER_ID);
            if (alertBox) alertBox.innerHTML = `<div class="alert warning">No connection - status saved on ` +
                `this device and will sync automatically once you are back online.</div>`;
        }
    } catch (err) {
        await queueRecord('visitStatus', payload, window.CURRENT_USER_ID);
        if (alertBox) alertBox.innerHTML = `<div class="alert warning">Could not reach the server - ` +
            `status saved on this device instead. It will sync automatically.</div>`;
        console.warn('Status save failed, queued offline instead:', err.message);
    }
}
