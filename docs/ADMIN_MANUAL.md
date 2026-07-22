# Administrator Manual
Municipal Police and Meter Reading Management System - Beitbridge Municipality

This covers everything an Admin can do that other roles can't. For day-to-day
task instructions common to every role (logging in, issuing a fine, capturing
a reading), see `USER_MANUAL.md` instead.

## 1. First-time setup

See the main `README.md`, sections 1-2, for installing XAMPP, extracting the
project, creating the database, and running `setup_admin.php` to create your
first Admin account. Once that account exists, delete or rename
`setup_admin.php` - it refuses to create a second Admin, but there's no
reason to leave it reachable.

## 2. Managing user accounts (`Users` in the nav)

- **Create User**: full name, username, role, and an initial password (must
  meet the password policy shown on the form: 8+ characters, upper- and
  lower-case, and a number).
- **Reset Password**: sets a new temporary password for any account. Tell
  them the new password directly - it is never emailed or displayed again
  after this screen.
- **Activate / Deactivate**: an inactive account can't log in, and any
  session that account is *currently* using is ended within one page load
  (not just blocked on their next login attempt). Two safeguards are built
  in: you can't deactivate your own account, and you can't deactivate the
  last remaining active Admin - both would lock the system's own
  administration out with no way back in short of editing the database
  directly.
- **Login History**: every login attempt, successful or failed, with
  timestamp and IP address.

## 3. Wards and Houses

- **Wards**: create wards, and assign meter readers to a ward (a reader only
  sees houses in their assigned ward(s) when capturing readings).
- **Houses**: create/edit individual houses, or use **Bulk Assign** to move
  many houses to a ward or reading round at once.

## 4. Duplicate meter reading review (`Duplicate Readings` in the nav)

When two readings land on the same house on the same day (two readers
covering the same area, or a device re-sending a queued offline reading),
both are flagged instead of one silently overwriting the other. This screen
groups them by house + date so you can either keep one and discard the rest,
or confirm both were legitimate (e.g. a corrected re-read) and keep
everything. Every resolution is written to the activity log.

## 5. Reports (`Reports` in the nav)

Four reports - Cases, Fines, Meter Readings, Damaged Meters - each filterable
by date range, status, ward, officer, or house depending on the report. Every
report has an **Export PDF** button that generates a PDF of exactly what's
currently on screen (same filters), suitable for printing or emailing.

## 6. Backups (`Backups` in the nav)

- **Create Backup Now**: snapshots every table's data (not the database
  structure, which `setup_database.php` / `schema.mysql.sql` already
  recreate on their own) to a timestamped file under `/backups`.
- **Download**: saves that file to your computer - keep copies somewhere
  outside the server too, not just on it.
- **Restore**: replaces *all* current data with the backup's data. This is
  destructive and asks you to type `RESTORE` to confirm. Use it to recover
  from data loss or a bad import, not as an undo button for one mistake -
  it rolls back everything, not just one record.
- **Automatic daily backups**: run `php cli_backup.php` (in the project
  root) once a day via a scheduler:
  - **Linux/macOS (cron)**: `0 2 * * * /usr/bin/php /full/path/to/municipal-system/cli_backup.php >> /full/path/to/municipal-system/backups/cli.log 2>&1`
  - **Windows (Task Scheduler)**: Program `C:\xampp\php\php.exe`, Arguments
    `C:\xampp\htdocs\municipal-system\cli_backup.php`, trigger: Daily.
  The script keeps the 30 most recent backups and prunes anything older.

## 7. Error Log (`Error Log` in the nav)

Shows the most recent server-side errors and warnings - useful after a
report of "something went wrong" without needing FTP/shell access to the
server. Download the full log for anything older than what's shown.

**Before a real deployment**, set `APP_ENV=production` in `.env` (see
section 8) - this hides raw PHP errors from visitors (they see a friendly
"Something went wrong" page instead) and writes the details here rather
than on-screen. Left as `local` (the default), errors show directly in the
browser, which is useful while developing but should never go live like
that - a raw PHP error can reveal file paths and sometimes query details.

## 8. Deploying for real (not just running locally)

1. Copy `.env.example` to `.env` and set real values - at minimum
   `APP_ENV=production`, and either the SQLite path or the MySQL
   credentials depending on which you're using (see README.md section 5 for
   moving from SQLite to MySQL).
2. Confirm `.htaccess` is present in the project root (it ships with the
   repo) - it blocks direct browser access to `.env`, the SQLite file,
   backups, `.git`, and the one-time setup script, and disables directory
   listing. This only takes effect under real Apache, not the `php -S`
   development server.
3. Delete or rename `setup_admin.php` once your Admin account exists.
4. Schedule `cli_backup.php` (section 6).
5. Review the Error Log page occasionally, especially in the first few days
   after deployment.
