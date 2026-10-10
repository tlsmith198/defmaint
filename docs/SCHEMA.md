# Database schema — for review before phase 3

21 tables, InnoDB, utf8mb4. Full DDL in `sql/schema.sql`.
This document explains the shape and flags the decisions worth a second look.

---

## 1. Access & configuration

**`users`** — this app owns its own accounts; nothing is shared with FAI Manager.
`role` is constrained to `admin` / `user`. Carries `initials` (for printed logs),
`must_change_pw`, and `failed_logins` / `locked_until` for lockout. Accounts are
deactivated, never deleted, so historical attribution survives.

**`settings`** — key/value with a `setting_group` (branding, general, schedule, email, security)
and an `is_secret` flag for the SMTP password. This is where the uploaded **logo path**,
CAGE code, date formats, tag prefixes, reminder thresholds and cron token live, so you can
retune the system without touching code.

---

## 2. Asset register

**`assets`** — one row per machine. `asset_tag` (`EQ-0001`, prefix and width configurable) is
the unique business key. Beyond the obvious identity fields it carries `machine_type`,
`control_system`, `year_built`, `owner_user_id` (the notification target), `photo_path`,
`hour_meter` + `hour_meter_at`, and a five-state `status`
(*active / down_maintenance / down_repair / out_of_service / retired*) with
`status_changed_at` and `status_note`.

**`asset_categories`**, **`locations`** — small lookup tables, seeded from the Machine
Capability Portfolio categories (Small 3-Axis through Large 5-Axis, plus lathe, saw, deburr,
oven, support equipment, facilities).

**`asset_specs`** — free-form `spec_group` / `spec_key` / `spec_value` rows rather than 30 sparse
columns on `assets`. Travels, table size, spindle, taper, ATC capacity and the lubrication
grades from the portfolio workbook all land here, so a saw or an oven is not carrying empty
5-axis columns.

**`attachments`** — one polymorphic table (`entity_type` + `entity_id`) for manuals, warranty
documents, lube charts, completion photos and issue photos. Removal is a soft `is_void` with
`voided_by` / `voided_at`; the row and the file stay for audit.

> **Confirm:** inspection equipment is *out of scope* per your answer — this register covers
> manufacturing equipment only, and calibration stays in FAI Manager. If that changes later,
> a `cal_reference` column on `assets` is the low-friction way to cross-link rather than
> duplicating calibration due dates.

---

## 3. Preventive maintenance

**`maintenance_requirements`** — the PM definition, one row per task per asset.
Frequency enum (daily → annual + custom) with a resolved `interval_days`, plus
`schedule_mode` = `rolling` (next due = last completion + interval) or `fixed_calendar`
(with `fixed_day_of_month` / `fixed_weekday`). Also `grace_days`, `tools_consumables`,
`est_minutes`, `assigned_role` or `assigned_user_id`, `is_safety_critical`, and
`template_key` — the handle that lets one requirement be cloned across every 5-axis mill and
still be recognised as the same task later. `is_active` + `retired_at` retire a requirement
without touching its history. `last_completed_at` / `next_due_date` are denormalised for fast
list rendering; the authoritative schedule lives in `maintenance_due_instances`.

Unique key on `(asset_id, frequency, task_name)` keeps the seed re-runnable and prevents
accidental duplicates.

**`requirement_steps`** — optional checklist lines inside one requirement, each with a
`response_type` of done / pass-fail / **value** / text. Value steps carry `value_unit`,
`value_min`, `value_max` — that is how the weekly coolant check records Brix concentration
(8–12 %) and pH (floor 8.7) as real measurements instead of a checkbox.

**`maintenance_due_instances`** — generated occurrences. `(requirement_id, due_date)` is unique
so a re-run of the due engine is idempotent. `days_late` is stamped at completion and is what
the fleet PM-compliance report counts.

---

## 4. Completion records (the AS9100 evidence)

**`maintenance_completions`** — append-only. It snapshots `task_name_snapshot`,
`frequency_snapshot` and `due_date_snapshot` so a completion from 2024 still prints exactly
what was asked of the tech that day, even if the requirement was reworded in 2026.
Records `result`, `completed_by`, `completed_at`, `duration_minutes`, `hour_meter`, `notes`,
`submitted_ip`, and `issue_id` when a failed check spawns an issue.

**`completion_step_results`** — per-step outcome, with `measured_value` and a computed
`in_range` flag.

**`completion_revisions`** — corrections are recorded here with old value, new value, reason,
who and when. `maintenance_completions.revision_no` increments. Nothing is ever silently
overwritten.

---

## 5. Issues

**`issues`** — `issue_no` (`ISS-2026-0001`), severity *minor / major / down*, and a lifecycle
of open → acknowledged → in_progress → resolved → closed. Each transition has its own
`*_by` / `*_at` column pair on the row (fast to query, no join needed for the common case),
and **`issue_status_history`** additionally holds the full immutable transition log.
`last_reminder_at` / `reminder_count` drive the overdue-issue reminder without re-sending on
every cron pass. `downtime_minutes` supports downtime trending.

**`issue_notes`** — the correspondence thread; `note_type` separates human comments from
system and email entries.

---

## 6. Notifications

**`notification_templates`** — one editable HTML template per event type
(`pm_due_today`, `pm_overdue`, `issue_new`, `issue_critical`, `issue_status_changed`,
`issue_resolved`, `issue_overdue`), each seeded with ADMI-styled default copy.

**`notification_recipients`** — recipient *rules* rather than hardcoded addresses:
a literal email, a specific user, everyone in a role, the asset owner, the issue reporter,
or the assignee — with `send_as` (to/cc/bcc) and an optional `min_severity` so Major and Down
escalate further than Minor.

**`notification_log`** — every send attempt with recipients, subject, body snapshot, status and
error text. This is both the audit trail and the retry queue.

---

## 7. Audit & operations

**`audit_log`** — user, action (`asset.create`, `asset.status_change`, `user.password_reset`,
`login.fail`, `settings.update`, …), entity, JSON detail of changed fields, IP, timestamp.
Append-only; the admin UI is read-only over it.

**`job_runs`** — proof the cPanel cron actually fired: job key, trigger type (cron / web /
manual), start, finish, status, items processed. On shared hosting this is the difference
between "the schedule is fine" and "the cron has been dead for three weeks".

---

## Deletion policy

`includes/purge.php` is the only place that deletes anything. It enforces two tiers:

- **Always allowed** — rows with no completion history behind them. A requirement nobody has
  signed off is evidence of nothing, so removing it destroys nothing. Cascades handle
  `requirement_steps` and `maintenance_due_instances`.
- **Setup mode only** — rows that *do* carry history. The `setup_mode` setting gates this.
  Forcing a delete removes the dependent `maintenance_completions` first (they hold RESTRICT
  foreign keys to requirements, assets and users), then the parent.

Issues raised from a deleted PM check survive; their `requirement_id`, `due_instance_id` and
`completion_id` are set to NULL rather than cascading, so a fault report is never lost because
someone tidied up a task definition. Every deletion writes an `audit_log` row containing a
snapshot of the deleted record first.

## Decisions to confirm

1. **Fleet seed** — `sql/seed_admi_machines.sql` loads all 15 machines with **403 PM
   requirements** (111 daily, 79 weekly, 55 monthly, 57 quarterly, 14 semi-annual, 87 annual)
   lifted straight from `ADMI_Maintenance_Plan.xlsx`, plus 244 spec rows from the capability
   portfolio including the FUCHS lubrication grades. Loading it is optional at install time.
2. **Daily-task volume** — 111 daily requirements across 15 machines means ~111 due instances a
   day. That is correct for a per-machine washdown/oil/spindle check, but it makes the phase-4
   due list long. Worth deciding whether daily operator checks should render as a single
   per-machine card (one tap closes the whole daily block) rather than eleven separate rows.
3. **Coolant readings** — modelled as value-typed requirement steps with min/max, so the weekly
   coolant log becomes queryable data. If you would rather keep the separate coolant log format
   from the workbook, that is a phase-5 UI decision, not a schema change.
4. **Hour-meter-based PM** — the schema stores `hour_meter` on assets and completions, but the
   due engine is calendar-based (matching the workbook's "Daily = every 8–10 operating hours"
   convention translated to calendar cadence). Say the word if any requirement should be driven
   by run hours instead.
