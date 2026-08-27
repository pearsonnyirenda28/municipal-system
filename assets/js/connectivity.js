/**
 * assets/js/connectivity.js
 * Version 4.0 - Smart Connectivity Detection.
 *
 * navigator.onLine only reports whether the device has *a* network
 * interface up, not whether the municipal server is actually reachable.
 * A captive portal on public Wi-Fi, or the office LAN with the server
 * down, both read as "online" while sync genuinely can't happen. This
 * polls api/ping.php (no-auth, tiny) to find out the real answer.
 *
 * Exposes:
 *   - window.getConnectivityStatus() -> 'offline' | 'no-server' | 'reachable'
 *   - window.isServerReachable() -> Promise<boolean>
 *   - a 'connectivitychange' event on window, detail: { status }
 *   - updates #connectivityBadge in the page header, if present
 */

const PING_URL = '/municipal-system/api/ping.php';
const PING_TIMEOUT_MS = 4000;
const POLL_INTERVAL_MS = 20000;

let currentStatus = navigator.onLine ? 'checking' : 'offline';

function setStatus(newStatus) {
    if (newStatus === currentStatus) return;
    currentStatus = newStatus;
    window.dispatchEvent(new CustomEvent('connectivitychange', { detail: { status: newStatus } }));
    updateBadge(newStatus);
}

function updateBadge(status) {
    const badge = document.getElementById('connectivityBadge');
    if (!badge) return;
    const labels = {
        offline: ['No connection', '#F8D7DA', '#8a2c22'],
        'no-server': ['Server unreachable', '#FCEEDB', '#8a5a10'],
        reachable: ['Online', '#E4F1EF', '#1c5551'],
        checking: ['Checking...', '#EDEFF2', '#5B6472'],
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
            method: 'GET', cache: 'no-store', signal: controller.signal,
        });
        clearTimeout(timeout);
        if (response.ok) { setStatus('reachable'); return true; }
        setStatus('no-server');
        return false;
    } catch (err) {
        setStatus('no-server');
        return false;
    }
}

window.getConnectivityStatus = () => currentStatus;
window.isServerReachable = checkServerReachable;

window.addEventListener('online', checkServerReachable);
window.addEventListener('offline', () => setStatus('offline'));

document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') checkServerReachable();
});

checkServerReachable();
setInterval(checkServerReachable, POLL_INTERVAL_MS);

window.addEventListener('connectivitychange', function (event) {
    if (event.detail.status === 'reachable' && typeof window.syncOfflineQueue === 'function') {
        window.syncOfflineQueue();
    }
});
