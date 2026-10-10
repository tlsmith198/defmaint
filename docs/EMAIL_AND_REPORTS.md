# Email notifications and AS9100 reports

Both features need the **`vendor/` folder** from the full release package (PHPMailer for email,
mPDF for PDF) and **PHP 8.0 or newer** (Select PHP Version → 8.1). Check Admin → Settings →
System Check: *Email & PDF libraries* should be green.

---

## Turn on email (10 minutes)

1. **Admin → Settings → Email.**
2. Fill in the SMTP details. Pick the one that matches where ADMI's mail lives:

   | Mail provider | Host | Port | Encryption |
   |---|---|---|---|
   | GoDaddy email / Workspace | `smtpout.secureserver.net` | 465 | SSL/TLS |
   | GoDaddy (alternative) | `smtpout.secureserver.net` | 587 | STARTTLS |
   | Microsoft 365 | `smtp.office365.com` | 587 | STARTTLS |

   Username is the full mailbox address; **From address** should be that same mailbox
   (GoDaddy and Microsoft reject a From address they do not own).
   GoDaddy shared hosting blocks plain port 25 and often `mail()`, so use the authenticated relay above.
   If Microsoft 365 refuses the login, an admin must allow *Authenticated SMTP* for that mailbox.
3. Put your own address in **Test recipient**, tick **Notifications enabled**, click
   **Save & send test email**. Check the inbox (and spam).
4. Make sure the nightly cron job exists (see INSTALL.md, step 5). It sends the daily digests and
   reminders and retries anything that failed.

### What gets sent, and to whom

| Notification | When | Default recipients |
|---|---|---|
| New issue | any issue is logged | asset owner |
| Major / Machine-down | issue severity Major or Down | all admins |
| Status changed | each lifecycle step | the person who reported it |
| Resolved | issue resolved | the person who reported it |
| Issue reminder | issue idle past its limit (Settings → Scheduling: 4 h down / 24 h major / 120 h minor), up to 10 times | assignee, admins cc |
| PM due today | nightly, one email per machine (daily tasks left out unless you tick the box in Email settings) | asset owner |
| PM overdue | nightly, one email per machine with overdue work | asset owner, admins cc |

Change recipients, wording and on/off under **Admin → Notifications** (Who gets what / Message
templates). Set each machine's **Owner** on the asset page — that is who "asset owner" means.

Every message is recorded in **Admin → Notifications → Delivery log** whether or not email is switched on,
with the failure reason if it did not go. *Retry* resends a failed one. Messages older than 2 days are
closed out as expired rather than sent late.

Email is sent just after the page finishes loading, so a slow mail server never holds up the shop floor.

---

## Reports

**Reports** in the main menu. Six reports, each as a screen view, **PDF** and **CSV**:

| Report | Use |
|---|---|
| Asset Maintenance History | one machine, date range: sign-offs, measured readings, issues, downtime, with a review signature block. Also the *Export PDF* button on every asset page. |
| PM Completion Log | who did what, when, across the fleet; filter by machine, frequency, result |
| PM Compliance Summary | on-time / late / failed / missed by frequency and by machine, with a compliance % |
| Overdue & Upcoming PM | working list: overdue now, next 7 / 30 days |
| Issue & Downtime Log | faults, resolution, downtime hours |
| Equipment Register | every asset with owner, status, last and next PM |

PDFs carry the company name, address and CAGE from **Settings → Branding**, the logo if you uploaded one,
the footer text, page numbers and who generated it and when. Downtime hours are recorded automatically when
a *Machine down* issue is resolved (time from report to resolution). Each PDF/CSV export is written to the audit trail.

Compliance % = tasks done on time and passed ÷ (on time + late + failed + missed + still overdue).
