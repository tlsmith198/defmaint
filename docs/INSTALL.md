# Installing on GoDaddy — quick start

About 15 minutes, no coding. You need your cPanel login and nothing else.

---

## 1. Create the database (5 minutes)

1. Sign in to GoDaddy → **My Products** → your hosting plan → **cPanel Admin**.
2. In cPanel, find **Databases → MySQL Database Wizard**.
3. **Step 1 — database name:** type `admimaint`. cPanel turns it into something like
   `cpuser_admimaint`. **Write the full name down.**
4. **Step 2 — user:** type `admiuser`, and use the **Password Generator** for the password.
   **Write down the full username and the password.**
5. **Step 3 — privileges:** tick **ALL PRIVILEGES**, then **Next Step**.

> This is the step people most often get wrong. If ALL PRIVILEGES is not ticked, the installer
> connects fine but cannot create tables.

---

## 2. Check the PHP version (1 minute)

cPanel → **Software → Select PHP Version**. Choose **8.1** or newer, click **Set as current**.

While you are there, click **Extensions** and make sure these are ticked:
`pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `openssl`.

---

## 3. Upload the application (3 minutes)

> Use the **full** release zip — it includes the `vendor/` folder (about 12 MB) that email and PDF reports need.

1. cPanel → **Files → File Manager** → open **public_html**.
2. Create a folder called `maintenance` and open it.
3. Click **Upload**, and upload `ADMI_Maintenance_System.zip`.
4. Back in File Manager, right-click the zip → **Extract** → extract into the `maintenance` folder.
5. Delete the zip file once it has extracted.

You should end up with `public_html/maintenance/install.php`, `/modules`, `/includes` and so on.
If everything landed inside an extra `ADMI_Maintenance_System` folder, either move the contents up
one level or just use that folder in the address below.

---

## 4. Run the installer (5 minutes)

Go to **https://yourdomain.com/maintenance/install.php** and follow the six steps.

| Step | What happens |
|------|--------------|
| Before you start | Reminder of what to have ready |
| Server check | Confirms PHP version, extensions, folder permissions. Click **Create folders & re-check** if anything is amber |
| Database | Enter the name, user and password from step 1. The installer tests the connection before saving anything |
| Create tables | Creates 21 tables. Leave **Load the ADMI machine fleet** ticked to start with all 15 machines and their PM schedules |
| Administrator | Your own login for the system |
| Finish | Gives you the cron command and a button to delete the installer |

---

## 5. Three finishing touches

**Delete the installer** — the last screen has a button. If it says it cannot, delete
`install.php` in File Manager.

**Add the nightly cron job** — cPanel → **Advanced → Cron Jobs**. Choose *Once Per Day (4:00 a.m.)*
from **Common Settings** and paste the command the installer gave you. It looks like:

```
/usr/local/bin/php /home/cpuser/public_html/maintenance/cron/run.php abc123...
```

This is what generates upcoming maintenance due dates and sends reminders. You can confirm it is
running under **Admin → Settings → System Check**.

**Upload the logo and SMTP details** — sign in, then **Admin → Settings**:
- *Branding* — upload the ADMI logo (PNG with a transparent background is best)
- *Email* — SMTP host, port, username and password for outbound notification email

---

## If something goes wrong

| What you see | What it means | Fix |
|---|---|---|
| "The database username or password was rejected" | Wrong credentials, or the user was never added to the database | cPanel → MySQL Databases → *Add User To Database* → ALL PRIVILEGES |
| "That database does not exist" | The account prefix is missing | Use the full name, e.g. `cpuser_admimaint`, not `admimaint` |
| "PHP is not allowed to write into the /config folder" | Hosting restriction — not an error you caused | The installer offers a **Download config.php** button. Upload that file into the app's `config` folder and click *I have uploaded it* |
| "command denied to user" while creating tables | ALL PRIVILEGES was not granted | cPanel → MySQL Databases → *Add User To Database* → ALL PRIVILEGES, then re-run the step |
| Uploads folder not writable | Folder permissions | File Manager → right-click `uploads` → *Change Permissions* → 755 (try 775 if 755 is not enough) |
| Blank white page | PHP error with display off | cPanel → **Metrics → Errors**, or check `error_log` in the app folder |
| "Setup required" page | `config/config.php` is missing | Click *Start the installer* on that page |
| Styling looks broken | Bootstrap loads from a CDN and the browser has no internet | See the CDN note in `README.md` for hosting Bootstrap locally |

Everything the installer checks is also available afterwards at
**Admin → Settings → System Check**, so you can re-run the diagnosis at any time without the
installer being on the server.
