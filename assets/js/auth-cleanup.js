/**
 * assets/js/auth-cleanup.js - loaded only on login.php.
 *
 * The service worker keeps a copy of every page a user visits so the app still opens
 * offline. Those copies are the logged-in user's data (case and fine lists, names...).
 * Reaching the login page means nobody is logged in on this device any more (logout, an
 * idle timeout, or a deactivated account) - so every cached PAGE is deleted here. Without
 * this, the next person at a shared computer could open the previous user's pages offline.
 *
 * What it deliberately does NOT touch: the app's scripts, styles and images (needed to run
 * at all), and the offline queue of saved-but-unsynced records, which lives in IndexedDB
 * and must survive a logout so it can still be sent after the next login.
 */
(function () {
    'use strict';
    if (!('caches' in window)) { return; }
    var BASE = '/municipal-system/';

    caches.keys().then(function (names) {
        return Promise.all(names.filter(function (n) { return n.indexOf('municipal-shell-') === 0; }).map(function (n) {
            return caches.open(n).then(function (cache) {
                return cache.keys().then(function (requests) {
                    return Promise.all(requests.filter(function (r) {
                        var p = new URL(r.url).pathname;
                        return p.indexOf(BASE + 'assets/') !== 0 && !/\/login\.php$/.test(p) && !/\/offline\.html$/.test(p);
                    }).map(function (r) { return cache.delete(r); }));
                });
            });
        }));
    }).catch(function () { /* cache access can fail in private windows - nothing to clean there */ });
})();
