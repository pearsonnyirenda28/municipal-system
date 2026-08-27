/**
 * assets/js/nav.js
 * Toggles the mobile hamburger menu. Progressive enhancement only -
 * the nav links are plain <a> tags and work fine with JS disabled,
 * just always visible instead of collapsible.
 */
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('navToggle');
    const nav = document.getElementById('mainNav');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', function () {
        const isOpen = nav.classList.toggle('open');
        toggle.classList.toggle('open', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close the menu after tapping a link (mobile UX nicety)
    nav.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            nav.classList.remove('open');
            toggle.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });
});
