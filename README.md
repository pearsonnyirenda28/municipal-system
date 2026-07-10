# Municipal Police and Meter Reading Management System

This is the working system documented in Chapters 1-7: **login and
role-based access, Police Cases, Fines (issue / pay / printable receipt),
Meter Readings (capture, with offline support and automatic sync), Damaged
Meter reports (with photo upload), User account management, and the four
filterable Reports** from Chapter 4, Section 2.2.

The database is **SQLite by default** - a single file, no separate
database server to install or configure. All settings (including the
database driver, if you ever want to switch to MySQL) live in a `.env`
file that is never uploaded to GitHub.

> Built and reviewed by hand, but **not executed** in the environment this
> was written in (no PHP available there to run it against). Please test
> locally and let me know if anything needs fixing - that's the next step.

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
- **Reports** - four filterable reports (Cases, Fines, Meter Readings,
  Damaged Meters), each with a Print button for a clean printed page.

### Testing the offline behaviour

1. Open **Meter Readings > Capture Reading** while online.
2. In Chrome DevTools, open the **Network** tab and switch it to **Offline**
   (or simply turn off your Wi-Fi/PC network adapter).
3. Capture a reading and click **Save Reading** - you'll see the orange
   "saved on this device" message, and the **Sync Now** counter will show
   1 pending.
4. Go back online and click **Sync Now** (or just reload the page) - the
   reading uploads to the database automatically and disappears from the
   pending count.

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

## 6. Secrets and .env

Every setting that could vary between your machine and anyone else's -
or that is genuinely sensitive, like a future SMS gateway key from Chapter
7's Future Plans - lives in `.env`, never in the PHP source files
themselves. `config.php` reads `.env` at startup and falls back to safe
defaults if a value is missing.

`.env` is listed in `.gitignore`, so it will **not** be uploaded to GitHub.
Only `.env.example` (with blank/placeholder values) is committed, so anyone
who clones the repository knows what settings exist without ever seeing
your real values.

## 7. Putting this on GitHub

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

## 8. Folder structure

```
municipal-system/
├── .env.example             <- copy to .env (real .env is gitignored)
├── .gitignore
├── config.php                <- loads .env, defines constants
├── schema.sql                <- SQLite schema, run via setup_database.php
├── schema.mysql.sql           <- MySQL schema, for production (Section 5)
├── setup_database.php        <- creates the SQLite file, run once
├── setup_admin.php           <- run once, then delete
├── migrate_to_mysql.php       <- one-time SQLite -> MySQL data migration
├── index.php
├── login.php / logout.php
├── dashboard.php
├── data/                      <- the SQLite database file lives here (gitignored)
├── includes/
│   ├── db.php                 <- PDO connection (SQLite or MySQL) + logActivity() + date-SQL helpers
│   ├── auth.php                <- session guard, roles, lockout, CSRF, password policy, security headers
│   ├── pagination.php          <- shared pagination + search box helpers
│   ├── header.php / footer.php
├── assets/
│   ├── css/style.css
│   └── js/
│       ├── offline-db.js       <- IndexedDB wrapper
│       ├── meter-reading.js    <- capture page logic + sync
│       └── nav.js               <- hamburger menu toggle
├── cases/
│   ├── list.php / create.php / update_status.php
├── fines/
│   ├── list.php / issue.php / pay.php / receipt.php
├── meter-readings/
│   ├── list.php / capture.php
├── damaged-meters/
│   ├── list.php / report.php / update_status.php
├── users/
│   ├── list.php / create.php / login_history.php
├── reports/
│   ├── index.php / cases.php / fines.php / readings.php / damage.php
├── uploads/damage_photos/     <- photo uploads land here (gitignored)
└── api/
    └── save_reading.php        <- JSON endpoint used by the offline JS
```

## 9. What's built vs. what's still on the roadmap

**Working now:** everything listed in Section 3 above, plus account
lockout, session timeout, and a full activity log of logins and data
changes (Chapter 4, Section 5.2).

**Designed in Chapter 4 but not yet built:**
- A full offline-installable app shell (service worker) - right now the
  *data* works offline once the Capture Reading page has loaded once, but
  the page itself still needs one online visit first.
- Editing/deleting existing records (cases, fines, readings) - everything
  currently supports create, list, and status/payment updates, not full
  edit-in-place.
- An admin screen to review the "possible duplicate" flags raised during
  meter reading sync (they're currently recorded in ActivityLogs, but
  there's no dedicated review screen yet).

**Found during this security pass, not yet fixed:**
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
