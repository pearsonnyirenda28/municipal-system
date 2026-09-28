/**
 * assets/js/receipt-print.js
 * Handles the fine receipt print action securely to comply with CSP.
 */
document.addEventListener('DOMContentLoaded', () => {
    const printBtn = document.getElementById('btnPrintReceipt');
    if (printBtn) {
        printBtn.addEventListener('click', () => {
            window.print();
        });
    }
});