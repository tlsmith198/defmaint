# Install — the short version

Seven steps, about 15 minutes. Everything happens in GoDaddy cPanel.
(Longer version with menu paths and troubleshooting: `INSTALL.md`.)

---

### 1. Make the database

cPanel → **MySQL Database Wizard**

- Database name: `admimaint`
- User: `admiuser`, password from the **Generate** button
- Last screen: tick **ALL PRIVILEGES**

**Write down all three** — the full database name, full username, and password.
cPanel adds your account in front, so they look like `cpuser_admimaint`.

### 2. Set PHP to 8.1 or newer

cPanel → **Select PHP Version** → pick 8.1+ → **Set as current**

### 3. Upload

cPanel → **File Manager** → open the folder your domain points at (usually `public_html`)
→ **Upload** → send `ADMI_Maintenance_System.zip`

### 4. Extract

Right-click the zip → **Extract** → into that same folder → delete the zip afterwards.

You should now see `index.php` and `install.php` sitting in that folder.

### 5. Fix permissions

Still in File Manager: **Select All** → **Permissions**

- **644**, tick *Recurse*, choose **"Only apply to files"** → Change
- **755**, tick *Recurse*, choose **"Only apply to directories"** → Change

Skipping this gives you a blank "Access is forbidden" page.

### 6. Run the installer

Go to **yourdomain.com/install.php** and follow the six screens. It asks for the three
things from step 1, then creates your own login.

Leave **"Load the ADMI machine fleet"** ticked — that's all 15 machines and their
403 maintenance tasks already filled in.

### 7. Finish up

- The last screen has a **Delete install.php** button. Click it.
- Add the cron job it shows you: cPanel → **Cron Jobs** → *Once Per Day (4:00 a.m.)* → paste the command.
- Sign in → **Admin → Settings → Branding** → upload the ADMI logo.
- **Settings → Scheduling → Go-live date**: set the day the floor starts using it. Until then
  nothing shows as due, so the shop doesn't open to a list of overdue checks.

---

## Checking it worked

**Admin → Settings → System Check.** Green across the board means you're done.
Amber on *Record locking* is expected and correct — that's Setup mode, which lets you delete
tasks while you dial the schedule in. Switch it off when the schedule is final.
Amber on *Go-live date* just means the start date is still ahead of you.

## If a page is blank or forbidden

1. Redo step 5 — permissions are the usual cause.
2. Still stuck? Rename `.htaccess` to `.htaccess.bak` and reload.
3. Check the domain actually points at the folder you extracted into:
   cPanel → **Domains** → look at the document root.
