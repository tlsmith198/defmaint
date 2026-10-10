-- =====================================================================
-- ADMI Maintenance System -- reference / default data
-- Safe to re-run: every insert is INSERT IGNORE or ON DUPLICATE KEY UPDATE.
-- =====================================================================
SET NAMES utf8mb4;

-- ---- Asset categories ------------------------------------------------
INSERT IGNORE INTO asset_categories (name, description, sort_order) VALUES
 ('Small 3-Axis',   'Drill/tap centers and compact VMCs', 10),
 ('Medium 3-Axis',  'General-purpose vertical machining centers', 20),
 ('Large 3-Axis',   'Large-envelope VMCs and bridge mills', 30),
 ('4-Axis HMC',     'Horizontal machining centers with rotary pallet', 40),
 ('Small 5-Axis',   'Compact trunnion machines', 50),
 ('Medium 5-Axis',  'Mid-size trunnion machines', 60),
 ('Large 5-Axis',   'Large trunnion, swivel-head, bridge and profiler machines', 70),
 ('CNC Lathe',      'Turning centers', 80),
 ('Saw',            'Band saws and cut-off saws', 90),
 ('Deburr / Finishing', 'Deburr benches, tumblers, blast cabinets', 100),
 ('Oven / Furnace',  'Heat treat, bake-out and drying equipment', 110),
 ('Support Equipment', 'Compressors, chillers, coolant systems, forklifts', 120),
 ('Facilities',     'Building systems and infrastructure', 130);

-- ---- Locations -------------------------------------------------------
INSERT IGNORE INTO locations (name, department, sort_order) VALUES
 ('Machine Shop',   'Manufacturing', 10),
 ('5-Axis Cell',    'Manufacturing', 20),
 ('Large Machining Bay', 'Manufacturing', 30),
 ('Saw / Material Prep', 'Manufacturing', 40),
 ('Deburr / Finishing',  'Manufacturing', 50),
 ('Maintenance Shop','Maintenance',   60),
 ('Facilities',     'Facilities',     70);

-- ---- Application settings -------------------------------------------
INSERT INTO settings (setting_key, setting_value, setting_group, is_secret) VALUES
 ('company_name',        'Aerospace & Defense Manufacturing Inc.', 'branding', 0),
 ('company_short_name',  'ADMI',                                   'branding', 0),
 ('cage_code',           '97WZ5',                                  'branding', 0),
 ('company_address',     '',                                       'branding', 0),
 ('logo_path',           '',                                       'branding', 0),
 ('logo_report_width_mm','38',                                     'branding', 0),
 ('report_footer_text',  'Controlled document when printed. Verify revision before use.', 'branding', 0),
 ('asset_tag_prefix',    'EQ-',                                    'general',  0),
 ('asset_tag_pad',       '4',                                      'general',  0),
 ('issue_no_prefix',     'ISS-',                                   'general',  0),
 ('timezone',            'America/New_York',                       'general',  0),
 ('date_format',         'm/d/Y',                                  'general',  0),
 ('datetime_format',     'm/d/Y g:i A',                            'general',  0),
 ('due_horizon_days',    '45',                                     'schedule', 0),
 ('schedule_start_date', '',                                       'schedule', 0),
 ('overdue_grace_default','0',                                     'schedule', 0),
 ('issue_reminder_hours_down',  '4',                               'schedule', 0),
 ('issue_reminder_hours_major', '24',                              'schedule', 0),
 ('issue_reminder_hours_minor', '120',                             'schedule', 0),
 ('who_can_log_issues',  'all',                                    'security', 0),
 ('setup_mode',          '1',                                      'security', 0),
 ('session_idle_minutes','480',                                    'security', 0),
 ('max_upload_mb',       '12',                                     'security', 0),
 ('smtp_host',           '',                                       'email',    0),
 ('smtp_port',           '587',                                    'email',    0),
 ('smtp_secure',         'tls',                                    'email',    0),
 ('smtp_username',       '',                                       'email',    0),
 ('smtp_password',       '',                                       'email',    1),
 ('smtp_from_email',     '',                                       'email',    0),
 ('smtp_from_name',      'ADMI Maintenance System',                'email',    0),
 ('email_enabled',       '0',                                      'email',    0),
 ('email_test_recipient','',                                       'email',    0),
 ('cron_token',          '',                                       'schedule', 1),
 ('web_cron_fallback',   '1',                                      'schedule', 0),
 ('installed_at',        '',                                       'general',  0),
 ('schema_version',      '1.0.0',                                  'general',  0)
ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group);

-- ---- Notification templates -----------------------------------------
INSERT IGNORE INTO notification_templates (type_key, label, subject, description, body_html) VALUES
('pm_due_today', 'PM due today',
 '[ADMI] Preventive maintenance due today - {{asset_name}}',
 'Daily digest of PM tasks that come due today.',
 '<p>The following preventive maintenance is <strong>due today</strong>.</p>{{task_table}}<p><a href="{{app_url}}">Open the maintenance dashboard</a></p>'),
('pm_overdue', 'PM overdue',
 '[ADMI] OVERDUE preventive maintenance - {{asset_name}}',
 'Sent when a scheduled PM passes its due date plus grace period.',
 '<p>The following preventive maintenance is <strong>overdue</strong>.</p>{{task_table}}<p><a href="{{app_url}}">Open the maintenance dashboard</a></p>'),
('issue_new', 'New issue logged',
 '[ADMI] {{issue_no}} {{severity_label}} - {{asset_name}}',
 'Sent to the asset owner and maintenance list when any issue is logged.',
 '<p><strong>{{issue_no}}</strong> was logged against <strong>{{asset_name}}</strong> ({{asset_tag}}).</p><p>Severity: <strong>{{severity_label}}</strong><br>Reported by: {{reported_by}}<br>Reported: {{reported_at}}</p><p>{{description}}</p><p><a href="{{issue_url}}">View the issue</a></p>'),
('issue_critical', 'Issue - Major / Machine Down',
 '[ADMI] {{severity_label}} - {{asset_name}} - {{issue_no}}',
 'Immediate escalation for Major and Down severity issues.',
 '<p><strong>{{severity_label}}</strong> condition reported on <strong>{{asset_name}}</strong> ({{asset_tag}}).</p><p>{{description}}</p><p>Reported by {{reported_by}} at {{reported_at}}.</p><p><a href="{{issue_url}}">View the issue</a></p>'),
('issue_status_changed', 'Issue status changed',
 '[ADMI] {{issue_no}} is now {{status_label}}',
 'Sent on each lifecycle transition.',
 '<p><strong>{{issue_no}}</strong> ({{asset_name}}) moved from {{from_status}} to <strong>{{status_label}}</strong> by {{changed_by}}.</p><p>{{note}}</p><p><a href="{{issue_url}}">View the issue</a></p>'),
('issue_resolved', 'Issue resolved',
 '[ADMI] {{issue_no}} resolved - {{asset_name}}',
 'Sent to the original reporter when an issue is resolved.',
 '<p>Your reported issue <strong>{{issue_no}}</strong> on {{asset_name}} has been resolved.</p><p>{{resolution_notes}}</p><p>Resolved by {{resolved_by}} at {{resolved_at}}.</p><p><a href="{{issue_url}}">View the issue</a></p>'),
('issue_overdue', 'Issue overdue with no action',
 '[ADMI] REMINDER - {{issue_no}} still open ({{age}})',
 'Reminder when an issue sits without action past the configured threshold.',
 '<p><strong>{{issue_no}}</strong> on {{asset_name}} has been in status <strong>{{status_label}}</strong> for {{age}} with no action.</p><p>Severity: {{severity_label}}</p><p><a href="{{issue_url}}">View the issue</a></p>');

-- ---- Default recipient rules ----------------------------------------
INSERT IGNORE INTO notification_recipients (type_key, recipient_kind, recipient_value, send_as, min_severity) VALUES
 ('pm_due_today',        'asset_owner',    NULL, 'to', NULL),
 ('pm_overdue',          'asset_owner',    NULL, 'to', NULL),
 ('pm_overdue',          'role',           'admin', 'cc', NULL),
 ('issue_new',           'asset_owner',    NULL, 'to', NULL),
 ('issue_critical',      'role',           'admin', 'to', 'major'),
 ('issue_status_changed','issue_reporter', NULL, 'to', NULL),
 ('issue_resolved',      'issue_reporter', NULL, 'to', NULL),
 ('issue_overdue',       'assignee',       NULL, 'to', NULL),
 ('issue_overdue',       'role',           'admin', 'cc', NULL);
