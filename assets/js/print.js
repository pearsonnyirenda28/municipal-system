/**
 * assets/js/print.js
 * Universal print trigger — CSP-safe (no inline handlers).
 * Bind to any element carrying [data-print] or class .btn-print.
 */
(function () {
    'use strict';

    function trigger(e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        window.print();
    }

    function bindAll() {
        document
            .querySelectorAll('[data-print], .btn-print, #btnPrintReceipt')
            .forEach(function (el) {
                if (el.dataset.printBound === '1') return;
                el.dataset.printBound = '1';

                // A <button> inside a form defaults to type=submit;
                // force it to "button" so it doesn't POST.
                if (el.tagName === 'BUTTON' && !el.getAttribute('type')) {
                    el.setAttribute('type', 'button');
                }

                el.addEventListener('click', trigger);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    } else {
        bindAll();
    }

    // Optional global in case anything needs to trigger print in JS
    window.triggerPrint = trigger;
})();