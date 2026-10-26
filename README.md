# Municipal Police and Meter Reading Management System

This is the working system documented in Chapters 1-7: **login and
role-based access, Police Cases, Fines (issue / pay / printable receipt),
Meter Readings (capture, with offline support and automatic sync), Damaged
Meter reports (with photo upload), User account management, and the four
filterable Reports** from Chapter 4, Section 2.2. Version 4.5 adds a
second sync layer on top: more than one physical workstation, each with
its own local SQLite, consolidating into one central MySQL "Workspace
Server" over the municipal LAN (see the Version 4.5 section and section 6
below) - separate from, and in addition to, one device's browser staying
usable through a temporary network drop (Version 3.5).

The database is **SQLite by default** - a single file, no separate
database server to install or configure. All settings (including the
database driver, if you ever want to switch to MySQL) live in a `.env`
file that is never uploaded to GitHub.

> **Testing status (Version 4.7).** The sync layer, the session code and the
> browser pages were exercised end to end: real headless Chrome (offline save,
> reconnect, automatic sync, session expiry, shared-computer logins), real
> Apache with the project's `.htaccess`, a real MariaDB Workspace Server, and a
> real WebSocket client. Not covered: a second physical workstation, real LAN
> conditions, Windows/XAMPP specifics (including `start_all_cli.bat`), and
> Safari/Firefox. See the Version 4.7, 4.6 and 4.5 sections for details.

## Recent fixes and additions

- **Fixed a Windows path bug**: `setup_database.php` and the database
  connection were failing on Windows/XAMPP with "mkdir(): No such file or
  directory" / "unable to open database file". The cause: PHP's `__DIR__`
  returns backslash paths on Windows (`C:\xampp\...`), but the old check
  for "is this already an absolute path?" only recognised forward slashes,
  so `__DIR__` got prepended a second time, producing a garbled path with
  a drive letter stuck in the middle. Fixed in `config.php`
  (`resolveDbPath()`), used consistently by both `db.php` and
  `setup_database.php` now.
- **Hamburger menu** for mobile/tablet screens (under ~860px wide) - the
  top navigation collapses behind a &#9776; button, since officers and
  meter readers are expected to use this mostly on phones in the field.
- **Police and meter-reading supervisors are now separate roles**
  (`PoliceSupervisor` / `MeterSupervisor`, not one generic `Supervisor`).
  A police supervisor's dashboard, menu, and reports only ever show case
  and fine data; a meter-reading supervisor's only ever show meter reading
  and damaged-meter data - each is blind to the other's domain, not just
  in the menu but in what the dashboard actually queries.
- **CSRF protection** on every form and on the JSON API used by offline
  sync. Every `<form method="post">` now carries a hidden, per-session
  token (`includes/auth.php` - `csrfToken()` / `csrfField()`), and every
  POST handler verifies it (`verifyCsrf()`) before touching the database.
  The `fetch()` calls in `meter-reading.js` send the same token as an
  `X-CSRF-Token` header, checked server-side by `verifyCsrfHeader()`.
- **Stronger password policy**: passwords now need 8+ characters with an
  uppercase letter, a lowercase letter, and a number, and can't be the
  account's own username/full name or one of a short list of common
  passwords (`passwordPolicyErrors()` in `includes/auth.php`, used by both
  `setup_admin.php` and `users/create.php`).
- **XSS audit**: went through every `<?=` output in the codebase by hand.
  SQL injection was already covered everywhere (every query goes through
  PDO prepared statements), and it turned out output escaping
  (`htmlspecialchars()`) was already consistently applied to every place
  user-entered text gets displayed - offender names, descriptions, receipt
  fields, report tables, all of it. What was genuinely missing were
  **security headers**: `includes/auth.php` now sends
  `X-Content-Type-Options`, `X-Frame-Options: DENY` (blocks clickjacking),
  and a `Content-Security-Policy` restricting scripts/styles/images to
  this app's own origin, as defence-in-depth on top of the escaping.
- **Session hardening**: the session cookie is now set `HttpOnly`
  (JavaScript can never read it, even via an XSS bug) and `SameSite=Lax`
  (blocks it being sent on cross-site form submissions, a second layer
  against CSRF beyond the token itself).
- **Pagination and search** on every list page (Cases, Fines, Meter
  Readings, Damaged Meters, Users) - `includes/pagination.php` is a small
  shared helper (`paginate()`, `renderPagination()`, `renderSearchBox()`)
  used consistently across all five, so a page with 500 fines behaves the
  same as one with 5.
- **Responsive tables**: every data table (list pages and reports) is now
  wrapped in a horizontally-scrolling container on narrow screens, instead
  of squashing columns unreadably on a phone.
- **Dashboard widget**: a "Recent Activity" panel at the bottom of the
  dashboard - Admin sees the whole system's recent activity, everyone
  else sees only their own (kept consistent with the
  PoliceSupervisor/MeterSupervisor data separation above).
- Assorted mobile spacing fixes (login box, form panels, page headers)
  that were technically responsive before but cramped on a small phone.
- **Version 2.0 is now complete.** The two items left open last round:
  - **Login history** (`users/login_history.php`, Admin only) - every
    successful login, failed attempt, and logout was already being
    written to `ActivityLogs`; this gives it a proper screen, filterable
    by outcome, user, and date range, with IP address shown per event.
  - **Advanced filters**: every list page (Cases, Fines, Meter Readings,
    Damaged Meters, Users) now has a real multi-field filter bar - date
    ranges, status/role/ward dropdowns, and search, all combined in one
    form - instead of the single-click status-only links from before.
- **Version 2.5 - Ward-Based Operations is now complete.**
  - **Wards are a real managed entity now**, not a free-text field on
    Houses. New `Wards` and `WardAssignments` tables; `wards/list.php`
    and `wards/create.php` (Admin) manage wards, `wards/assign.php`
    assigns/unassigns meter readers to a ward (many-to-many - a reader
    can cover more than one ward).
  - **A whole new Houses module** (`houses/`) - there was no house
    management UI at all before this, only seed data. `houses/list.php`
    (filter by ward and street, search, paginated), `houses/create.php`
    and `houses/edit.php` (each house picks its ward from a dropdown, not
    free text), and `houses/bulk_assign.php` - the "bulk assignment
    tools" requirement - filter down a list of houses and move all of
    them to a different ward in one save.
  - **Meter readers now only see their own wards.** Not just filtered in
    the UI - the underlying queries in `meter-readings/capture.php`,
    `meter-readings/list.php`, `damaged-meters/report.php`, and
    `damaged-meters/list.php` all scope to `getAssignedWardIds()`
    (`includes/db.php`) for the `MeterReader` role. A reader assigned to
    no ward sees an explicit "ask your Administrator" message rather than
    either an empty page or, worse, every house in the system.
  - **Reader dashboard**: daily workload, a progress bar, and remaining
    houses for the day, scoped to the reader's own assigned wards.
  - **Existing SQLite databases upgrade automatically.** Since
    `CREATE TABLE IF NOT EXISTS` can't add columns to a table that
    already exists, `setup_database.php` now also runs a one-time,
    repeatable migration (`migrateWardColumns()`) that adds the new
    `street`/`ward_id` columns to an existing `Houses` table, turns every
    distinct old free-text ward value into a real row in `Wards`, and
    points each house at the right one - nothing is deleted, safe to run
    again, and does nothing on a database that's already been migrated.
  - `schema.mysql.sql` and `migrate_to_mysql.php` were updated to match
    (both new tables added, `Houses` column list updated, dependency
    order preserved so `Wards` migrates before `Houses`).
  - **Version 3.0 - Guided Field Operations is now complete**, built on a
    branch (`version-3.0-guided-field-operations`), not on `main` directly.
    - **New `HouseVisits` table**: one row per house, per reader, per day.
      A visit is now tracked as its own thing, separate from whether a
      reading was actually captured - a locked gate or a vacant plot is a
      completed *visit* even though it isn't a completed *reading*.
    - **House navigation**: `meter-readings/capture.php` is a guided
      navigator now, not a free house picker - Previous/Next buttons walk
      the reader's round in street/house-number order, a "Jump to house"
      dropdown shows every house with its status, and landing on the page
      with no `?house_id=` resumes at the first house that hasn't been
      dealt with yet today.
    - **Reading status**: alongside the existing meter-reading capture,
      five explicit status buttons (Skip, Vacant, Locked Premises, Illegal
      Connection, Revisit Required) record a visit outcome and
      auto-advance to the next house; "Report Damaged Meter" hands off to
      the existing damage-report form (now pre-filled with the house) and
      marks the visit accordingly on save.
    - **Reader dashboard**: the Version 2.5 workload widget now shows a
      full status breakdown ("Today's Reading Statistics" - Completed,
      Skipped, Vacant, etc.), plus an **Unsynced records** count read
      directly from the browser's own IndexedDB (not guessed server-side).
    - **Honest limitation**: the five status buttons are online-only for
      now - unlike meter readings, marking a house Skipped/Vacant/Locked/
      etc. does not yet work offline. See the roadmap section for why.
## Version 3.5 - Intelligent Offline Synchronization (complete, on its own branch)

Built on `version-3.5-offline-sync`, branched off `version-3.0-guided-field-operations`
(not off `main`) since it depends directly on that round's work.

- **Generalized offline queue** (`assets/js/offline-queue.js`) replaces the
  meter-reading-only `offline-db.js` from Version 3.0. One IndexedDB
  queue now handles every offline-capable action, and each queued
  record carries exactly what the roadmap asked for: a UUID
  (`clientRef`), a persistent per-device ID (`localStorage`, survives
  across sessions on that device), the user ID, a timestamp, a synced
  flag, and a retry count with the last error message if it keeps failing.
- **Closed the gap flagged at the end of Version 3.0**: the five visit-status
  buttons (Skip/Vacant/Locked/Illegal Connection/Revisit Required) are
  offline-capable now, via a new JSON endpoint (`api/mark_visit.php`)
  and `assets/js/status-actions.js`, following the exact same
  online-first-fall-back-to-queue pattern meter readings already used.
- **Fine issuing is offline-capable too** (`api/save_fine.php`,
  `assets/js/fine-issue.js`) - same idempotency approach as meter
  readings (a `client_ref` column added to `Fines`, checked before
  insert so a retried sync can't create the same fine twice) and the
  same "flag, don't reject" duplicate detection (same offender, amount,
  and day, different `client_ref`).
- **Conflict review screen** (`sync/conflicts.php`, Admin only) - every
  "possible duplicate" flag raised during sync, across both meter
  readings and fines, in one filterable list. This was a gap called out
  in an earlier round's audit (flags were logged but had no screen);
  it's closed now.
- `fines/issue.php` keeps working exactly as before if JavaScript is
  disabled (the original server-side POST handler is untouched) - the
  offline behaviour is a progressive enhancement, not a replacement.

**What Version 3.5 does NOT cover yet, on purpose:**
- **Police Cases are not offline-capable.** The roadmap lists cases
  alongside fines and meter readings for offline storage; only fines
  and meter readings (plus visit status) were done this round to keep
  the change reviewable. Cases would follow the identical pattern
  (`api/save_case.php` + a client-side queue entry) whenever it's next.
- **Damaged Meter reports, including photos, are not offline-capable.**
  This is explicitly the hardest of the four (a photo has to be held as
  binary data in IndexedDB and uploaded separately once back online),
  and was deliberately left out of this round rather than rushed.
- The old `meter-readings/mark_status.php` (the Version 3.0 form-POST
  status handler) was deleted, fully superseded by `api/mark_visit.php`.
  If you had that URL bookmarked anywhere, it's gone.


## Version 4.7 - Session and login security fixes

Each problem below was first reproduced on v4.6, then fixed and re-tested.

- **Fixed: the account lockout never locked anyone.** The failed-attempt
  counter lived in the visitor's own session, so discarding the cookie reset
  it. Failures are now counted from the server's audit log (`ActivityLogs`),
  per username, since that user's last successful login, using the database
  clock. Unknown usernames behave exactly like real ones, so the lockout
  cannot be used to find out which accounts exist.
- **Fixed: a database-level deactivated account could still log in and stay
  logged in** (see "Known gaps" - the feature itself is unfinished). Login now
  refuses it, and every open session re-reads its account every
  `SESSION_REVALIDATE_SECONDS` (default 30), which also applies role changes.
- **Fixed: changing or resetting a password did not end other sessions.**
  Sessions carry a fingerprint of the password hash; when it stops matching,
  they end. Changing your own password keeps the current session (with a new
  ID) and ends the others.
- **Fixed: the idle timeout was only enforced when a page loaded.** The JSON
  endpoints (`api/save_reading.php`, `save_fine.php`, `mark_visit.php`) used
  their own looser check. They now use `requireApiLogin()`, which applies the
  same timeout and account rules and answers `401 {code: SESSION_EXPIRED}`.
- **Fixed: an ended session stranded saved records.** A 401/403 counted as a
  failed attempt for every queued record, and after `MAX_SYNC_RETRIES` passes
  the push loop skipped them for good. An ended session now pauses the sync,
  counts nothing against the records, and shows a "Log in again" bar. Records
  already stranded this way are given another chance once per page load.
- **Fixed: on a shared computer one user's saved records could be sent under
  the next user's login** (the server stamps `issued_by` / `reader_id` from the
  session). The queue now sends only the current user's records, and the server
  refuses a mismatch (`409 WRONG_USER`) using the `X-Queued-By` header.
- **Fixed: logout left every visited page readable offline.** Reaching the
  login page now deletes the cached pages (`assets/js/auth-cleanup.js`); scripts,
  styles and the unsent-records queue are kept.
- **Hardened:** own cookie name `MUNICIPAL_SESSID` (the shared `PHPSESSID`
  let other PHP apps on the same host overwrite each other's session data),
  strict mode, cookies-only, `Secure` on HTTPS, `gc_maxlifetime` never below the
  timeout, a new session ID and CSRF token at login, one bcrypt comparison even
  for unknown usernames, and logout now expires the cookie.
- **New `.env` settings:** `SESSION_TIMEOUT_MINUTES` (default 15; use `1` to
  demonstrate the timeout) and `SESSION_REVALIDATE_SECONDS` (default 30).
- **New: `start_all_cli.bat`** starts the sync engine (once, in its own
  window), takes a backup, and can schedule the nightly one. Use `stop` to close
  the engine and `schedule-backup` for the 02:00 task.

Existing users are logged out once after upgrading (the cookie name changed).

### Known gaps (found, not changed)

- **Account deactivation is unfinished.** `users/toggle_active.php` exists, but
  the user list has no button for it, `is_active` is missing from `schema.sql`
  and `schema.mysql.sql`, and master-data sync does not carry it. The checks
  above work on databases that have the column and are skipped, without errors,
  on those that do not.
- **`ws/` (WebSocket notifications) is not connected.** `WS_PORT` is defined
  nowhere, so `ws/server.php` cannot start, and no page loads
  `notifications-ws.js` or renders a notification bell. Its session checks were
  hardened and tested with a test-only bootstrap, ready for when it is wired up.
- Fine numbers are generated per workstation (see Version 4.6).

## Version 4.6 - Fixes found by testing in a real browser and under Apache

Version 4.5 had only ever been tested over PHP's built-in dev server and in
Node. Neither enforces `.htaccess` or Content-Security-Policy, and neither
runs the page scripts in a real browser, so these slipped through.

- **Fixed: "CSRF errors" / `window.CSRF_TOKEN is not set`.** `.htaccess` sent a
  second, narrower `Content-Security-Policy` (no `'unsafe-inline'`) on top of
  the one PHP sends in `includes/db.php`. Browsers enforce the intersection of
  every CSP header, so the inline `<script>` that hands the token to the sync
  layer was blocked, and with it every inline `onchange`/`onclick` handler.
  The `.htaccess` copy is removed: `includes/db.php` is the single policy.
  As a second line of defence the token is also in a `<meta name="csrf-token">`
  tag (`includes/header.php`) and `connectivity.js` reads it if the inline
  script did not run.
- **Fixed: `countPending is not defined` / `syncOfflineQueue is not defined`.**
  `meter-reading.js`, `fine-issue.js` and `status-actions.js` ran before the
  footer loaded `offline-db-sqlite.js`. They are now `defer`red, and the
  reader dashboard's pending-count snippet waits for `DOMContentLoaded`.
- **Fixed: the "pending" counter stayed at 1 after a background sync.**
  `syncOfflineQueue()` now fires a `queuesynced` event the pages listen for.
- **Fixed: one bad record blocked the whole sync.** `api/sync_push.php` put
  the entire batch in one transaction, so a single constraint violation made
  the server answer HTTP 500 for all of it; the workstation retried the same
  batch forever and nothing behind the bad record synced. Each record is now
  applied in its own transaction and reported individually (`synced`,
  `already_synced`, or `error` with a message). The engine already parked
  rejected records in `SYNC_ERROR` after `SYNC_MAX_ATTEMPTS`. Database-down
  errors still return HTTP 500 so they simply retry.
- **Changed: duplicate fine numbers across workstations.** Each workstation
  numbers its own fines, so two can both issue `FINE-2026-0002`. Centrally the
  number is UNIQUE, so the later one is stored as `FINE-2026-0002-xxxx`
  (suffix derived from its `sync_id`) and the engine logs a WARN. The receipt
  keeps the workstation's number. Giving each workstation its own number
  prefix would remove the clash entirely (not done).
- **Changed: service worker cache is now `municipal-shell-v3`** (purges the
  stale `offline-queue.js` and old cached pages) and precaches the SQLite
  engine and WASM files; the dead `assets/js/offline-queue.js` is deleted.

### Upgrading an existing install to 4.6

1. Copy this folder's files over your install. The package contains **no**
   `.env`, database, backups or uploads, so yours are not overwritten.
2. Open `setup_database.php` once (adds the sync columns/tables to an older
   SQLite file; safe to run again).
3. In the browser: DevTools, Application, Service Workers, **Unregister**,
   then Clear site data, then reload. Do this once per device.
4. Workstations: add `DEVICE_ID`, `CENTRAL_SERVER_URL` (base URL only, no
   `/api/...`) and `SYNC_API_KEY` to `.env` (see `.env.example`).
5. Workspace Server: select the **database first** in phpMyAdmin, then import
   `schema.mysql.sql` (importing without a database selected fails with
   `#1046 No database selected`).

## Version 4.5 - Workstation-to-Central-MySQL Sync (complete)

Version 3.5 (above) solved *one device losing its connection to this
app's own server*. This version solves a different problem: **more than
one physical workstation, each running this app against its own local
SQLite, consolidating into one central MySQL "Workspace Server" over the
municipal LAN** - local-first persistence, an idempotent background sync
engine, and master data (Users/Wards/Houses/WardAssignments) flowing back
down to every workstation.

Some of this already existed as a start - `includes/local_store.php`,
`cli_sync_engine.php`, `api/sync_push.php`, and the `DEVICE_ID` /
`CENTRAL_SERVER_URL` settings in `config.php` - but it wrote into a
second, separate SQLite file (`data/municipal_local.sqlite`) that nothing
else in the app ever touched, so it never held real data, and the one
page that displayed it wasn't linked from anywhere. This version finishes
it into something real:

- **One local database, not two.** `getLocalDb()` is now an explicit
  alias for the same `getDb()` connection every other page already uses -
  `sync_id` / `device_id` / `sync_status` / `synced_at` columns were added
  directly to `MeterReadings`, `Fines`, and `HouseVisits` instead (safe,
  additive `ALTER TABLE`s in `setup_database.php`, tested against an
  existing database with real rows already in it).
- **`api/save_reading.php`, `api/save_fine.php`, and `api/mark_visit.php`**
  now queue a `LocalSyncQueue` row in the same transaction as their normal
  insert/update, so a record can't exist locally without being queued for
  the center, or be queued without existing.
- **`cli_sync_engine.php`** is a real background worker now: it drains
  that queue to `api/sync_push.php` on the Workspace Server (bounded
  batches, exponential-in-spirit bounded retries, a row that keeps
  getting rejected lands in `SYNC_ERROR` after `SYNC_MAX_ATTEMPTS` instead
  of retrying forever), and separately pulls Users/Wards/Houses/
  WardAssignments back down from the new **`api/sync_pull.php`** -
  the "master data flows center-to-workstation, field data flows
  workstation-to-center" split. It tracks its own status (`WORKSPACE_
  UNAVAILABLE` / `ONLINE` / `SYNCING` / `SYNCHRONIZED` / `SYNC_ERROR`) in
  a `SyncEngineState` row that **`sync/status.php`** (new, Admin-only)
  displays, alongside anything stuck and needing attention.
- **A real security gap closed**: `api/sync_push.php` had no
  authentication at all before - anyone who could reach it on the LAN
  could write directly into the central MySQL. Both it and the new
  `api/sync_pull.php` now require a shared `X-Sync-Key` header
  (`SYNC_API_KEY` in `.env`, the same value on every workstation and the
  Workspace Server).
- **`schema.mysql.sql` now defines all 11 tables**, not 2 - it only had
  `Fines` and `MeterReadings`, so `migrate_to_mysql.php` (which already
  expected all 11) would have failed on the other 9 the first time anyone
  actually tried a full migration. `migrate_to_mysql.php`'s column lists
  were updated to carry the new sync columns across too.
- **The actual browser-side save flow is local-first now.**
  `meter-reading.js`, `fine-issue.js`, and `status-actions.js` previously
  tried the network *first* and only queued locally on failure or while
  `navigator.onLine` was already false - a crash, a closed tab, or simply
  a slow connection during that attempt could lose data that was never
  written anywhere. They now write to the local queue first, unconditionally,
  and only *then* attempt an opportunistic push - matching the durability
  guarantee this version is built around (a successful save means safely
  persisted on this device, not necessarily synced yet). One side effect:
  whether a reading looks abnormally high, or a fine looks like a possible
  duplicate, is server-side logic that compares against recent history, so
  under local-first saving it's only knowable once the queued record
  actually reaches the server - that warning now surfaces after sync
  completes instead of at the moment of saving.
- **A dead-code collision fixed**: `assets/js/offline-queue.js`
  (IndexedDB) and `assets/js/offline-db-sqlite.js` (real SQLite via
  WASM) defined the exact same global function names. Every
  offline-capable page loaded both - `offline-db-sqlite.js` always loaded
  second (via the global footer) and silently won, so `offline-queue.js`'s
  own IndexedDB store was created but never actually used. The redundant
  `<script>` tags were removed; `offline-db-sqlite.js` (loaded once,
  globally) is now the one real implementation.

**Deliberately out of scope this round** (same reasoning as Version 3.5
leaving out Cases and Damaged Meters): Cases and Damaged Meters still
don't participate in central sync - a `Fines.case_id` is never pushed to
the Workspace Server as-is (it's a local autoincrement id that means
nothing on a different database; see `api/save_fine.php`'s comment).
Duplicate-resolution and payment updates made *after* a record has
already synced don't currently re-sync - only the create-time write does.
`api/sync_pull.php` pulls a full master-data snapshot every time rather
than an incremental diff, which is simpler and fine at municipal scale but
worth revisiting if that data ever grows large. See section 6 below for
how to actually run more than one workstation against this.

**How this was verified**: with no PHP available in the environment this
was written in for Version 3.5, that release could only be reviewed by
eye. This one was actually run - a seeded MySQL "Workspace Server" and a
fresh SQLite "workstation" were both stood up for real, `cli_sync_engine.php`
was run as an actual background process against both, and the full loop
was exercised end-to-end: master data pulled down with exact IDs
preserved, a login using only those pulled credentials (proving the app
authenticates with zero connection to the center), a reading pushed back
up with its linked visit correctly cross-referencing the reading's
*central* id (not the workstation's local one), and - the actual point of
the exercise - a reading recorded with the Workspace Server and sync
engine both completely down, confirmed durably saved and queued rather
than lost, then confirmed it synced automatically with no user action
once the center came back. The browser-side queue (`offline-db-sqlite.js`)
was separately exercised against the real shipped WASM binary and a
spec-compliant in-memory IndexedDB, including a simulated page reload, to
confirm the SQLite blob genuinely persists rather than just looking
correct in isolation. What wasn't re-verified: the rest of the
application (Cases, Payments, Houses/Wards CRUD, Reports) - untouched by
this round of changes, and not re-tested here.


## 1. Requirements

- XAMPP (or WAMP) with PHP 8.1+ - the bundled `pdo_sqlite` extension is
  enabled by default, so no extra setup is needed for the database itself.
- A modern browser (Chrome, Edge, Firefox)

## 2. Setup steps

1. Copy the whole `municipal-system` folder into your XAMPP `htdocs` folder,
   so the path is `C:\xampp\htdocs\municipal-system` (Windows) or
   `/opt/lampp/htdocs/municipal-system` (Linux).
2. Start **Apache** from the XAMPP Control Panel (you do **not** need to
   start MySQL for the default SQLite setup).
3. Copy `.env.example` to a new file named `.env` in the same folder.
   The defaults inside already work out of the box - you only need to
   change anything if you want to switch to MySQL or add a future API key.
4. In your browser, go to
   `http://localhost/municipal-system/setup_database.php` and click through -
   this creates the SQLite database file and all eight tables for you
   (there is no phpMyAdmin-style tool for SQLite, so this replaces that step).
5. Make sure the `data/` and `uploads/damage_photos/` folders are writable
   (on Linux/Mac: `chmod -R 775 data uploads`). SQLite needs to write to
   `data/` for every request, not just at setup time.
6. Go to `http://localhost/municipal-system/setup_admin.php` and create
   your first Administrator account.
7. **Delete `setup_admin.php`** once that account is created (it has no
   protection of its own and should not be left on a live server).
8. Go to `http://localhost/municipal-system/login.php` and log in.
9. As Admin, go to **Users > Create User** to add an Officer, MeterReader,
   Clerk, PoliceSupervisor or MeterSupervisor account for testing each role.

### Switching to MySQL instead of SQLite

Open `.env` and set `DB_DRIVER=mysql`, then fill in `DB_HOST`, `DB_NAME`,
`DB_USER` and `DB_PASS`. You will need to convert `schema.sql` back to
MySQL syntax first (the version in this repo is SQLite syntax - mainly
`INTEGER PRIMARY KEY AUTOINCREMENT` instead of `AUTO_INCREMENT`, and `TEXT
... CHECK (... IN (...))` instead of `ENUM`) and import it via phpMyAdmin.

## 3. Trying it out

Once logged in as Admin, you can:

- **Dashboard** - live counts of open cases, unpaid fines, abnormal
  readings and pending meter repairs.
- **Cases > Record a Case** - open a police case; then **Issue Fine**
  straight from the case list to link a fine to it automatically.
- **Fines > Record Payment / Print Receipt** - record a payment and open
  the printable receipt (browser's Print > Save as PDF gives a real PDF).
- **Meter Readings > Capture Reading** - pick one of the three seeded
  houses (142, 087, 215); the previous reading auto-fills.
- **Damaged Meters > Report Damage** - report a fault with an optional
  photo upload; Admin can then update its repair status.
- **Users > Create User** - add accounts for each role without touching
  the database directly.
- **Wards > Create Ward** - two sample wards (Ward 1, Ward 3) already
  exist from setup; **Wards > Assign Readers** ties a MeterReader account
  to one or more wards.
- **Houses > Add House / Bulk Assign to Ward** - three sample houses
  exist already, each in one of the two sample wards.
- **Reports** - four filterable reports (Cases, Fines, Meter Readings,
  Damaged Meters), each with a Print button for a clean printed page.

**Important if you're testing as a MeterReader:** that role now only
sees houses in wards it's been assigned to (Version 2.5). Create a
MeterReader account, then go to **Wards > Assign Readers** as Admin and
tick that account for at least one ward - otherwise Meter Readings and
Damaged Meters will correctly, but perhaps confusingly at first, show
"you aren't assigned to any ward yet" instead of the sample houses.

### Testing the offline behaviour

1. Open **Meter Readings > Capture Reading** while online.
2. In Chrome DevTools, open the **Network** tab and switch it to **Offline**
   (or simply turn off your Wi-Fi/PC network adapter).
3. Capture a reading and click **Save Reading** - you'll see the orange
   "saved on this device" message, and the **Sync Now** counter will show
   1 pending.
4. Still offline, try one of the **Skip / Vacant / Locked / Illegal
   Connection / Revisit Required** buttons on the same page - same
   behaviour, queued instead of failing outright.
5. Go to **Fines > Issue a Fine**, still offline, and submit one - same
   pattern again (this is a different queue entry type, but the same
   underlying mechanism).
6. Go back online and click **Sync Now** on any of those pages (or just
   reload) - everything queued across *all three* actions uploads
   together, since Version 3.5 unified them into one queue, not three
   separate ones.

## 4. Accounts and roles

Use **Users > Create User** (Admin only) to create Officer, MeterReader,
Clerk, PoliceSupervisor and MeterSupervisor accounts once you have your first Admin account from
`setup_admin.php`. There is still no public self-registration screen -
by design, only an Admin can create accounts (Activity 4, Section 5.2).

## 5. Moving to MySQL for production

SQLite (the default) is great for development and for a small single-server
setup, but if you want **MySQL as the main, central database** - for
example once this goes on a live host, or if the municipality's IT
department already runs MySQL - here's how to move everything across:

1. Create a MySQL database and import the MySQL-flavoured schema:
   ```
   mysql -u root -p -e "CREATE DATABASE municipal_system"
   mysql -u root -p municipal_system < schema.mysql.sql
   ```
   (or use phpMyAdmin's Import tab with `schema.mysql.sql` - `schema.sql`
   is the SQLite version and won't work against MySQL.)
2. In `.env`, fill in `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` for that
   MySQL database. Leave `DB_DRIVER=sqlite` for now.
3. Visit `migrate_to_mysql.php` in your browser and click **Start
   Migration**. It copies every row from your local SQLite file into
   MySQL, table by table, in the right order so foreign keys line up,
   and re-syncs MySQL's auto-increment counters afterwards so new
   records don't collide with the migrated ones.
4. Once that finishes cleanly, change `.env` to `DB_DRIVER=mysql`. From
   that point on, the app reads and writes MySQL as the main database -
   your SQLite file is left untouched (nothing is deleted), so there's
   a safety net if you ever need to check something against it.

The migration script is safe to run more than once - it uses
`INSERT IGNORE`, so anything already copied across is simply skipped the
second time rather than duplicated.

**Why the app can switch drivers at all:** a small number of SQL date
functions differ between SQLite and MySQL (e.g. "readings from the last
30 days"). Those are isolated into two helper functions in `includes/db.php`
(`dbCurrentMonthMatch()` and `dbWithinLast()`) that return the correct SQL
for whichever `DB_DRIVER` is active, so the rest of the codebase - every
page, every query - is identical regardless of which database is behind it.

## 6. Deploying more than one workstation

Everything above (and everything in sections 1-5) works exactly the same
for a single standalone install - `DEVICE_ID`, `CENTRAL_SERVER_URL`, and
`SYNC_API_KEY` can all stay blank, and `cli_sync_engine.php` simply idles
with nothing to sync to. This section is only relevant once you want
**more than one** physical workstation (e.g. a ward office and a
municipal HQ) consolidating into one central database.

**Roles.** This is the same codebase running two ways:
- **Field workstation(s)**: `DB_DRIVER=sqlite` (the default). Runs
  everything normally; `cli_sync_engine.php` additionally runs in the
  background.
- **Workspace Server** (one, central): `DB_DRIVER=mysql`, schema imported
  from `schema.mysql.sql`. Exposes `api/sync_push.php` / `api/sync_pull.php`
  for workstations to call. Nobody needs to run `cli_sync_engine.php`
  *here* - it's the destination, not a source.

**Setup, in order:**
1. Set up the Workspace Server first: a normal install with
   `DB_DRIVER=mysql` (section 5 above covers importing `schema.mysql.sql`
   and configuring `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`). Create your
   real Users, Wards, Houses, and WardAssignments **here** - a field
   workstation should never create its own (see "Why master data only
   goes one way" below).
2. Generate a shared secret: `php -r "echo bin2hex(random_bytes(24));"`.
   Set it as `SYNC_API_KEY` in `.env` on the Workspace Server **and**
   every workstation - they must match exactly.
3. On each field workstation's `.env`: give it a unique `DEVICE_ID`
   (e.g. `WARD7-FRONTDESK`), and set `CENTRAL_SERVER_URL` to the
   Workspace Server's base URL, no trailing path
   (e.g. `http://192.168.1.10/municipal-system`).
4. Run `cli_sync_engine.php` on each workstation as a **persistent
   background process** - not something left running in a terminal
   someone might close:
   - **Windows (the likely case with XAMPP)**: use
     [NSSM](https://nssm.cc/) to install it as a Windows service
     (`nssm install MunicipalSync "C:\xampp\php\php.exe" "C:\xampp\htdocs\municipal-system\cli_sync_engine.php"`),
     or a Task Scheduler task set to run at startup with "repeat every
     1 minute" and "if already running, do nothing" as a poor man's
     supervisor.
   - **Linux**: a systemd unit -
     ```ini
     [Unit]
     Description=Municipal System sync engine
     After=network.target

     [Service]
     ExecStart=/usr/bin/php /path/to/municipal-system/cli_sync_engine.php
     Restart=always
     RestartSec=5
     WorkingDirectory=/path/to/municipal-system

     [Install]
     WantedBy=multi-user.target
     ```
     then `systemctl enable --now municipal-sync`.
5. Check `sync/status.php` (Admin-only) on the workstation - it shows the
   engine's current state, what's pending, and anything stuck in
   `SYNC_ERROR` that stopped retrying automatically.

**Why master data only goes one way.** `api/sync_pull.php` writes each
Users/Wards/Houses/WardAssignments row back with the **exact same
primary key** it has centrally - that's what lets a `MeterReadings.house_id`
mean the same house whether you're looking at it on a workstation or on
the Workspace Server. That only holds if a house (or user, or ward) is
always created **at the Workspace Server** and pulled down from there,
never created independently at a field workstation with its own local
autoincrement id. Field data - readings, fines, visit status - is the
opposite: created at the workstation, pushed up.

**A real trade-off worth knowing about**: `api/sync_pull.php` includes
`password_hash` for every user, because a field workstation needs to
authenticate a login locally even with zero connection to the Workspace
Server (offline login is part of "the app works regardless of LAN
availability"). This means every workstation's local SQLite file holds
real password hashes for every account, not just the people who use that
particular machine. Treat each workstation's disk accordingly (whole-disk
encryption, physical access control) - this is inherent to a
local-first-with-local-login design, not something a setting turns off.

## 7. Secrets and .env

Every setting that could vary between your machine and anyone else's -
or that is genuinely sensitive, like a future SMS gateway key from Chapter
7's Future Plans - lives in `.env`, never in the PHP source files
themselves. `config.php` reads `.env` at startup and falls back to safe
defaults if a value is missing.

`.env` is listed in `.gitignore`, so it will **not** be uploaded to GitHub.
Only `.env.example` (with blank/placeholder values) is committed, so anyone
who clones the repository knows what settings exist without ever seeing
your real values.

## 8. Putting this on GitHub

1. Create a new repository on GitHub (leave it empty - no README/license,
   since this project already has its own).
2. From inside the `municipal-system` folder:
   ```
   git init
   git add .
   git commit -m "Initial commit - core system"
   git branch -M main
   git remote add origin https://github.com/<your-username>/<your-repo>.git
   git push -u origin main
   ```
3. Double-check `.env`, `data/*.sqlite` and `uploads/damage_photos/*` did
   **not** get uploaded (GitHub's file listing should show `.env.example`
   but not `.env`). `.gitignore` in this project already takes care of this
   for you - you shouldn't need to do anything extra.
4. Anyone cloning your repository then follows Section 2 above, starting
   from "copy `.env.example` to `.env`".

### Pushing a feature branch (like this round's work)

The repository now has three branches:
- `main` - Versions up to 2.5
- `version-3.0-guided-field-operations` - branched off `main`
- `version-3.5-offline-sync` - branched off `version-3.0-guided-field-operations`
  (not off `main`, since it depends on that round's code)

None of this is merged into `main` yet, so you can review or test each
round separately before deciding to merge.

```
git push -u origin main
git push -u origin version-3.0-guided-field-operations
git push -u origin version-3.5-offline-sync
```

On GitHub, that gives you branches you can open Pull Requests from
(GitHub will offer a "Compare & pull request" button) to review each
diff before merging - or merge them yourself, **in order**, once you're
happy:

```
git checkout main
git merge version-3.0-guided-field-operations
git merge version-3.5-offline-sync
git push
```

(Merging 3.5 before 3.0 would fail/conflict, since 3.5's history already
contains 3.0's commits - merging 3.0 first, then 3.5, brings both in cleanly.)

## 9. Folder structure

```
municipal-system/
├── .env.example             <- copy to .env (real .env is gitignored)
├── .gitignore
├── config.php                <- loads .env, defines constants
├── schema.sql                <- SQLite schema, run via setup_database.php
├── schema.mysql.sql           <- MySQL schema, for production (Section 5) / a Workspace Server (Section 6)
├── setup_database.php        <- creates the SQLite file, run once
├── setup_admin.php           <- run once, then delete
├── migrate_to_mysql.php       <- one-time SQLite -> MySQL data migration
├── cli_sync_engine.php        <- background worker: workstation <-> central MySQL (Version 4.5, Section 6)
├── index.php
├── login.php / logout.php
├── dashboard.php
├── data/                      <- the SQLite database file lives here (gitignored)
├── includes/
│   ├── db.php                 <- PDO connection (SQLite or MySQL) + logActivity() + date-SQL helpers
│   ├── local_store.php         <- workstation-tier sync helpers: sync_id, LocalSyncQueue, engine state (Version 4.5)
│   ├── central_store.php       <- Workspace-Server-tier helpers: MySQL connection, X-Sync-Key check (Version 4.5)
│   ├── auth.php                <- session guard, roles, lockout, CSRF, password policy, security headers
│   ├── pagination.php          <- shared pagination + search box helpers
│   ├── header.php / footer.php
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── offline-db-sqlite.js <- the ONE active local queue: real SQLite via WASM (Version 4.0/4.5)
│       ├── meter-reading.js    <- capture page logic, local-first (Version 4.5)
│       ├── status-actions.js   <- Skip/Vacant/Locked/... buttons, local-first (Version 4.5)
│       ├── fine-issue.js       <- offline-capable fine issuing, local-first (Version 4.5)
│       └── nav.js               <- hamburger menu toggle
├── cases/
│   ├── list.php / create.php / update_status.php
├── fines/
│   ├── list.php / issue.php / pay.php / receipt.php
├── meter-readings/
│   ├── list.php / capture.php
├── damaged-meters/
│   ├── list.php / report.php / update_status.php
├── wards/
│   ├── list.php / create.php / assign.php
├── houses/
│   ├── list.php / create.php / edit.php / bulk_assign.php
├── users/
│   ├── list.php / create.php / login_history.php
├── reports/
│   ├── index.php / cases.php / fines.php / readings.php / damage.php
├── sync/
│   ├── conflicts.php           <- possible-duplicate review (Version 3.5)
│   └── status.php              <- this workstation's sync status - pending, errors, engine state (Version 4.5)
├── uploads/damage_photos/     <- photo uploads land here (gitignored)
└── api/
    ├── save_reading.php        <- meter readings, local-first (Version 3.0 / 4.5)
    ├── mark_visit.php          <- visit status marks, local-first (Version 3.5 / 4.5)
    ├── save_fine.php           <- fine issuing, local-first (Version 3.5 / 4.5)
    ├── sync_push.php           <- Workspace Server: receives pushes from workstations (Version 4.5)
    └── sync_pull.php           <- Workspace Server: serves master data to workstations (Version 4.5)
```

## 10. What's built vs. what's still on the roadmap

**Working now:** everything listed in Section 3 above, plus account
lockout, session timeout, and a full activity log of logins and data
changes (Chapter 4, Section 5.2).

**Designed in Chapter 4 but not yet built:**
- A full offline-installable app shell (service worker) - right now the
  *data* works offline once the Capture Reading page has loaded once, but
  the page itself still needs one online visit first.
- Editing/deleting existing records - Houses now has a proper edit screen
  (`houses/edit.php`, Version 2.5), but Cases, Fines and Meter Readings
  still only support create, list, and status/payment updates, not full
  edit-in-place.

**Version 2.0, 2.5, 3.0, 3.5, and 4.5 (mobile/security, ward-based
operations, guided field operations, browser-tier offline sync, and
workstation-to-central-MySQL sync) are all complete** - see the
changelog at the top of this file for the full list, including what each
deliberately left out (Version 3.5: offline Cases, offline Damaged Meter
reports with photos; Version 4.5: those same two, plus re-syncing
duplicate-resolution/payment updates made after a record already synced,
plus incremental - rather than full-snapshot - master data pulls). **Not
yet started:** Version 4.0's full installable PWA (a service worker so
the app shell itself loads offline, not just the data on pages already
visited), further progress/assignment tools beyond the current dashboard
widget, and the enterprise-tier features from Version 5.0 (PDF/Excel
export, analytics, a REST API, 2FA).

**Found during this security pass, not yet fixed:**
- `api/sync_push.php` and `api/sync_pull.php` (Version 4.5) are protected
  by a shared `SYNC_API_KEY`, but it's a single static secret shared by
  every workstation - if one workstation's `.env` leaks, every
  workstation needs a new key, not just that one. Per-workstation
  credentials (or mutual TLS, if this ever crosses an untrusted network)
  would be the real fix; a single shared LAN-only secret was judged
  proportionate for now.
- `setup_database.php`, `setup_admin.php`, and `migrate_to_mysql.php` are
  all one-time tools not gated behind a login (they can't be, really -
  `setup_admin.php` in particular has to run *before* any account exists).
  All three are now CSRF-protected, but someone who finds the URL directly
  could still trigger them. `setup_database.php` and `setup_admin.php`
  are self-limiting (they refuse to do anything once an Admin already
  exists), but `migrate_to_mysql.php` currently isn't - it'll happily
  re-run for anyone who reaches it. **Delete `migrate_to_mysql.php` once
  you've actually migrated**, the same way `setup_admin.php` tells you to
  delete itself. Worth revisiting properly (e.g. requiring an Admin login)
  before this ever sits on a real public-facing host rather than a local
  XAMPP install.

These are natural next additions on top of the same database and folder
structure - none of them require changing what has already been built.
