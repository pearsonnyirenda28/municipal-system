/**
 * assets/js/dashboard-pending.js
 * Only included on dashboard.php when a reader-workload widget is
 * present (see the #unsyncedCount element there). Reuses the same
 * IndexedDB the guided capture flow writes to (assets/js/offline-db.js,
 * loaded first) - this is genuinely what's still sitting on this
 * device, not a server-side guess.
 */
countPendingReadings().then(function (count) {
    const el = document.getElementById('unsyncedCount');
    if (el) {
        el.textContent = count;
    }
});
