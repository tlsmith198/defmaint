<?php
/** Issue log. Lifecycle works today; email notification arrives in phase 6. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$filters = [
    'status'   => (string) req('status', ''),
    'severity' => (string) req('severity', ''),
    'asset_id' => req_int('asset_id'),
    'q'        => (string) req('q', ''),
];
$rows = issue_list($filters);

$counts = db_one(
    "SELECT COUNT(*) AS total,
            SUM(status IN ('open','acknowledged','in_progress')) AS open_n,
            SUM(severity = 'down'  AND status IN ('open','acknowledged','in_progress')) AS down_n,
            SUM(severity = 'major' AND status IN ('open','acknowledged','in_progress')) AS major_n
       FROM issues"
) ?: [];

$pageTitle   = 'Issue Log';
$activeNav   = 'issues';
$pageActions = can('log_issue')
    ? '<a class="btn btn-danger" href="' . h(url('modules/issues/form.php'))
      . '"><i class="bi bi-exclamation-triangle"></i> Log an issue</a>'
    : '';
include APP_ROOT . '/templates/header.php';
?>
<div class="row row-cols-2 row-cols-md-4 g-3 mb-3">
  <div class="col"><div class="card stat-card <?= (int) ($counts['open_n'] ?? 0) ? 'warning' : 'success' ?>">
    <div class="card-body py-3"><div class="stat-value"><?= (int) ($counts['open_n'] ?? 0) ?></div>
    <div class="stat-label">Open</div></div></div></div>
  <div class="col"><div class="card stat-card danger">
    <div class="card-body py-3"><div class="stat-value"><?= (int) ($counts['down_n'] ?? 0) ?></div>
    <div class="stat-label">Machine down</div></div></div></div>
  <div class="col"><div class="card stat-card warning">
    <div class="card-body py-3"><div class="stat-value"><?= (int) ($counts['major_n'] ?? 0) ?></div>
    <div class="stat-label">Major</div></div></div></div>
  <div class="col"><div class="card stat-card">
    <div class="card-body py-3"><div class="stat-value"><?= (int) ($counts['total'] ?? 0) ?></div>
    <div class="stat-label">Logged all time</div></div></div></div>
</div>

<form class="card mb-3" method="get">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" id="q" name="q" value="<?= h($filters['q']) ?>"
               placeholder="Number, title, machine…">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status" data-autosubmit>
          <option value="">Open issues</option>
          <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
          <?php foreach (issue_statuses() as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="severity">Severity</label>
        <select class="form-select" id="severity" name="severity" data-autosubmit>
          <option value="">All</option>
          <?php foreach (severities() as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $filters['severity'] === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="asset_id">Machine</label>
        <select class="form-select" id="asset_id" name="asset_id" data-autosubmit>
          <option value="">All</option>
          <?php foreach (asset_options(true) as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $filters['asset_id'] === (int) $a['id'] ? 'selected' : '' ?>>
              <?= h($a['asset_tag']) ?> — <?= h($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
</form>

<div class="card"><div class="table-responsive">
  <table class="table table-admi table-hover mb-0">
    <thead><tr>
      <th>Issue</th><th>Machine</th><th>Severity</th><th>Status</th>
      <th>Reported</th><th>Assigned</th><th class="text-center">Notes</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7" class="text-center text-muted py-4">No issues match this filter.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $i): ?>
      <tr class="<?= $i['severity'] === 'down' && in_array($i['status'], ['open','acknowledged','in_progress'], true) ? 'has-down' : '' ?>"
          data-href="<?= h(url('modules/issues/view.php?id=' . $i['id'])) ?>">
        <td>
          <a class="fw-semibold text-decoration-none"
             href="<?= h(url('modules/issues/view.php?id=' . $i['id'])) ?>"><?= h($i['issue_no']) ?></a>
          <div class="small text-muted"><?= h($i['title']) ?></div>
        </td>
        <td class="small"><span class="font-monospace text-muted"><?= h($i['asset_tag']) ?></span><br>
          <?= h($i['asset_name']) ?></td>
        <td><?= badge($i['severity'], severities()) ?></td>
        <td><?= badge($i['status'], issue_statuses()) ?></td>
        <td class="small"><?= h(fmt_date($i['reported_at'])) ?><br>
          <span class="text-muted"><?= h($i['reporter_name']) ?></span></td>
        <td class="small"><?= h($i['assignee_name'] ?: '—') ?></td>
        <td class="text-center small"><?= (int) $i['note_count'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<p class="small text-muted mt-2">
  Every transition is audited. Email goes out automatically for new issues, Major/Down escalations,
  status changes, resolutions and stale-issue reminders (see Admin → Notifications).
</p>
<?php include APP_ROOT . '/templates/footer.php'; ?>
