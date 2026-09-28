# User Manual
Municipal Police and Meter Reading Management System - Beitbridge Municipality

For Admin-only features (user management, backups, error log), see
`ADMIN_MANUAL.md` instead. This covers what every role uses day to day.

## Logging in

Go to the site's login page, enter your username and password. After 5
failed attempts, that username is locked for 10 minutes - if you're locked
out, wait or ask an Administrator to reset your password. You'll be logged
out automatically after 15 minutes of inactivity.

Your notification bell (top right) shows anything that needs attention for
your role - unpaid fines older than a week, abnormal meter readings, damaged
meters awaiting repair, and (for supervisors) duplicate readings and sync
failures. Click it to see details and jump straight to the relevant list.

## For Officers and Police Supervisors

**Opening a case** (Cases > + New Case): enter the offender's details,
offence type, and description. The Case Number is generated automatically -
you don't need to make one up. National ID, if entered, must be in the
format `63-123456A78` (2 digits, 6-8 digits, one letter, 2 digits) - the
form will tell you if it doesn't match.

**Issuing a fine** (Fines > Issue Fine): provide either a house number or a
vehicle plate (at least one is required). Vehicle plates must be in the
format `ABC 1234`. The Fine Number is generated automatically.

**Recording a payment**: open the fine from the Fines list and use the pay
action - this updates its status to Paid and records who took the payment
and when.

**Case Report / Fine Report** (Police Supervisor only): filter by date,
officer, ward, or status, then either print or Export PDF.

## For Meter Readers

**Capturing a reading** (Meter Readings > Capture): the page walks you
through your assigned houses one at a time - enter the current reading and
it moves you to the next house automatically. If the reading looks unusually
high compared to that house's history, you'll see a warning, but it still
saves - a supervisor reviews flagged ("abnormal") readings separately.

**Working offline**: if you lose signal mid-round, a banner tells you and
readings you capture keep saving to your device. They sync automatically as
soon as you're back online (or tap "Sync Now"), and a counter shows how many
are still waiting. Nothing is lost by going offline - just don't close the
browser tab/app before it finishes syncing once you're back online.

**Marking a house Skip / Vacant / Locked**: on the capture page if you can't
take a reading (nobody home, gate locked, etc.) - note this currently
requires a connection and doesn't queue offline the way a normal reading
does, so it's best used when you do have signal.

**Reporting a damaged meter** (Damaged Meters > Report): describe the damage
and attach a photo if you have one. A supervisor tracks it through Pending →
In Progress → Fixed.

## For Meter Supervisors

Everything a Meter Reader can do, plus:

- **Duplicate Readings**: review and resolve any house/date that got two
  conflicting readings (see Admin Manual section 4 for how to resolve one -
  the screen works the same regardless of role).
- **Reading Report / Damage Report**: filter and Export PDF, same pattern as
  the police reports above.

## For Clerks

Fines list and payment recording, same as described above for Officers, but
without case-creation access.

## Getting help

If something looks wrong or a page shows an error, note what you were doing
and tell an Administrator - it's logged automatically (see Admin Manual
section 7) and they can look up exactly what happened without you needing to
describe every detail.
