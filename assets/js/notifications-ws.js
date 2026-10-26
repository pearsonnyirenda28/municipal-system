/**
 * assets/js/notifications-ws.js
 * Connects to ws/server.php (see ws/README.md) and keeps the header
 * notification bell live, without needing a page reload. Pure
 * progressive enhancement: if the WS server isn't running, or the
 * connection drops, this fails silently and the bell just goes back to
 * being exactly what it always was - accurate as of the last page load.
 *
 * Reconnects with backoff (2s, 4s, 8s... capped at 30s) so a restarted
 * WS server or a laptop waking from sleep recovers on its own.
 */
(function () {
    const container = document.getElementById('toastContainer');
    const wsPort = container ? parseInt(container.dataset.wsPort, 10) : NaN;
    if (!wsPort) return;

    let socket = null;
    let backoff = 2000;
    const MAX_BACKOFF = 30000;
    let previousMessages = null; // Set of notification message strings, for diffing on new pushes

    function connect() {
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const url = protocol + '//' + window.location.hostname + ':' + wsPort + '/';

        try {
            socket = new WebSocket(url);
        } catch (e) {
            scheduleReconnect();
            return;
        }

        socket.addEventListener('open', function () {
            backoff = 2000; // connected cleanly - reset the backoff for next time
        });

        socket.addEventListener('message', function (event) {
            let data;
            try {
                data = JSON.parse(event.data);
            } catch (e) {
                return;
            }
            if (data.type === 'notifications') {
                applyUpdate(data);
            }
            if (data.type === 'auth_error') {
                // The server no longer accepts this session (it timed out, or the account was
                // deactivated). Stop reconnecting in a loop; the next page load goes to login.
                authFailed = true;
            }
            // type 'auth_error' -> session isn't valid from the server's
            // point of view (e.g. it expired). Nothing to do client-side;
            // the next real page load will bounce to the login page via
            // the normal requireLogin() check same as always.
        });

        socket.addEventListener('close', scheduleReconnect);
        socket.addEventListener('error', function () { socket.close(); });
    }

    var authFailed = false;

    function scheduleReconnect() {
        if (authFailed) { return; }
        setTimeout(connect, backoff);
        backoff = Math.min(backoff * 2, MAX_BACKOFF);
    }

    function applyUpdate(data) {
        updateBadges(data.count);
        updateDropdowns(data.items);

        if (!data.isInitial) {
            const currentMessages = new Set(data.items.map(i => i.message));
            if (previousMessages) {
                for (const item of data.items) {
                    if (!previousMessages.has(item.message)) {
                        showToast(item);
                    }
                }
            }
        }
        previousMessages = new Set(data.items.map(i => i.message));
    }

    function updateBadges(count) {
        document.querySelectorAll('.notif-bell').forEach(function (bell) {
            let badge = bell.querySelector('.notif-badge');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notif-badge';
                    bell.appendChild(badge);
                }
                badge.textContent = count > 99 ? '99+' : String(count);
            } else if (badge) {
                badge.remove();
            }
        });
    }

    function updateDropdowns(items) {
        document.querySelectorAll('.notif-dropdown').forEach(function (dropdown) {
            dropdown.innerHTML = '';
            if (!items.length) {
                const empty = document.createElement('div');
                empty.className = 'notif-empty';
                empty.textContent = 'No notifications right now.';
                dropdown.appendChild(empty);
                return;
            }
            items.forEach(function (item) {
                const a = document.createElement('a');
                a.href = item.link;
                a.className = 'notif-item';
                a.textContent = item.message;
                dropdown.appendChild(a);
            });
        });
    }

    function showToast(item) {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('a');
        toast.href = item.link;
        toast.className = 'glass-toast';
        toast.innerHTML = '<span class="glass-toast-dot"></span><span>' + escapeHtml(item.message) + '</span>';
        container.appendChild(toast);

        requestAnimationFrame(function () { toast.classList.add('show'); });

        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 400);
        }, 8000);
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    connect();
})();
