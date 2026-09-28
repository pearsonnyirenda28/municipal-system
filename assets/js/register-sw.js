/**
 * assets/js/register-sw.js
 * Registers service-worker.js. Service workers only run in a "secure
 * context" - HTTPS, or http://localhost specifically (XAMPP on your own
 * PC satisfies this; a plain-HTTP production host would not). If
 * registration fails for that or any other reason, this fails silently
 * from the user's point of view - the rest of the app (including the
 * offline data queue from Version 3.5) works exactly the same either
 * way, since the service worker only affects whether pages themselves
 * can load with zero connectivity, not whether captured data is safe.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/municipal-system/service-worker.js', {
            scope: '/municipal-system/',
        }).then(function (registration) {
            console.log('Service worker registered:', registration.scope);
        }).catch(function (err) {
            console.warn('Service worker registration failed (offline page caching will not be ' +
                'available, but data capture and sync still work normally):', err.message);
        });
    });
}
