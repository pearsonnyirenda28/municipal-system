# Workspace Server Setup — Municipal System

Quick reference for setting up **this machine** (192.168.1.100) as the
central Workspace Server. Keep it next to `setup_municipal_sync_service.bat`
— that script is for field workstations only. This machine doesn't run it
(see the mental model at the bottom for why).

## 1. Database and a dedicated app user

In phpMyAdmin (`http://localhost/phpmyadmin` on this machine), SQL tab:

```sql
CREATE DATABASE municipal_system CHARACTER SET utf8mb4;
CREATE USER 'sync_app'@'localhost' IDENTIFIED BY 'choose-a-real-password-here';
GRANT ALL PRIVILEGES ON municipal_system.* TO 'sync_app'@'localhost';
FLUSH PRIVILEGES;
```

Don't run the app as MySQL `root` — this server is reachable by every
workstation on the LAN, so a dedicated, limited user is worth it from
the start.

## 2. Import the schema

phpMyAdmin → `municipal_system` database → **Import** tab → choose
`schema.mysql.sql` from the project → Go. Creates all 11 tables.

## 3. Deploy the codebase

The same project files as every workstation, copied into this machine's
`htdocs\municipal-system`. Only `.env` differs from a workstation's.

## 4. This machine's `.env`

```
DB_DRIVER=mysql
DB_HOST=localhost
DB_NAME=municipal_system
DB_USER=sync_app
DB_PASS=choose-a-real-password-here

DEVICE_ID=WORKSPACE-SERVER
CENTRAL_SERVER_URL=
SYNC_API_KEY=a5e054346c290bc54cf5f8114a41ee1000e5cecca91d0603
```

`CENTRAL_SERVER_URL` stays **blank** — this machine is the destination,
not a source. `SYNC_API_KEY` must match every workstation's `.env`
**exactly** (this is the same key already set on WORKSTATION-01).

## 5. Open the firewall

Run as Administrator, on this machine:

```
netsh advfirewall firewall add rule name="Municipal System HTTP" dir=in action=allow protocol=TCP localport=80
```

Without this, Apache answers `localhost` fine but silently refuses every
*other* machine on the LAN — the most common cause of a workstation
stuck on `WORKSPACE_UNAVAILABLE` that has nothing to do with the app
itself.

## 6. Verify the chain end to end

From a **workstation's** browser:
`http://192.168.1.100/municipal-system/api/ping.php`
should return `{"ok":true,...}`. If it hangs or times out, re-check
step 5 first — it almost always is.

Then check that workstation's Sync Status page — it should leave
`WORKSPACE_UNAVAILABLE` within one poll cycle (5s by default).

## Quick mental model

```
Local SQLite (LocalSyncQueue)
        |
        v
cli_sync_engine.php    <- kept alive by NSSM, on EACH WORKSTATION only
        |  (HTTP POST, over the LAN)
        v
api/sync_push.php      <- runs under THIS machine's own Apache
        |  (PDO/MySQL write)
        v
Central MySQL           <- this machine, DB_DRIVER=mysql
```

This machine never runs `cli_sync_engine.php` and never needs NSSM — it
is always the one being pushed to, never the one doing the pushing.
