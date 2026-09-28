    </main><!-- /.content-wrapper -->

    <!-- ── Centered Fixed Footer ───────────────────────────────── -->
    <footer class="footer">
        <span class="footer-motto">Service with Integrity</span>
        <span class="footer-sep">·</span>
        <span>&copy; <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?> &mdash; Beitbridge Municipality</span>
        <span class="footer-sep">·</span>
        <span>All rights reserved</span>
        <span class="footer-sep">·</span>
        <span>Developed by <strong>Pearson Nyirenda</strong></span>
    </footer>

</div><!-- /.app-viewport -->

<!-- ── 1. SQLite WASM — MUST be first ─────────────────────────── -->
<script src="/municipal-system/assets/vendor/sql-wasm.js"></script>

<!-- ── 2. SQLite queue — defines getDb, queueRecord, etc. ─────── -->
<script src="/municipal-system/assets/js/offline-db-sqlite.js"></script>

<!-- ── 3. Everything else, in this order ──────────────────────── -->
<script src="/municipal-system/assets/js/nav.js"></script>
<script src="/municipal-system/assets/js/connectivity.js"></script>
<script src="/municipal-system/assets/js/print.js"></script>
<script src="/municipal-system/assets/js/password-toggle.js"></script>
<script src="/municipal-system/assets/js/register-sw.js"></script>

</body>
</html>