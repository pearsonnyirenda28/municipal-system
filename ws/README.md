# Real-time notifications (WebSocket server)

The header notification bell (unpaid fines, abnormal readings, pending
repairs, unresolved duplicates, sync failures) normally refreshes on every
page load, computed live by `includes/notifications.php`. This folder adds
a second, optional layer: a standalone WebSocket server that pushes updates
to already-open browser tabs, so the bell (and a small glass toast in the
corner) updates within a few seconds of something happening, without
anyone needing to click or reload.

**This is entirely optional.** If it isn't running, nothing breaks - the
bell just goes back to only updating on page load, exactly as it did
before this existed.

## Why no library (Ratchet, etc.)

The usual way to do WebSockets in PHP is the Ratchet library via Composer.
This project has stayed dependency-free everywhere else - the vendored
copy of FPDF instead of a Composer package, no build step, no CDN fonts -
so `ws/WebSocketServer.php` implements just enough of the WebSocket
protocol (RFC 6455) by hand: the handshake, and text-frame
encoding/decoding. It's about 250 lines, and it's worth reading if you
want to understand how WebSockets actually work under the hood rather
than through a library's abstraction.

## Running it

```
php ws/server.php
```

Leave that running in its own terminal window (or see "Keeping it running"
below). You'll see:

```
WebSocket server listening on ws://0.0.0.0:8090
Polling every 3s per connected client. Ctrl+C to stop.
```

Reload any page in the app while logged in - the browser console (F12)
should show a WebSocket connection to `ws://localhost:8090/` if you want
to confirm it connected. Windows may prompt to allow `php.exe` through the
firewall the first time - allow it (this is only for connections *to* your
own machine on that port, nothing external).

## How it decides what to push

Every 3 seconds, for each connected browser tab, it re-runs the *exact
same* `getNotifications()` function the header bell itself uses - there is
deliberately no separate notifications table or event queue duplicating
that logic. If the result is different from what that tab was last sent,
it pushes the update. A toast only appears for messages that are new since
the last push (not on first connect, so you don't get a wall of toasts the
moment you open a tab).

## Authentication

The WebSocket handshake is still a plain HTTP request under the hood, so
the browser sends your normal `PHPSESSID` session cookie along with it
automatically - the server reads that cookie, looks up the matching PHP
session file, and uses whichever account you're logged in as. There's no
separate login step and no new credentials to manage. If your session
isn't valid (expired, logged out), the connection is closed immediately
rather than left open doing nothing.

## Keeping it running (production)

For a real deployment, don't run this in a terminal window you might
close - set it up the same way as the scheduled backup script
(`cli_backup.php`, see `docs/ADMIN_MANUAL.md` section 6):

- **Windows**: Task Scheduler, trigger "At startup", action
  `C:\xampp\php\php.exe C:\xampp\htdocs\municipal-system\ws\server.php`,
  with "Run whether user is logged on or not" so it survives a logout.
- **Linux/macOS**: a systemd service or `supervisord` entry that runs
  `php /path/to/municipal-system/ws/server.php` and restarts it if it
  ever exits.

## HTTPS deployments (wss://)

Browsers block a plain `ws://` connection from an `https://` page (mixed
content). If you deploy this app behind HTTPS, you'll need Apache to
reverse-proxy WebSocket traffic to this server using `mod_proxy_wstunnel`,
something like:

```apache
ProxyPass /ws/ ws://127.0.0.1:8090/
ProxyPassReverse /ws/ ws://127.0.0.1:8090/
```

and point `notifications-ws.js` at `wss://yourdomain/ws/` instead of a raw
port. This is a real step, not just a config toggle - out of scope for a
local XAMPP setup, but worth knowing about before a real deployment.
