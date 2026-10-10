# Updating an installed system

## Easy way: Admin → Updates (after the one-time bootstrap below)

1. Sign in as an admin → **Admin → Updates** (or Settings → Updates tab).
2. Choose the new `ADMI_Maintenance_System.zip` → **Upload & review**.
3. Check the review screen (changed / new files, any database changes) → **Install update**.
4. Hard-refresh the browser (Ctrl+F5).

What it does for you: backs up every file it replaces, never touches `config/config.php`, `/uploads`
or `install.php`, never deletes files, runs any new `sql/migrations/*.sql` once, and puts everything
back automatically if a write fails. **Roll back** on the same page restores a backup (files only;
database changes are not undone). It works on GoDaddy cPanel shared hosting **without editing php.ini or changing any PHP setting**:
it reads zips with the PHP zip extension if present, or a built-in reader otherwise; it keeps backups as
plain folders; it writes each file atomically; and it keeps running if the browser disconnects.

**If the upload is refused** (GoDaddy's firewall occasionally blocks uploads that contain PHP, or a
tiny `upload_max_filesize`): cPanel → **File Manager** → open `maintenance/tmp/updates/inbox/`
(create the `inbox` folder if it is not there) → **Upload** the zip → back on the Updates page pick it
under *Or use the inbox folder* → Review → Install. No web upload and no size limit are involved.

**If install says it could not write a file:** cPanel → File Manager → select the app folder →
Change Permissions → folders 755, files 644 (never 777 - GoDaddy's suEXEC treats that as an error).

**One-time bootstrap:** the updater first has to exist on the server. Install this build the manual
way (steps below) once; every update after that can use the Updates page.

---

## Manual way (fallback, and for the one-time bootstrap)

This update replaces application files only. **There is no database change**, so nothing
needs to be migrated and no data is touched.

Takes about ten minutes, most of it the backup.

---

## 1. Back up first (do not skip)

**Database** — cPanel → **Databases → phpMyAdmin** → select your database in the left column →
**Export** tab → **Go**. Save the `.sql` file somewhere you will find it again.

**Files** — cPanel → **File Manager** → open `public_html` → right-click the `maintenance`
folder → **Compress** → *Zip Archive* → **Compress File(s)**. That leaves a dated zip beside
the folder you can restore from.

---

## 2. Upload the new files

1. File Manager → open `public_html/maintenance` (the folder that contains `index.php`).
2. Click **Upload** and send `ADMI_Maintenance_System.zip`.
3. Back in File Manager, right-click the zip → **Extract** → extract into that same
   `maintenance` folder.
4. When cPanel asks about existing files, choose to **overwrite**.
5. Delete the zip file when it has finished extracting.

### What is safe

The package deliberately does **not** contain:

- `config/config.php` — your database credentials stay exactly as they are
- anything you have uploaded — machine photos, documents, the company logo

Those are untouched by the extract.

---

## 3. Two things right after

**Delete `install.php` again.** The package includes it for fresh installs, so extracting
puts it back. It locks itself once a system is installed and refuses to run, but it should
not sit on a live server. File Manager → select `install.php` → **Delete**.

**Force a fresh stylesheet.** The CSS changed, so do one hard refresh in your browser —
Ctrl+F5 on Windows, Cmd+Shift+R on Mac. Otherwise the new pages can look half-styled.

---

## 4. Confirm it worked

Sign in and go to **Admin → Settings → System Check**.

- *Application build* should read the new build stamp
- *Schema* should read 21 tables
- *Scheduled job (cron)* should be green, or amber if the nightly cron has not run yet
- *Installer removed* should be green — if it is red, `install.php` is still on the server

Then open **Maintenance**. The first visit generates the upcoming due dates if the nightly
cron has not already done it, so the page may take a second or two the very first time.

---

## If something looks wrong

**Pages look unstyled** — hard refresh (Ctrl+F5). If that does not fix it, the shop's
connection may be blocking the Bootstrap CDN; see the CDN note in `README.md`.

**"Setup required" page** — `config/config.php` was overwritten or deleted. Restore it from
your backup zip; it is the only file in the package that carries your database password.

**A page errors after the update** — restore is straightforward: delete the `maintenance`
folder, extract your backup zip in its place, and the previous version is back. The database
is untouched by the update, so it will match either version.

**Everything else** — cPanel → **Metrics → Errors** shows the PHP error log, and
`error_log` in the application folder catches anything the app itself logged.

---

## What changed in this build

| Area | Change |
|------|--------|
| Go-live date | New — set the day the schedule starts in Settings → Scheduling. Clears accumulated overdue and missed items and re-spreads every task from that date, keeping sign-off history |
| Deleting tasks | New — delete a maintenance task outright instead of only retiring it, singly or in bulk from the requirement list |
| Setup mode | New switch in Settings → General. While on, tasks, machines and sign-off records with history can be deleted; turning it off locks the record so history can only be retired or corrected |
| Clean-up tools | New — delete all retired tasks, delete every task on a machine, rebuild the schedule from current frequencies, clear trial sign-off records |
| Deleting machines | New — delete a machine and its whole schedule from the asset edit page, confirmed by typing its tag |
| Audit trail | Every deletion records a snapshot of what was removed before the rows go |
| System Check | Now reports whether the record is locked, and the application build |

No database schema change. The one new setting (`setup_mode`) defaults to on, so an existing
install gains the delete tools as soon as the files are in place.
