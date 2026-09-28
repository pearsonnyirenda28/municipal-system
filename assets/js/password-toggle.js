/* assets/js/password-toggle.js — CSP-safe show/hide toggle. */
(function () {
    'use strict';

    function toggle(btn) {
        var inputId = btn.getAttribute('data-password-toggle');
        if (!inputId) return;
        var input = document.getElementById(inputId);
        if (!input) return;

        var isHidden = input.type === 'password';

        input.type = isHidden ? 'text' : 'password';
        btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');

        var showIcon = btn.querySelector('.eye-show');
        var hideIcon = btn.querySelector('.eye-hide');
        if (showIcon) showIcon.hidden = isHidden;
        if (hideIcon) hideIcon.hidden = !isHidden;

        input.focus();
    }

    function bindAll() {
        document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
            if (btn.dataset.pwBound === '1') return;
            btn.dataset.pwBound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                toggle(btn);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    } else {
        bindAll();
    }
})();