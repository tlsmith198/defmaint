# ADMI Equipment, Asset & Maintenance Management System

Self-hosted PHP/MySQL application for **Aerospace & Defense Manufacturing Inc.** (CAGE 97WZ5).
Unified asset register, preventive-maintenance scheduling, issue logging with email notification,
and AS9100-ready completion records.

Target environment: **GoDaddy shared cPanel hosting** (cPanel-managed MySQL, cron via
cPanel → Advanced → Cron Jobs, PHP version set under *Select PHP Version*).

---

## Build status

| Phase | Scope | Status |
|-------|-------------------------------------------|--------|
| 1 | Database schema | **Delivered** — `sql/schema.sql`, documented in `docs/SCHEMA.md` |
| 2 | Asset register (CRUD, list, detail, docs) | **Delivered** |
| 3 | PM requirement builder + clone across assets | **Delivered** |
| 4 | Due engine, calendar, dashboard due list | **Delivered** |
| 5 | Shop-floor completion logging | **Delivered** |
| 6 | Issue lifecycle + PHPMailer notification engine | **Delivered** — issues, lifecycle, audit, email (instant + nightly digests/reminders), delivery log, recipient rules, templates |
| 7 | mPDF AS9100 reports | **Delivered** — six reports, each as screen / PDF / CSV |
| — | **Run Checks** (Maintenance → Run Checks): one tap-to-sign-off sheet per Daily / Weekly / Monthly / Quarterly / other, across all machines; the classic due list and per-machine batch form remain | **Delivered** |
| — | In-app updater (Admin → Updates): upload zip, review, install, roll back | **Delivered** |
| 8 | Permissions pass + GoDaddy deployment hardening | **Delivered** (gate, settings, cron, System Check incl. mail/PDF libraries, in-app updater) |

### Deleting vs. retiring

A live AS9100 maintenance record must not destroy objective evidence, but that rule gets in the
way while the schedule is still being tuned. So deletion runs in two tiers, governed by the
**Setup mode** switch in Admin → Settings → General:

| | Setup mode ON | Record locked (off) |
|---|---|---|
| Task with no sign-off history | delete | delete |
| Task with sign-off history | delete (takes its records with it) | retire only |
| Sign-off record | delete | correct, with a revision trail |
| Machine with history | delete | set to Retired |

Both tiers write a full snapshot of what was removed to `audit_log` *before* deleting, so the
audit trail evidences the deletion even though the rows are gone. New installs start in Setup
mode; the banner across the top of every admin page is the reminder to lock the record once the
schedule is final.

`Rebuild the schedule` (per machine, or fleet-wide in Settings) throws away unsigned due dates
and recalculates them from the current frequencies — useful after editing a batch of intervals,
and safe at any time because signed-off work is never touched.

### How the schedule works

One open occurrence exists per active requirement at a time. Future dates are projected on
demand for the calendar rather than persisted, because in **rolling** mode the next due date
depends on when the work is actually signed off. Requirements can instead use **fixed calendar**
mode (a set weekday or day of month). When a whole cycle is skipped, the catch-up loop closes
the stale occurrence as *missed* and opens the next one, so the compliance figures count
honestly instead of showing one task 40 days late.

On first load the 403 seeded requirements are **staggered** across the planning horizon rather
than all falling due on day one — daily checks start on day one, longer cycles spread
deterministically.

**Go-live date** (Settings → Scheduling) is the day the floor actually starts using the system.
Nothing falls due before it. Setting it clears every unsigned due date and missed record, then
re-spreads the whole schedule from that day — which is the fix for a system that sat installed
for weeks and accumulated hundreds of overdue items nobody was ever asked to do. Sign-off
history is never touched, and the reset is recorded in the audit trail with before/after counts.

Email setup and the report pack are described in [`docs/EMAIL_AND_REPORTS.md`](docs/EMAIL_AND_REPORTS.md).

---

## Requirements

- PHP **8.1 or newer** (8.0 minimum). Email and PDF reports require 8.0+; the rest of the app still runs on 7.4
  (compatibility shims for `str_starts_with` etc. are in `includes/bootstrap.php`)
- The `vendor/` folder (PHPMailer, mPDF) ships in the full release package — upload it with the app
- Extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `openssl`
- MySQL 5.7+ / MariaDB 10.3+
- Bootstrap 5 and Bootstrap Icons load from jsDelivr CDN. If the shop needs the app to work
  without internet access, download both into `assets/vendor/` and change the two `<link>`
  tags in `templates/header.php` plus the pair in `login.php`.

---

## Install on GoDaddy shared hosting

**Step-by-step with cPanel menu paths: [`docs/INSTALL.md`](docs/INSTALL.md).** Short version:

1. cPanel → **MySQL Database Wizard** — create a database and user, tick **ALL PRIVILEGES**.
2. cPanel → **Select PHP Version** — 8.1 or newer.
3. Upload `ADMI_Maintenance_System.zip` into `public_html/maintenance/` and extract it.
4. Browse to `https://yourdomain/maintenance/install.php` and follow the six steps.
5. Delete `install.php` with the button on the last screen.

The installer:

- checks PHP version, extensions, upload limits, sessions and folder permissions before
  asking for anything, and creates the `uploads/` tree itself
- tests the database connection *before* writing any file, and translates MySQL errors into
  plain instructions ("the user was never added to the database", "the name needs the cPanel prefix")
- falls back to a **Download config.php** button if the hosting account will not let PHP write
  into `/config` — no dead end, no FTP required
- resumes wherever it left off, and refuses to jump to a step whose prerequisites are missing
- optionally loads the 15-machine ADMI fleet and its 403 PM requirements
- generates the cron token and hands you the exact cPanel cron command, with a copy button
- locks itself once installed, and offers to delete itself

Everything it checks stays available afterwards at **Admin → Settings → System Check**, which
also flags a cron that has stopped running and warns if `install.php` is still on the server.

### Manual install (no installer)

```sh
cp config/config.sample.php config/config.php   # fill in DB credentials
mysql -u USER -p DBNAME < sql/schema.sql
mysql -u USER -p DBNAME < sql/seed_reference.sql
mysql -u USER -p DBNAME < sql/seed_admi_machines.sql   # optional fleet seed
```

Then create an admin row manually, or run `install.php` for the account step only.

---

## Folder structure

```
/config      config.php (git-ignored), config.sample.php
/includes    bootstrap, db, auth, helpers, csrf, settings, upload, assets_lib
/modules     assets/, maintenance/, issues/, reports/, admin/, settings/
/templates   header, footer, 403, coming_soon
/assets      css/, js/, img/
/uploads     assets/, documents/, completions/, issues/, branding/  (writable)
/sql         schema.sql, seed_reference.sql, seed_admi_machines.sql
/cron        run.php  (single scheduled-job entry point)
/docs        SCHEMA.md
```

Every page begins with `require_once .../includes/bootstrap.php`, then a single gate call:
`require_login()` or `require_admin()`. Role checks are never inlined — `require_admin()` in
`includes/auth.php` is the one place to audit or extend.

---

## Security notes

- Session cookies are HttpOnly + SameSite=Lax, and Secure when served over HTTPS.
- Every state-changing POST carries a CSRF token (`csrf_field()` / `require_post_csrf()`).
- Six failed sign-ins lock an account for 15 minutes.
- All queries are prepared statements via PDO with emulation off.
- Uploads are MIME-sniffed with `finfo`, executable extensions are rejected, and
  `uploads/.htaccess` strips any handler that could execute a file that slipped through.
- `config/`, `sql/`, `includes/` and `tmp/` are blocked at the web server level.

## AS9100 behaviour

- Nothing is hard-deleted. Assets retire, users deactivate, documents void — the rows remain.
- Every asset change, status change, sign-in, upload and export writes to `audit_log`
  with user, timestamp and IP.
- Maintenance completions (phase 5) are append-only; corrections are written to
  `completion_revisions`, never overwritten in place.
