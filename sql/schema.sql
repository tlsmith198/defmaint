-- =====================================================================
-- ADMI Equipment, Asset & Maintenance Management System
-- Aerospace & Defense Manufacturing Inc.  (CAGE 97WZ5)
-- MySQL 5.7 / 8.0 schema -- InnoDB / utf8mb4
-- =====================================================================
-- AS9100 notes:
--   * Nothing in this schema is ever hard-deleted by the application.
--     Records are retired/voided via status columns, never DELETEd.
--   * maintenance_completions and issue transitions are append-only.
--     Corrections are recorded in completion_revisions, not overwritten.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- 1. USERS & ACCESS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(64)  NOT NULL,
  email           VARCHAR(190) NOT NULL,
  full_name       VARCHAR(120) NOT NULL,
  initials        VARCHAR(8)   DEFAULT NULL,   -- shop-floor initials on paper logs
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('admin','user') NOT NULL DEFAULT 'user',
  job_title       VARCHAR(120) DEFAULT NULL,
  phone           VARCHAR(40)  DEFAULT NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_pw  TINYINT(1)   NOT NULL DEFAULT 0,
  last_login_at   DATETIME     DEFAULT NULL,
  failed_logins   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until    DATETIME     DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED DEFAULT NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY ix_users_active (is_active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. APPLICATION SETTINGS (key/value -- includes logo path & SMTP)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key     VARCHAR(64)  NOT NULL,
  setting_value   TEXT,
  setting_group   VARCHAR(32)  NOT NULL DEFAULT 'general',
  is_secret       TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (setting_key),
  KEY ix_settings_group (setting_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. ASSET REGISTER
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_categories (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 100,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asset_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(80) NOT NULL,
  department  VARCHAR(80) DEFAULT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 100,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_locations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_tag       VARCHAR(32)  NOT NULL,           -- EQ-0001 (auto-generated, admin-overridable)
  name            VARCHAR(160) NOT NULL,           -- "Hurco VMX42SRTi"
  short_name      VARCHAR(40)  DEFAULT NULL,       -- "VMX42SRTi" -- shop shorthand
  category_id     SMALLINT UNSIGNED DEFAULT NULL,
  location_id     SMALLINT UNSIGNED DEFAULT NULL,
  manufacturer    VARCHAR(120) DEFAULT NULL,
  model           VARCHAR(120) DEFAULT NULL,
  serial_number   VARCHAR(120) DEFAULT NULL,
  machine_type    VARCHAR(120) DEFAULT NULL,       -- "5-Axis Trunnion VMC"
  control_system  VARCHAR(120) DEFAULT NULL,       -- "Hurco WinMax", "FANUC 0i-MF Plus"
  year_built      SMALLINT UNSIGNED DEFAULT NULL,
  date_acquired   DATE DEFAULT NULL,
  in_service_date DATE DEFAULT NULL,
  status          ENUM('active','down_maintenance','down_repair','out_of_service','retired')
                    NOT NULL DEFAULT 'active',
  status_changed_at DATETIME DEFAULT NULL,
  status_note     VARCHAR(255) DEFAULT NULL,
  owner_user_id   INT UNSIGNED DEFAULT NULL,       -- responsible person (notification target)
  photo_path      VARCHAR(255) DEFAULT NULL,
  hour_meter      DECIMAL(10,1) DEFAULT NULL,
  hour_meter_at   DATETIME DEFAULT NULL,
  notes           TEXT,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by      INT UNSIGNED DEFAULT NULL,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by      INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assets_tag (asset_tag),
  KEY ix_assets_status (status),
  KEY ix_assets_category (category_id),
  KEY ix_assets_location (location_id),
  KEY ix_assets_owner (owner_user_id),
  CONSTRAINT fk_assets_category FOREIGN KEY (category_id) REFERENCES asset_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_assets_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_assets_owner    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Free-form capability/spec rows (travels, taper, spindle, lube grades...)
CREATE TABLE IF NOT EXISTS asset_specs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id    INT UNSIGNED NOT NULL,
  spec_group  VARCHAR(40) NOT NULL DEFAULT 'General',
  spec_key    VARCHAR(80) NOT NULL,
  spec_value  VARCHAR(255) DEFAULT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  KEY ix_asset_specs_asset (asset_id, sort_order),
  CONSTRAINT fk_asset_specs_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ATTACHMENTS (polymorphic: assets, requirements, completions, issues)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type     ENUM('asset','requirement','completion','issue','issue_note','setting') NOT NULL,
  entity_id       INT UNSIGNED NOT NULL,
  doc_type        VARCHAR(40) NOT NULL DEFAULT 'other',  -- manual, warranty, lube_chart, photo, procedure, other
  title           VARCHAR(190) DEFAULT NULL,
  file_path       VARCHAR(255) NOT NULL,                 -- relative to /uploads
  original_name   VARCHAR(190) DEFAULT NULL,
  mime_type       VARCHAR(100) DEFAULT NULL,
  file_size       INT UNSIGNED DEFAULT NULL,
  uploaded_by     INT UNSIGNED DEFAULT NULL,
  uploaded_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_void         TINYINT(1) NOT NULL DEFAULT 0,         -- soft-remove only; file record retained
  voided_by       INT UNSIGNED DEFAULT NULL,
  voided_at       DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_attachments_entity (entity_type, entity_id, is_void)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PREVENTIVE MAINTENANCE REQUIREMENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_requirements (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  asset_id          INT UNSIGNED NOT NULL,
  task_name         VARCHAR(255) NOT NULL,
  instructions      TEXT,                       -- rich text / spec notes
  spec_note         VARCHAR(255) DEFAULT NULL,  -- short "Spec / Notes" column from the paper plan
  frequency         ENUM('daily','weekly','monthly','quarterly','semiannual','annual','custom')
                      NOT NULL DEFAULT 'monthly',
  interval_days     SMALLINT UNSIGNED NOT NULL DEFAULT 30,  -- resolved interval, incl. custom
  schedule_mode     ENUM('rolling','fixed_calendar') NOT NULL DEFAULT 'rolling',
  fixed_day_of_month TINYINT UNSIGNED DEFAULT NULL,   -- fixed_calendar monthly+ (1-28)
  fixed_weekday     TINYINT UNSIGNED DEFAULT NULL,    -- fixed_calendar weekly (1=Mon .. 7=Sun)
  grace_days        SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- days past due before "overdue"
  tools_consumables VARCHAR(500) DEFAULT NULL,
  est_minutes       SMALLINT UNSIGNED DEFAULT NULL,
  assigned_role     VARCHAR(80) DEFAULT NULL,   -- "Operator", "Maintenance Tech"
  assigned_user_id  INT UNSIGNED DEFAULT NULL,
  is_safety_critical TINYINT(1) NOT NULL DEFAULT 0,
  sort_order        SMALLINT NOT NULL DEFAULT 100,
  template_key      VARCHAR(64) DEFAULT NULL,   -- groups requirements cloned across assets
  last_completed_at DATETIME DEFAULT NULL,      -- denormalized for fast "next due" lookups
  next_due_date     DATE DEFAULT NULL,          -- denormalized; authoritative copy lives in due_instances
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  retired_at        DATETIME DEFAULT NULL,
  retired_by        INT UNSIGNED DEFAULT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by        INT UNSIGNED DEFAULT NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by        INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_req_asset_task (asset_id, frequency, task_name(150)),
  KEY ix_req_asset (asset_id, is_active, sort_order),
  KEY ix_req_freq (frequency, is_active),
  KEY ix_req_template (template_key),
  KEY ix_req_nextdue (next_due_date, is_active),
  CONSTRAINT fk_req_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
  CONSTRAINT fk_req_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional sub-steps / checklist lines within one requirement
CREATE TABLE IF NOT EXISTS requirement_steps (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  requirement_id  INT UNSIGNED NOT NULL,
  step_no         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  step_text       VARCHAR(500) NOT NULL,
  response_type   ENUM('done','passfail','value','text') NOT NULL DEFAULT 'passfail',
  value_unit      VARCHAR(24) DEFAULT NULL,     -- "%", "psi", "pH", "MPa"
  value_min       DECIMAL(12,3) DEFAULT NULL,
  value_max       DECIMAL(12,3) DEFAULT NULL,
  is_required     TINYINT(1) NOT NULL DEFAULT 1,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_steps_req (requirement_id, step_no),
  CONSTRAINT fk_steps_req FOREIGN KEY (requirement_id) REFERENCES maintenance_requirements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. DUE ENGINE -- generated occurrences
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_due_instances (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  requirement_id  INT UNSIGNED NOT NULL,
  asset_id        INT UNSIGNED NOT NULL,        -- denormalized for fast filtering
  due_date        DATE NOT NULL,
  window_start    DATE DEFAULT NULL,            -- earliest acceptable completion date
  status          ENUM('scheduled','completed','missed','skipped','cancelled') NOT NULL DEFAULT 'scheduled',
  completion_id   INT UNSIGNED DEFAULT NULL,
  completed_at    DATETIME DEFAULT NULL,
  days_late       SMALLINT DEFAULT NULL,        -- computed at completion; drives compliance %
  skip_reason     VARCHAR(255) DEFAULT NULL,
  skipped_by      INT UNSIGNED DEFAULT NULL,
  generated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generated_by    VARCHAR(32) NOT NULL DEFAULT 'cron',
  PRIMARY KEY (id),
  UNIQUE KEY uq_due_req_date (requirement_id, due_date),
  KEY ix_due_status_date (status, due_date),
  KEY ix_due_asset (asset_id, status, due_date),
  CONSTRAINT fk_due_req   FOREIGN KEY (requirement_id) REFERENCES maintenance_requirements(id) ON DELETE CASCADE,
  CONSTRAINT fk_due_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. COMPLETIONS  (append-only -- AS9100 objective evidence)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_completions (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  due_instance_id   INT UNSIGNED DEFAULT NULL,   -- NULL = ad-hoc / unscheduled completion
  requirement_id    INT UNSIGNED NOT NULL,
  asset_id          INT UNSIGNED NOT NULL,
  -- snapshots so the historical record survives later requirement edits
  task_name_snapshot   VARCHAR(255) NOT NULL,
  frequency_snapshot   VARCHAR(20)  NOT NULL,
  due_date_snapshot    DATE DEFAULT NULL,
  result            ENUM('pass','fail','done','not_done','na') NOT NULL DEFAULT 'done',
  completed_by      INT UNSIGNED NOT NULL,
  completed_at      DATETIME NOT NULL,
  duration_minutes  SMALLINT UNSIGNED DEFAULT NULL,
  hour_meter        DECIMAL(10,1) DEFAULT NULL,
  notes             TEXT,
  issue_id          INT UNSIGNED DEFAULT NULL,   -- issue raised from a failed check
  revision_no       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  submitted_ip      VARCHAR(45) DEFAULT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_comp_asset_date (asset_id, completed_at),
  KEY ix_comp_req (requirement_id, completed_at),
  KEY ix_comp_user (completed_by, completed_at),
  KEY ix_comp_due (due_instance_id),
  CONSTRAINT fk_comp_req   FOREIGN KEY (requirement_id) REFERENCES maintenance_requirements(id),
  CONSTRAINT fk_comp_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
  CONSTRAINT fk_comp_user  FOREIGN KEY (completed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS completion_step_results (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  completion_id       INT UNSIGNED NOT NULL,
  requirement_step_id INT UNSIGNED DEFAULT NULL,
  step_no             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  step_text_snapshot  VARCHAR(500) NOT NULL,
  result              ENUM('pass','fail','done','not_done','na') NOT NULL DEFAULT 'done',
  measured_value      DECIMAL(12,3) DEFAULT NULL,
  value_unit          VARCHAR(24) DEFAULT NULL,
  in_range            TINYINT(1) DEFAULT NULL,
  note                VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_csr_completion (completion_id, step_no),
  CONSTRAINT fk_csr_completion FOREIGN KEY (completion_id) REFERENCES maintenance_completions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrections never overwrite silently -- they are logged here.
CREATE TABLE IF NOT EXISTS completion_revisions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  completion_id INT UNSIGNED NOT NULL,
  revision_no   SMALLINT UNSIGNED NOT NULL,
  field_name    VARCHAR(64) NOT NULL,
  old_value     TEXT,
  new_value     TEXT,
  reason        VARCHAR(500) NOT NULL,
  revised_by    INT UNSIGNED NOT NULL,
  revised_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_rev_completion (completion_id, revision_no),
  CONSTRAINT fk_rev_completion FOREIGN KEY (completion_id) REFERENCES maintenance_completions(id) ON DELETE CASCADE,
  CONSTRAINT fk_rev_user FOREIGN KEY (revised_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. ISSUE / FAULT LOG
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS issues (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_no          VARCHAR(24) NOT NULL,         -- ISS-2026-0001
  asset_id          INT UNSIGNED NOT NULL,
  requirement_id    INT UNSIGNED DEFAULT NULL,    -- set when raised from a failed PM check
  due_instance_id   INT UNSIGNED DEFAULT NULL,
  completion_id     INT UNSIGNED DEFAULT NULL,
  title             VARCHAR(190) NOT NULL,
  description       TEXT,
  severity          ENUM('minor','major','down') NOT NULL DEFAULT 'minor',
  status            ENUM('open','acknowledged','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  reported_by       INT UNSIGNED NOT NULL,
  reported_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acknowledged_by   INT UNSIGNED DEFAULT NULL,
  acknowledged_at   DATETIME DEFAULT NULL,
  started_by        INT UNSIGNED DEFAULT NULL,
  started_at        DATETIME DEFAULT NULL,
  resolved_by       INT UNSIGNED DEFAULT NULL,
  resolved_at       DATETIME DEFAULT NULL,
  resolution_notes  TEXT,
  closed_by         INT UNSIGNED DEFAULT NULL,
  closed_at         DATETIME DEFAULT NULL,
  assigned_to       INT UNSIGNED DEFAULT NULL,
  downtime_minutes  INT UNSIGNED DEFAULT NULL,
  last_reminder_at  DATETIME DEFAULT NULL,
  reminder_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_issues_no (issue_no),
  KEY ix_issues_asset (asset_id, status),
  KEY ix_issues_status_sev (status, severity, reported_at),
  KEY ix_issues_assigned (assigned_to, status),
  CONSTRAINT fk_issues_asset FOREIGN KEY (asset_id) REFERENCES assets(id),
  CONSTRAINT fk_issues_reporter FOREIGN KEY (reported_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issue_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id    INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED DEFAULT NULL,          -- NULL = system-generated
  note_type   ENUM('comment','status_change','system','email') NOT NULL DEFAULT 'comment',
  note        TEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_notes_issue (issue_id, created_at),
  CONSTRAINT fk_notes_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS issue_status_history (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id    INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) DEFAULT NULL,
  to_status   VARCHAR(20) NOT NULL,
  changed_by  INT UNSIGNED NOT NULL,
  changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note        VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_ish_issue (issue_id, changed_at),
  CONSTRAINT fk_ish_issue FOREIGN KEY (issue_id) REFERENCES issues(id) ON DELETE CASCADE,
  CONSTRAINT fk_ish_user FOREIGN KEY (changed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_templates (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_key      VARCHAR(48) NOT NULL,   -- pm_due_today, pm_overdue, issue_new, issue_critical,
                                        -- issue_status_changed, issue_overdue, issue_resolved
  label         VARCHAR(120) NOT NULL,
  subject       VARCHAR(255) NOT NULL,
  body_html     MEDIUMTEXT NOT NULL,
  description   VARCHAR(255) DEFAULT NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by    INT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_nt_type (type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_recipients (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_key        VARCHAR(48) NOT NULL,
  recipient_kind  ENUM('email','user','role','asset_owner','issue_reporter','assignee') NOT NULL DEFAULT 'email',
  recipient_value VARCHAR(190) DEFAULT NULL,   -- address, user id, or role name
  send_as         ENUM('to','cc','bcc') NOT NULL DEFAULT 'to',
  min_severity    ENUM('minor','major','down') DEFAULT NULL,  -- issue notifications only
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_nr_type (type_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_log (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type_key      VARCHAR(48) NOT NULL,
  entity_type   VARCHAR(24) DEFAULT NULL,   -- issue, due_instance, asset
  entity_id     INT UNSIGNED DEFAULT NULL,
  to_addresses  TEXT,
  cc_addresses  TEXT,
  subject       VARCHAR(255) DEFAULT NULL,
  body_snapshot MEDIUMTEXT,
  status        ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  error_message VARCHAR(500) DEFAULT NULL,
  attempts      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  queued_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at       DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_nl_type (type_key, queued_at),
  KEY ix_nl_entity (entity_type, entity_id),
  KEY ix_nl_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. AUDIT TRAIL & SCHEDULED JOB RUNS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED DEFAULT NULL,
  action       VARCHAR(64) NOT NULL,      -- asset.create, asset.status_change, req.clone, login.fail ...
  entity_type  VARCHAR(32) DEFAULT NULL,
  entity_id    INT UNSIGNED DEFAULT NULL,
  detail       TEXT,                      -- JSON blob of changed fields
  ip_address   VARCHAR(45) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_entity (entity_type, entity_id, created_at),
  KEY ix_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proof that the cPanel cron actually ran (auditors and debugging alike)
CREATE TABLE IF NOT EXISTS job_runs (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_key       VARCHAR(48) NOT NULL,     -- due_engine, reminder_sweep, mail_queue
  trigger_type  ENUM('cron','web','manual') NOT NULL DEFAULT 'cron',
  started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at   DATETIME DEFAULT NULL,
  status        ENUM('running','ok','error') NOT NULL DEFAULT 'running',
  items_processed INT UNSIGNED NOT NULL DEFAULT 0,
  message       VARCHAR(1000) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_job_key (job_key, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session-backed remember/lockout support is handled in PHP sessions; no table needed.
