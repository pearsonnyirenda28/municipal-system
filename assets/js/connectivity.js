/**
 * assets/js/connectivity.js
 * Version 4.0 — Smart Connectivity Detection.
 *
 * Wrapped in an IIFE so the constants and functions inside are scoped to
 * this module and cannot collide if the file is accidentally loaded twice.
 */
(function () {
    'use strict';

    // ── Guard: if already initialised, don't run again ────────────
    if (window.__connectivityLoaded) return;
    window.__connectivityLoaded = true;

    const PING_URL         = '/municipal-system/api/ping.php';
    const PING_TIMEOUT_MS  = 4000;
    const POLL_INTERVAL_MS = 20000;

    let currentStatus = navigator.onLine ? 'checking' : 'offline';

    function setStatus(newStatus) {
        if (newStatus === currentStatus) return;
        currentStatus = newStatus;
        window.dispatchEvent(new CustomEvent('connectivitychange', {
            detail: { status: newStatus },
        }));
        updateBadge(newStatus);
    }

    function updateBadge(status) {
        const badge = document.getElementById('connectivityBadge');
        if (!badge) return;
        const labels = {
            offline:     ['No connection',      '#F8D7DA', '#8a2c22'],
            'no-server': ['Server unreachable', '#FCEEDB', '#8a5a10'],
            reachable:   ['Online',             '#E4F1EF', '#1c5551'],
            checking:    ['Checking...',        '#EDEFF2', '#5B6472'],
        };
        const [text, bg, fg] = labels[status] || labels.checking;
        badge.textContent = text;
        badge.style.background = bg;
        badge.style.color = fg;
    }

    async function checkServerReachable() {
        if (!navigator.onLine) {
            setStatus('offline');
            return false;
        }
        try {
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), PING_TIMEOUT_MS);
            const response = await fetch(PING_URL + '?t=' + Date.now(), {
                method: 'GET',
                cache: 'no-store',
                signal: controller.signal,
            });
            clearTimeout(timeout);
            if (response.ok) {
                setStatus('reachable');
                return true;
            }
            setStatus('no-server');
            return false;
        } catch (err) {
            setStatus('no-server');
            return false;
        }
    }

    // ── Public API ────────────────────────────────────────────────
    window.getConnectivityStatus = () => currentStatus;
    window.isServerReachable     = checkServerReachable;

    // ── Event bindings ────────────────────────────────────────────
    window.addEventListener('online',  checkServerReachable);
    window.addEventListener('offline', () => setStatus('offline'));

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') checkServerReachable();
    });

    checkServerReachable();
    setInterval(checkServerReachable, POLL_INTERVAL_MS);

    window.addEventListener('connectivitychange', function (event) {
        if (event.detail.status === 'reachable'
            && typeof window.syncOfflineQueue === 'function') {
            window.syncOfflineQueue();
        }
    });
})();