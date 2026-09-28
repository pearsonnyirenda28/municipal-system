/**
 * assets/js/nav.js
 * Drawer open/close behaviour — CSP-safe, no inline handlers.
 *
 * Coordinates:
 *   #navToggle  → hamburger button (opens / closes the drawer)
 *   #navClose   → × button inside the drawer (closes it)
 *   #mainNav    → the drawer element itself
 *
 * Progressive enhancement only — the nav links are plain <a> tags and
 * work fine with JS disabled, just always visible instead of collapsible.
 *
 * Closes the drawer via:
 *   - Hamburger click (toggle)
 *   - × button inside the drawer
 *   - Escape key
 *   - Clicking anywhere outside the drawer
 *   - Tapping a nav link (mobile UX)
 *   - Tapping the logout button (before it navigates away)
 */
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('navToggle');
    var nav    = document.getElementById('mainNav');
    var close  = document.getElementById('navClose');

    if (!toggle || !nav) return;

    function openDrawer() {
        nav.classList.add('open');
        toggle.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Close menu');
        if (close) close.focus();
    }

    function closeDrawer() {
        nav.classList.remove('open');
        toggle.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open menu');
    }

    function isOpen() {
        return nav.classList.contains('open');
    }

    // ── Hamburger toggles the drawer ─────────────────────────────
    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        isOpen() ? closeDrawer() : openDrawer();
    });

    // ── × button inside the drawer closes it ─────────────────────
    if (close) {
        close.addEventListener('click', function (e) {
            e.preventDefault();
            closeDrawer();
            toggle.focus();
        });
    }

    // ── Escape key closes the drawer ─────────────────────────────
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
            closeDrawer();
            toggle.focus();
        }
    });

    // ── Click outside the drawer closes it ───────────────────────
    document.addEventListener('click', function (e) {
        if (!isOpen()) return;
        if (nav.contains(e.target)) return;      // inside the drawer
        if (toggle.contains(e.target)) return;   // on the hamburger
        closeDrawer();
    });

    // ── Tapping a link closes the drawer (mobile UX) ─────────────
    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            closeDrawer();
        });
    });
});