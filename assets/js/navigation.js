document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.getElementById('navToggle');
    const links = document.getElementById('navLinks');

    if (toggle && links) {
        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            links.classList.toggle('active');
        });

        document.addEventListener('click', (e) => {
            if (!links.contains(e.target) && !toggle.contains(e.target)) {
                links.classList.remove('active');
            }
        });
    }

    const indicator = document.getElementById('connectivityIndicator');
    const text = document.getElementById('connectivityText');

    function updateConnectionStatus() {
        if (!indicator || !text) return;
        if (navigator.onLine) {
            indicator.className = 'connectivity-badge online';
            text.textContent = 'Online';
        } else {
            indicator.className = 'connectivity-badge offline';
            text.textContent = 'Offline';
        }
    }

    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);
    updateConnectionStatus();
});