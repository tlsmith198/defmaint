<?php
/**
 * Application settings. Admin only.
 * Branding (including the report logo upload), general, scheduling and email.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$tab = (string) req('tab', 'branding');

/** Fields the form is allowed to write, grouped by tab. */
$editable = [
    'branding' => ['company_name', 'company_short_name', 'cage_code', 'company_address',
                   'logo_report_width_mm', 'report_footer_text'],
    'general'  => ['asset_tag_prefix', 'asset_tag_pad', 'issue_no_prefix', 'timezone',
                   'date_format', 'datetime_format', 'max_upload_mb', 'who_can_log_issues',
                   'session_idle_minutes', 'setup_mode'],
    'schedule' => ['due_horizon_days', 'overdue_grace_default', 'issue_reminder_hours_down',
                   'issue_reminder_hours_major', 'issue_reminder_hours_minor',
                   'web_cron_fallback', 'cron_token'],
    'email'    => ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_username', 'smtp_password',
                   'smtp_from_email', 'smtp_from_name', 'email_enabled', 'email_test_recipient',
                   'app_base_url', 'notify_include_daily'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    // ---- data clean-up tools (not settings) --------------------------
    $tool = (string) req('tool', '');
    if ($tool === 'reset_schedule') {
        [, $msg] = purge_reset_schedule(null);
        flash(h($msg), 'info');
        redirect('modules/settings/index.php?tab=general');
    }
    if ($tool === 'go_live') {
        [$ok, $msg] = purge_set_go_live((string) req('schedule_start_date', ''));
        flash(h($msg), $ok ? 'success' : 'danger');
        redirect('modules/settings/index.php?tab=schedule');
    }
    if ($tool === 'clear_completions') {
        [$ok, $msg] = purge_all_completions(null);
        flash(h($msg), $ok ? 'info' : 'danger');
        redirect('modules/settings/index.php?tab=general');
    }

    $group  = (string) req('group', 'branding');
    $fields = $editable[$group] ?? [];
    $changed = [];

    // ---- logo upload / removal ---------------------------------------
    if ($group === 'branding') {
        if (req('remove_logo') === '1') {
            setting_set('logo_path', '', 'branding');
            audit('settings.logo_remove', 'setting', null);
            flash('Logo removed. Reports fall back to the typeset ADMI header.', 'info');
        } elseif (!empty($_FILES['logo']['name'])) {
            [$rel, $err] = store_upload($_FILES['logo'], 'branding', UPLOAD_IMAGE_TYPES);
            if ($err) {
                flash(h($err), 'danger');
            } elseif ($rel) {
                setting_set('logo_path', $rel, 'branding');
                audit('settings.logo_upload', 'setting', null, ['file' => $rel]);
                flash('Logo uploaded. It now appears in the app header, the sign-in page and PDF reports.');
            }
        }
    }

    foreach ($fields as $key) {
        if ($key === 'logo_path') {
            continue;
        }
        $value = $_POST[$key] ?? null;
        if ($value === null && in_array($key, ['email_enabled', 'web_cron_fallback', 'setup_mode', 'notify_include_daily'], true)) {
            $value = '0';                       // unchecked checkbox
        }
        if ($value === null) {
            continue;
        }
        if (in_array($key, ['email_enabled', 'web_cron_fallback', 'setup_mode', 'notify_include_daily'], true)) {
            $value = $value ? '1' : '0';
        }
        $value = is_string($value) ? trim($value) : $value;

        // Never blank a stored secret by submitting an empty masked field.
        if ($key === 'smtp_password' && $value === '') {
            continue;
        }
        if ((string) setting($key, '') !== (string) $value) {
            $changed[$key] = $key === 'smtp_password' ? '[updated]' : $value;
        }
        setting_set($key, $value, $group);
    }

    if ($changed) {
        audit('settings.update', 'setting', null, $changed);
        flash(count($changed) . ' setting' . (count($changed) === 1 ? '' : 's') . ' saved.');
    }
    if ($tool === 'test_email') {                       // settings are saved above, then the test uses them
        $err = notify_send_test((string) setting('email_test_recipient', ''));
        audit('settings.email_test', 'setting', null, ['ok' => $err === null]);
        flash($err === null
            ? 'Settings saved. Test email sent to ' . h(setting('email_test_recipient')) . ' - check the inbox (and spam).'
            : 'Settings saved, but the test email failed: ' . h($err), $err === null ? 'success' : 'danger');
    }
    redirect('modules/settings/index.php?tab=' . $group);
}

$pageTitle   = 'Settings';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'Settings & branding']];
include APP_ROOT . '/templates/header.php';

function s_tab(string $key, string $label, string $current): void
{
    printf('<li class="nav-item"><a class="nav-link %s" href="%s">%s</a></li>',
        $key === $current ? 'active' : '',
        h(url('modules/settings/index.php?tab=' . $key)), h($label));
}
$logo = setting('logo_path', '');
?>
<ul class="nav nav-tabs mb-3">
  <?php
  s_tab('branding', 'Branding & logo', $tab);
  s_tab('general',  'General',         $tab);
  s_tab('schedule', 'Scheduling & cron', $tab);
  s_tab('email',    'Email / SMTP',    $tab);
  ?>
  <li class="nav-item">
    <a class="nav-link" href="<?= h(url('modules/settings/system.php')) ?>">System check</a>
  </li>
  <li class="nav-item">
    <a class="nav-link" href="<?= h(url('modules/settings/update.php')) ?>"><i class="bi bi-cloud-arrow-up"></i> Updates</a>
  </li>
</ul>

<?php if ($tab === 'branding'): ?>
<form method="post" enctype="multipart/form-data" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="group" value="branding">
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">Company identity</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label" for="company_name">Company name</label>
              <input class="form-control" id="company_name" name="company_name"
                     value="<?= h(setting('company_name', '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="company_short_name">Short name</label>
              <input class="form-control" id="company_short_name" name="company_short_name"
                     value="<?= h(setting('company_short_name', 'ADMI')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="cage_code">CAGE code</label>
              <input class="form-control font-monospace" id="cage_code" name="cage_code"
                     value="<?= h(setting('cage_code', '')) ?>">
            </div>
            <div class="col-md-8">
              <label class="form-label" for="company_address">Address (PDF header)</label>
              <input class="form-control" id="company_address" name="company_address"
                     value="<?= h(setting('company_address', '')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="report_footer_text">Report footer text</label>
              <input class="form-control" id="report_footer_text" name="report_footer_text"
                     value="<?= h(setting('report_footer_text', '')) ?>">
              <div class="form-text">Printed at the bottom of every PDF report.</div>
            </div>
          </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Save branding</button></div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">Company logo</div>
        <div class="card-body">
          <div class="border rounded p-3 mb-3 text-center bg-white">
            <?php if ($logo): ?>
              <img src="<?= h(upload_url($logo)) ?>" alt="Current logo" style="max-height:90px;max-width:100%">
            <?php else: ?>
              <div class="text-muted py-4"><i class="bi bi-image fs-2 d-block mb-2"></i>No logo uploaded</div>
            <?php endif; ?>
          </div>
          <img id="logoPreview" class="d-none img-fluid mb-2" alt="">
          <label class="form-label" for="logo">Upload a new logo</label>
          <input class="form-control" type="file" id="logo" name="logo" accept="image/*"
                 data-preview-target="#logoPreview">
          <div class="form-text mb-3">
            PNG with a transparent background works best. Aim for at least 600 px wide;
            it is scaled down for the app header and PDF reports.
          </div>
          <div class="mb-3">
            <label class="form-label" for="logo_report_width_mm">Width in PDF reports (mm)</label>
            <input class="form-control" type="number" min="10" max="90" id="logo_report_width_mm"
                   name="logo_report_width_mm" value="<?= h(setting('logo_report_width_mm', '38')) ?>">
          </div>
          <?php if ($logo): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
              <label class="form-check-label text-danger" for="remove_logo">Remove the current logo on save</label>
            </div>
          <?php endif; ?>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Save logo</button></div>
      </div>
    </div>
  </div>
</form>

<?php elseif ($tab === 'general'): ?>
<form method="post" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="group" value="general">
  <div class="row g-3"><div class="col-lg-8">
    <div class="card">
      <div class="card-header">Numbering &amp; formats</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label" for="asset_tag_prefix">Asset tag prefix</label>
            <input class="form-control font-monospace" id="asset_tag_prefix" name="asset_tag_prefix"
                   value="<?= h(setting('asset_tag_prefix', 'EQ-')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="asset_tag_pad">Digits</label>
            <input class="form-control" type="number" min="2" max="8" id="asset_tag_pad" name="asset_tag_pad"
                   value="<?= h(setting('asset_tag_pad', '4')) ?>">
            <div class="form-text">Next tag: <code><?= h(next_asset_tag()) ?></code></div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="issue_no_prefix">Issue prefix</label>
            <input class="form-control font-monospace" id="issue_no_prefix" name="issue_no_prefix"
                   value="<?= h(setting('issue_no_prefix', 'ISS-')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="timezone">Time zone</label>
            <select class="form-select" id="timezone" name="timezone">
              <?php foreach (DateTimeZone::listIdentifiers(DateTimeZone::AMERICA) as $tz): ?>
                <option value="<?= h($tz) ?>" <?= setting('timezone') === $tz ? 'selected' : '' ?>><?= h($tz) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="date_format">Date format</label>
            <input class="form-control" id="date_format" name="date_format"
                   value="<?= h(setting('date_format', 'm/d/Y')) ?>">
            <div class="form-text">Now: <?= h(date(setting('date_format', 'm/d/Y'))) ?></div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="datetime_format">Date &amp; time format</label>
            <input class="form-control" id="datetime_format" name="datetime_format"
                   value="<?= h(setting('datetime_format', 'm/d/Y g:i A')) ?>">
            <div class="form-text">Now: <?= h(date(setting('datetime_format', 'm/d/Y g:i A'))) ?></div>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="max_upload_mb">Max upload size (MB)</label>
            <input class="form-control" type="number" min="1" max="64" id="max_upload_mb" name="max_upload_mb"
                   value="<?= h(setting('max_upload_mb', '12')) ?>">
            <div class="form-text">
              Server limit: <?= h(ini_get('upload_max_filesize')) ?> / post <?= h(ini_get('post_max_size')) ?>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="who_can_log_issues">Who can log issues</label>
            <select class="form-select" id="who_can_log_issues" name="who_can_log_issues">
              <option value="all"   <?= setting('who_can_log_issues') === 'all'   ? 'selected' : '' ?>>Any signed-in user</option>
              <option value="admin" <?= setting('who_can_log_issues') === 'admin' ? 'selected' : '' ?>>Administrators only</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="session_idle_minutes">Session idle timeout (minutes)</label>
            <input class="form-control" type="number" min="15" max="1440" id="session_idle_minutes"
                   name="session_idle_minutes" value="<?= h(setting('session_idle_minutes', '480')) ?>">
            <div class="form-text">Shop-floor tablets usually want a long timeout.</div>
          </div>
        </div>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit">Save general settings</button></div>
    </div>

    <div class="card mt-3 <?= setup_mode() ? 'border-warning' : '' ?>">
      <div class="card-header <?= setup_mode() ? 'text-warning-emphasis' : '' ?>">
        <i class="bi bi-tools"></i> Setup mode
      </div>
      <div class="card-body">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="setup_mode" name="setup_mode" value="1"
                 <?= setup_mode() ? 'checked' : '' ?>>
          <label class="form-check-label" for="setup_mode">
            <strong>The system is still being set up</strong>
            <span class="d-block small text-muted">
              While this is on, an administrator can permanently delete maintenance tasks, machines
              and sign-off records — useful while you are dialling in frequencies and trying things out.
              Every deletion is still written to the audit trail with a snapshot of what was removed.
            </span>
          </label>
        </div>
        <hr>
        <p class="small mb-0">
          <strong>Turn it off when the schedule is final.</strong> From then on, anything carrying
          sign-off history can only be <em>retired</em> or <em>corrected</em>, never deleted — which is
          what AS9100 expects of a live maintenance record. Tasks with no history remain deletable
          either way, since they are evidence of nothing.
        </p>
      </div>
      <div class="card-footer"><button class="btn btn-primary" type="submit">Save general settings</button></div>
    </div>
  </div></div>
</form>

<?php $sum = purge_data_summary(); ?>
<div class="row g-3"><div class="col-lg-8">
  <div class="card danger-zone">
    <div class="card-header"><i class="bi bi-database-gear"></i> Fleet-wide clean-up</div>
    <div class="card-body">
      <p class="small text-muted">
        Current data: <?= $sum['assets'] ?> machines · <?= $sum['requirements'] ?> tasks
        (<?= $sum['retired'] ?> retired) · <?= $sum['instances'] ?> scheduled occurrences ·
        <?= $sum['completions'] ?> sign-off records · <?= $sum['issues'] ?> issues.
      </p>
      <div class="row g-3">
        <div class="col-md-6">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="tool" value="reset_schedule">
            <button class="btn btn-outline-secondary w-100" type="submit"
                    data-confirm="Rebuild the whole fleet schedule? Unsigned due dates are recalculated from the current frequencies. Sign-off history is not touched.">
              Rebuild the whole schedule
            </button>
          </form>
          <div class="form-text">
            Throws away unsigned due dates across every machine and regenerates them from the
            current requirement definitions. Nothing that has been signed off is affected, so this
            is safe at any time.
          </div>
        </div>
        <div class="col-md-6">
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="tool" value="clear_completions">
            <button class="btn btn-danger w-100" type="submit"
                    <?= setup_mode() && $sum['completions'] ? '' : 'disabled' ?>
                    data-confirm="Delete all <?= (int) $sum['completions'] ?> sign-off record(s)? This cannot be undone.">
              Clear trial sign-off records (<?= (int) $sum['completions'] ?>)
            </button>
          </form>
          <div class="form-text">
            <?php if (!setup_mode()): ?>
              Locked — switch Setup mode on first. Once the record is locked, sign-off history
              cannot be bulk-deleted.
            <?php else: ?>
              For clearing out the sign-offs you logged while testing, before the shop starts
              using it for real. Affected tasks go back on the schedule as due.
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div></div>

<?php elseif ($tab === 'schedule'): ?>
<?php
$cronPath  = APP_ROOT . '/cron/run.php';
$token     = setting('cron_token', '');
$lastRuns  = db_all('SELECT * FROM job_runs ORDER BY started_at DESC LIMIT 5');
?>
<form method="post" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="group" value="schedule">
  <div class="row g-3">
    <div class="col-lg-7">
      <?php
      $goLive  = due_schedule_start();
      $overdue = (int) db_value("SELECT COUNT(*) FROM maintenance_due_instances
                                  WHERE status = 'scheduled' AND due_date < CURDATE()", [], 0);
      $missed  = (int) db_value("SELECT COUNT(*) FROM maintenance_due_instances
                                  WHERE status = 'missed'", [], 0);
      ?>
      <div class="card mb-3 <?= $goLive === null && $overdue > 50 ? 'border-warning' : '' ?>">
        <div class="card-header"><i class="bi bi-calendar-check"></i> Go-live date</div>
        <div class="card-body">
          <p class="small text-muted">
            The day the floor starts using the system. Nothing falls due before it, so a system
            that sat installed for a few weeks doesn't greet everyone with a pile of overdue checks.
            Setting it clears every unsigned due date and missed record, then re-spreads the whole
            schedule from that day. <strong>Sign-off history is never touched.</strong>
          </p>

          <?php if ($overdue > 50 || $missed > 0): ?>
            <div class="alert alert-warning py-2 small">
              Right now there <?= $overdue === 1 ? 'is' : 'are' ?> <strong><?= $overdue ?></strong>
              overdue item<?= $overdue === 1 ? '' : 's' ?><?= $missed ? ' and <strong>' . $missed . '</strong> missed record' . ($missed === 1 ? '' : 's') : '' ?>.
              If the shop hasn't started using this yet, set the date below to the day it goes live
              and all of that clears.
            </div>
          <?php endif; ?>

          <form method="post" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="tool" value="go_live">
            <div class="col-sm-6">
              <label class="form-label" for="schedule_start_date">Start the schedule on</label>
              <input class="form-control" type="date" id="schedule_start_date"
                     name="schedule_start_date" value="<?= h($goLive ?? '') ?>">
              <div class="form-text">
                <?php if ($goLive === null): ?>
                  Not set — dates currently run from when the system was installed.
                <?php elseif ($goLive > date('Y-m-d')): ?>
                  Nothing is due until <?= h(fmt_date($goLive)) ?>.
                <?php else: ?>
                  Running since <?= h(fmt_date($goLive)) ?>.
                <?php endif; ?>
              </div>
            </div>
            <div class="col-sm-6">
              <button class="btn btn-primary w-100" type="submit"
                      data-confirm="Rebuild the schedule from this date? Unsigned due dates and missed records are cleared. Sign-off history is kept.">
                Apply &amp; rebuild the schedule
              </button>
              <div class="form-text">
                Leave the field empty and apply to go back to running from today.
              </div>
            </div>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Due engine &amp; reminders</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="due_horizon_days">Generate due dates this far ahead (days)</label>
              <input class="form-control" type="number" min="7" max="365" id="due_horizon_days"
                     name="due_horizon_days" value="<?= h(setting('due_horizon_days', '45')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="overdue_grace_default">Default grace period (days)</label>
              <input class="form-control" type="number" min="0" max="30" id="overdue_grace_default"
                     name="overdue_grace_default" value="<?= h(setting('overdue_grace_default', '0')) ?>">
              <div class="form-text">Individual requirements can override this.</div>
            </div>
            <div class="col-12"><div class="form-section-title mb-2">Issue reminder thresholds</div></div>
            <div class="col-md-4">
              <label class="form-label" for="issue_reminder_hours_down">Machine Down (hours)</label>
              <input class="form-control" type="number" min="1" id="issue_reminder_hours_down"
                     name="issue_reminder_hours_down" value="<?= h(setting('issue_reminder_hours_down', '4')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="issue_reminder_hours_major">Major (hours)</label>
              <input class="form-control" type="number" min="1" id="issue_reminder_hours_major"
                     name="issue_reminder_hours_major" value="<?= h(setting('issue_reminder_hours_major', '24')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="issue_reminder_hours_minor">Minor (hours)</label>
              <input class="form-control" type="number" min="1" id="issue_reminder_hours_minor"
                     name="issue_reminder_hours_minor" value="<?= h(setting('issue_reminder_hours_minor', '120')) ?>">
            </div>
            <div class="col-12">
              <label class="form-label" for="cron_token">Cron token</label>
              <input class="form-control font-monospace" id="cron_token" name="cron_token"
                     value="<?= h($token) ?>" placeholder="long random string">
              <div class="form-text">Required on the cron URL so the job cannot be triggered by strangers.</div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="web_cron_fallback" name="web_cron_fallback"
                       value="1" <?= setting_bool('web_cron_fallback', true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="web_cron_fallback">
                  Page-load fallback — if the cron has not run today, generate due dates on the next sign-in
                </label>
              </div>
              <div class="form-text">
                Leave this on for GoDaddy shared hosting so a missed cron never silently stalls the schedule.
              </div>
            </div>
          </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Save scheduling</button></div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-header">cPanel cron setup</div>
        <div class="card-body small">
          <p>In cPanel &rarr; <strong>Advanced</strong> &rarr; <strong>Cron Jobs</strong>, add a job that runs
             once nightly (a common choice is 4:00 a.m.):</p>
          <pre class="bg-light p-2 border rounded small mb-2" style="white-space:pre-wrap">/usr/local/bin/php <?= h($cronPath) ?> <?= h($token ?: '&lt;cron_token&gt;') ?></pre>
          <p class="mb-2">Or, if the hosting plan only offers URL-based cron:</p>
          <pre class="bg-light p-2 border rounded small mb-2" style="white-space:pre-wrap">curl -s "<?= h(url('cron/run.php')) ?>?token=<?= h($token ?: '&lt;cron_token&gt;') ?>"</pre>
          <p class="text-muted mb-0">
            Confirm the PHP binary path with your host — on GoDaddy shared hosting it is usually
            <code>/usr/local/bin/php</code> and the version is set under
            <em>Select PHP Version</em>.
          </p>
        </div>
      </div>
      <div class="card">
        <div class="card-header">Recent job runs</div>
        <?php if (!$lastRuns): ?>
          <div class="card-body small text-muted">The scheduled job has not run yet.</div>
        <?php else: ?>
        <table class="table table-admi mb-0">
          <thead><tr><th>Job</th><th>Started</th><th>Status</th><th class="text-end">Items</th></tr></thead>
          <tbody>
          <?php foreach ($lastRuns as $j): ?>
            <tr>
              <td class="small font-monospace"><?= h($j['job_key']) ?></td>
              <td class="small"><?= h(fmt_datetime($j['started_at'])) ?></td>
              <td><span class="badge text-bg-<?= $j['status'] === 'ok' ? 'success' : ($j['status'] === 'error' ? 'danger' : 'secondary') ?>">
                <?= h($j['status']) ?></span></td>
              <td class="text-end small"><?= (int) $j['items_processed'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>

<?php else: /* email */ ?>
<form method="post" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="group" value="email">
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">Outbound email (PHPMailer / SMTP)</div>
        <div class="card-body">
          <div class="alert alert-info small">
            GoDaddy shared hosting commonly blocks direct <code>mail()</code> and outbound port 25.
            Use an authenticated SMTP relay — either GoDaddy's own
            (<code>smtpout.secureserver.net</code>, port 465 SSL or 587 TLS) or Microsoft 365 for the
            ADMI domain. Confirm the credentials with the mail administrator before enabling.
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="smtp_host">SMTP host</label>
              <input class="form-control" id="smtp_host" name="smtp_host"
                     value="<?= h(setting('smtp_host', '')) ?>" placeholder="smtpout.secureserver.net">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="smtp_port">Port</label>
              <input class="form-control" type="number" id="smtp_port" name="smtp_port"
                     value="<?= h(setting('smtp_port', '587')) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="smtp_secure">Encryption</label>
              <select class="form-select" id="smtp_secure" name="smtp_secure">
                <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', '' => 'None'] as $k => $lbl): ?>
                  <option value="<?= h($k) ?>" <?= (string) setting_raw('smtp_secure', 'tls') === $k ? 'selected' : '' ?>>
                    <?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="smtp_username">Username</label>
              <input class="form-control" id="smtp_username" name="smtp_username"
                     value="<?= h(setting('smtp_username', '')) ?>" autocomplete="off">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="smtp_password">Password</label>
              <input class="form-control" type="password" id="smtp_password" name="smtp_password"
                     placeholder="<?= setting('smtp_password') ? '•••••••• (unchanged)' : '' ?>" autocomplete="new-password">
              <div class="form-text">Leave blank to keep the stored password.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="smtp_from_email">From address</label>
              <input class="form-control" type="email" id="smtp_from_email" name="smtp_from_email"
                     value="<?= h(setting('smtp_from_email', '')) ?>" placeholder="maintenance@admi.example">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="smtp_from_name">From name</label>
              <input class="form-control" id="smtp_from_name" name="smtp_from_name"
                     value="<?= h(setting('smtp_from_name', '')) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="email_test_recipient">Test recipient</label>
              <input class="form-control" type="email" id="email_test_recipient" name="email_test_recipient"
                     value="<?= h(setting('email_test_recipient', '')) ?>">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled" value="1"
                       <?= setting_bool('email_enabled') ? 'checked' : '' ?>>
                <label class="form-check-label" for="email_enabled">
                  Notifications enabled — send email for PM due, overdue and issue events
                </label>
              </div>
              <div class="form-text">
                Every message is written to the notification log whether or not email is on.
                Turn this on after the test email below arrives.
              </div>
            </div>
            <div class="col-md-8">
              <label class="form-label" for="app_base_url">Public app address (used for links in email)</label>
              <input class="form-control" id="app_base_url" name="app_base_url"
                     value="<?= h(setting('app_base_url', '')) ?>" placeholder="https://yourdomain.com/maintenance">
              <div class="form-text">Filled in automatically the first time an admin opens the app.</div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="notify_include_daily" name="notify_include_daily" value="1"
                       <?= setting_bool('notify_include_daily') ? 'checked' : '' ?>>
                <label class="form-check-label" for="notify_include_daily">Include daily tasks in “due today” digests</label>
              </div>
            </div>
          </div>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
          <button class="btn btn-primary" type="submit">Save email settings</button>
          <button class="btn btn-outline-secondary" type="submit" formaction="<?= h(url('modules/settings/index.php?tab=email')) ?>"
                  name="tool" value="test_email"><i class="bi bi-send"></i> Save &amp; send test email</button>
          <a class="btn btn-outline-secondary ms-auto" href="<?= h(url('modules/admin/notifications.php')) ?>">
            <i class="bi bi-envelope-paper"></i> Notification log &amp; rules</a>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">Notification types</div>
        <ul class="list-group list-group-flush">
          <?php foreach (db_all('SELECT * FROM notification_templates ORDER BY id') as $t): ?>
            <li class="list-group-item">
              <div class="d-flex justify-content-between">
                <span class="fw-semibold small"><?= h($t['label']) ?></span>
                <span class="badge text-bg-<?= $t['is_active'] ? 'success' : 'secondary' ?>">
                  <?= $t['is_active'] ? 'on' : 'off' ?></span>
              </div>
              <div class="small text-muted"><?= h($t['description']) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer small text-muted">
          Per-type recipient lists and editable HTML templates are wired up with the
          notification engine in phase 6.
        </div>
      </div>
    </div>
  </div>
</form>
<?php endif; ?>
<?php include APP_ROOT . '/templates/footer.php'; ?>
