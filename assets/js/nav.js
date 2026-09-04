/**
 * assets/js/nav.js
 * Toggles the mobile hamburger menu with low-vision and keyboard accessibility support.
 * Progressive enhancement only - nav links work fine with JS disabled.
 */
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('navToggle');
    const nav = document.getElementById('mainNav');

    if (toggle && nav) {
        function closeMenu() {
            nav.classList.remove('open');
            toggle.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
        }

        // Toggle menu state on hamburger click
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = nav.classList.toggle('open');
            toggle.classList.toggle('open', isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close the menu after tapping a link (mobile UX)
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMenu);
        });

        // Close menu when tapping anywhere outside the navigation drawer
        document.addEventListener('click', function (e) {
            if (nav.classList.contains('open') && !nav.contains(e.target) && !toggle.contains(e.target)) {
                closeMenu();
            }
        });

        // Close menu when pressing the Escape key (keyboard & screen reader accessibility)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && nav.classList.contains('open')) {
                closeMenu();
                toggle.focus(); // Return focus to hamburger button
            }
        });
    }

    // Global print button handler
    const printBtn = document.getElementById('printBtn');
    if (printBtn) {
        printBtn.addEventListener('click', () => {
            window.print();
        });
    }
});