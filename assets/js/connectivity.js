/**
 * assets/js/connectivity.js
 * Version 4.0 — Smart Connectivity Detection.
 *
 * Wrapped in an IIFE so the constants and functions inside are scoped to
 * this module and cannot collide if the file is accidentally loaded twice.
 */
// Fallback: give the sync layer its CSRF token from the <meta> tag if the page's
// inline <script> did not run (for example because a stricter CSP blocked it).
(function () {
    if (!window.CSRF_TOKEN) {
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m && m.content) { window.CSRF_TOKEN = m.content; }
    }
    // Who is logged in - used by the offline queue so one user's saved records are never
    // sent under another user's login on a shared computer.
    if (!window.CURRENT_USER_ID) {
        var u = document.querySelector('meta[name="current-user-id"]');
        if (u && u.content) { window.CURRENT_USER_ID = parseInt(u.content, 10); }
    }
})();

// Tells the user when a sync pass found something they need to act on. The records
// themselves are never lost: they stay on this device until they can be sent.
window.addEventListener('queuesynced', function (e) {
    var d = (e && e.detail) || {};
    var bar = document.getElementById('syncNoticeBar');
    var msg = '', link = false;
    if (d.sessionExpired) {
        msg = 'Your session has ended, so your saved records have not been sent yet. They are safe on this device. ';
        link = true;
    } else if (d.otherUsers > 0) {
        msg = d.otherUsers + ' saved record' + (d.otherUsers === 1 ? '' : 's') +
              ' on this device ' + (d.otherUsers === 1 ? 'belongs' : 'belong') + ' to another user and will be sent when that user logs in.';
    }
    if (!msg) { if (bar) { bar.parentNode.removeChild(bar); } return; }
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'syncNoticeBar';
        bar.setAttribute('role', 'alert');
        bar.style.cssText = 'position:sticky;top:0;z-index:9999;background:#000;color:#fff;padding:10px 16px;font-size:14px;text-align:center;';
        document.body.insertBefore(bar, document.body.firstChild);
    }
    bar.textContent = msg;
    if (link) {
        var a = document.createElement('a');
        a.href = '/municipal-system/login.php';
        a.textContent = 'Log in again';
        a.style.cssText = 'color:#fff;font-weight:bold;text-decoration:underline;';
        bar.appendChild(a);
    }
});

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