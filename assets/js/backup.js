/**
 * assets/js/backup.js
 * CSP-safe handlers for the backups page.
 *
 * Replaces the inline onclick / onsubmit attributes that backup.php used
 * to have. Those were silently blocked by the app's Content-Security-
 * Policy, so the Restore button appeared to do nothing when clicked and
 * the Delete / Confirm-Restore forms submitted without asking for
 * confirmation. Moving the handlers into a file loaded as
 * <script src="..."> keeps them running under CSP.
 *
 * Two behaviours are provided:
 *
 *   button.js-restore-toggle[data-target="<id>"]
 *       -> reveals the element with that id (the per-row restore form)
 *       -> focuses its text input, if it has one
 *
 *   form.js-confirm[data-confirm="<message>"]
 *       -> shows window.confirm(<message>) before allowing the submit
 */
(function () {
    'use strict';

    function bindRestoreToggles() {
        document.querySelectorAll('.js-restore-toggle').forEach(function (btn) {
            if (btn.dataset.bound === '1') return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var targetId = btn.getAttribute('data-target');
                if (!targetId) return;

                var target = document.getElementById(targetId);
                if (!target) return;

                target.style.display = 'block';
                var input = target.querySelector('input[type="text"]');
                if (input) input.focus();
            });
        });
    }

    function bindConfirmForms() {
        document.querySelectorAll('form.js-confirm').forEach(function (form) {
            if (form.dataset.bound === '1') return;
            form.dataset.bound = '1';

            form.addEventListener('submit', function (e) {
                var msg = form.getAttribute('data-confirm') || 'Are you sure?';
                if (!window.confirm(msg)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });
    }

    function init() {
        bindRestoreToggles();
        bindConfirmForms();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();