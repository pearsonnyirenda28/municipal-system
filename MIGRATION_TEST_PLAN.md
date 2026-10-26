# SQLite -> MySQL migration test plan

Run in this order. Tick each box and keep a screenshot for Chapter 5 / 6.4 evidence.

## 0. Before you start
- [ ] Copy `migrate_to_mysql.php` and `verify_migration.php` over your project.
- [ ] `.env`: ONE `DB_DRIVER=sqlite` line; DB_HOST / DB_NAME=municipal_system / DB_USER / DB_PASS filled in.
- [ ] Visit `setup_database.php` once (adds the client_ref / duplicate / discarded columns to the SQLite file).
- [ ] Back up `data/municipal_system.sqlite` (copy the file).
- [ ] MySQL: run `schema.mysql.full.sql`. Migration needs ALTER (it resets AUTO_INCREMENT):
      `GRANT ALTER ON municipal_system.* TO 'sync_app'@'localhost';`  (REVOKE it after step 3)

## 1. Make a few awkward records in SQLite first (so the test means something)
- [ ] Deactivate one user (Users > toggle active).
- [ ] Discard one duplicate reading in Meter Readings > Duplicates (soft delete).
- [ ] Issue a fine with an apostrophe / accent in the name (e.g. O'Brien).
- [ ] Note the counts on the dashboard.

## 2. Migrate
- [ ] Log in as Admin, open `migrate_to_mysql.php`, press the migrate button.
- [ ] Every table shows found = copied (re-running shows copied = 0; that is correct, it uses INSERT IGNORE).

## 3. Verify
- [ ] Open `verify_migration.php` -> must say ALL CHECKS PASSED (44 of 44 with the 11 tables).
- [ ] `REVOKE ALTER ON municipal_system.* FROM 'sync_app'@'localhost';`

## 4. Switch and smoke-test
- [ ] `.env`: DB_DRIVER=mysql. Log in again with an existing account (proves password hashes survived).
- [ ] The deactivated user is still refused at login.
- [ ] Dashboard, Fines list, Readings list, Duplicates, Cases, all 4 Reports open with no error.
- [ ] Issue a fine -> row appears in phpMyAdmin Fines, number continues after the migrated ones.
- [ ] Capture a reading twice with the same data -> second is "already synced", one row.
- [ ] Offline test: DevTools > Network > Offline, save a fine, go online, press Sync -> row in MySQL.

## 5. Rollback (if anything fails)
- [ ] Set DB_DRIVER=sqlite again. Your SQLite file was never modified by the migration.
- [ ] In MySQL: DROP DATABASE municipal_system; re-run schema.mysql.full.sql; try again.

## 6. After the test
- [ ] Delete `verify_migration.php`, `migrate_to_mysql.php`, `setup_admin.php` and `setup_database.php` from the folder you submit.
