/**
 * service-worker.js
 * Version 4.0 - True Offline Field Application.
 *
 * What this does and doesn't do, honestly:
 *   - DOES let already-visited pages (dashboard, meter reading capture,
 *     issue fine, etc.) open with zero connectivity once the user has
 *     opened them at least once while logged in and online - at that
 *     point the exact HTML they were shown is cached and reused. This
 *     is the actual gap Version 3.0 left open ("the page itself still
 *     needs one online visit first") - it's still true, but now it's a
 *     genuine one-time requirement per page rather than per session,
 *     and the cached copy survives closing the app entirely.
 *   - Only login.php is precached at install time, deliberately - see
 *     the comment on PRECACHE_URLS below (a real gotcha involving login
 *     redirects, worth reading before changing this list).
 *   - DOES cache CSS/JS/icons so the app's look and behaviour don't
 *     depend on the network at all once installed.
 *   - Does NOT turn this into a full client-side single-page app. A page
 *     for a specific house (capture.php?house_id=57) is its own cache
 *     entry, separate from plain capture.php.
 *   - Does NOT intercept any POST request or anything under /api/ - all
 *     of that is left to the network exactly as before, and the offline
 *     queue in offline-queue.js handles the fallback when it fails.
 */

const CACHE_VERSION = 'municipal-shell-v2';   // bumped to force a fresh install
const BASE = '/municipal-system';

const PRECACHE_URLS = [
    `${BASE}/offline.html`,
    `${BASE}/manifest.json`,
    `${BASE}/assets/css/style.css`,
    `${BASE}/assets/js/nav.js`,
    `${BASE}/assets/js/connectivity.js`,
    `${BASE}/assets/js/offline-queue.js`,
    `${BASE}/assets/js/meter-reading.js`,
    `${BASE}/assets/js/status-actions.js`,
    `${BASE}/assets/js/fine-issue.js`,
    `${BASE}/assets/js/print.js`,
    `${BASE}/assets/js/password-toggle.js`,
    `${BASE}/assets/icons/icon-192.png`,
    `${BASE}/assets/icons/icon-512.png`,
    // Deliberately NOT precaching dashboard.php, capture.php, etc. here.
    // Those require a login session; fetching them at install time (before
    // the user has necessarily logged in on this device) would follow the
    // redirect to login.php and cache *that* content under the dashboard's
    // cache key - wrong content under the wrong URL. Only login.php itself
    // is safe to precache. The authenticated pages still end up correctly
    // cached the ordinary way: the first time the user actually visits
    // each one while logged in and online, networkFirstForPages() below
    // caches exactly what they were shown - correct by construction, just
    // not available before that first real visit.
    `${BASE}/login.php`,
];

// ── Install ────────────────────────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => {
            return Promise.allSettled(
                PRECACHE_URLS.map((url) =>
                    cache.add(new Request(url, { credentials: 'same-origin' }))
                )
            );
        }).then(() => self.skipWaiting())
    );
});

// ── Activate ───────────────────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_VERSION)
                    .map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

// ── Route helpers ──────────────────────────────────────────────────
function isApiRequest(url)    { return url.pathname.startsWith(`${BASE}/api/`); }
function isStaticAsset(url)   { return url.pathname.startsWith(`${BASE}/assets/`); }

// ── Fetch routing ──────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET'
        || url.origin !== self.location.origin
        || isApiRequest(url)) {
        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (request.mode === 'navigate'
        || (request.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(networkFirstForPages(request));
    }
});

// ── Cache strategies ───────────────────────────────────────────────

/**
 * Static assets: serve from cache instantly, refresh in the background.
 *
 * IMPORTANT: response.clone() must be called SYNCHRONOUSLY inside the
 * fetch().then() callback, before the outer function returns `response`
 * to the browser. If we let the clone happen inside a deferred
 * caches.open().then() chain, the browser may have already begun
 * streaming `response.body` by the time we try to clone it - which
 * throws "Failed to execute 'clone' on 'Response': Response body is
 * already used".
 */
async function cacheFirst(request) {
    const cached = await caches.match(request);

    const networkFetch = fetch(request).then((response) => {
        if (response.ok) {
            const copy = response.clone();          // ← clone FIRST, synchronously
            caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
        }
        return response;
    }).catch(() => null);

    return cached || (await networkFetch) || new Response('', { status: 504 });
}

/**
 * Navigation / HTML pages: try the network first, fall back to the
 * last cached copy of that exact page, and finally to offline.html.
 *
 * Same clone rule as above - clone synchronously, defer only the
 * caches.open().then() that writes it.
 */
async function networkFirstForPages(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const copy = response.clone();          // ← clone FIRST, synchronously
            caches.open(CACHE_VERSION).then((cache) => cache.put(request, copy));
        }
        return response;
    } catch (err) {
        const cached = await caches.match(request);
        return cached || caches.match(`${BASE}/offline.html`);
    }
}