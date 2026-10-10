<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
due_engine_maybe_run_web();

$stats = db_one("
    SELECT
      SUM(status <> 'retired')                                   AS total_assets,
      SUM(status = 'active')                                     AS active_assets,
      SUM(status IN ('down_maintenance','down_repair'))           AS down_assets,
      SUM(status = 'out_of_service')                              AS oos_assets
    FROM assets") ?: [];

$pm = db_one("
    SELECT
      SUM(d.due_date < CURDATE())  AS overdue,
      SUM(d.due_date = CURDATE())  AS due_today,
      SUM(d.due_date > CURDATE() AND d.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS due_week
    FROM maintenance_due_instances d
    JOIN maintenance_requirements r ON r.id = d.requirement_id AND r.is_active = 1
    WHERE d.status = 'scheduled'") ?: [];

$openIssues = db_one("
    SELECT COUNT(*) AS n,
           SUM(severity = 'down')  AS down_n,
           SUM(severity = 'major') AS major_n
    FROM issues WHERE status IN ('open','acknowledged','in_progress')") ?: [];

$byCategory = db_all("
    SELECT c.name, COUNT(a.id) AS n
      FROM asset_categories c
      LEFT JOIN assets a ON a.category_id = c.id AND a.status <> 'retired'
     GROUP BY c.id, c.name HAVING n > 0 ORDER BY c.sort_order");

$downAssets = db_all("
    SELECT a.*, c.name AS category_name
      FROM assets a LEFT JOIN asset_categories c ON c.id = a.category_id
     WHERE a.status IN ('down_maintenance','down_repair','out_of_service')
     ORDER BY a.status_changed_at DESC LIMIT 10");

$recentIssues = db_all("
    SELECT i.*, a.asset_tag, a.name AS asset_name, u.full_name AS reporter
      FROM issues i
      JOIN assets a ON a.id = i.asset_id
      JOIN users u  ON u.id = i.reported_by
     WHERE i.status IN ('open','acknowledged','in_progress')
     ORDER BY FIELD(i.severity,'down','major','minor'), i.reported_at DESC LIMIT 8");

$recentCompletions = db_all("
    SELECT mc.*, a.asset_tag, a.name AS asset_name, u.full_name AS completed_by_name
      FROM maintenance_completions mc
      JOIN assets a ON a.id = mc.asset_id
      JOIN users u  ON u.id = mc.completed_by
     ORDER BY mc.completed_at DESC LIMIT 8");

$reqTotal = (int) db_value('SELECT COUNT(*) FROM maintenance_requirements WHERE is_active = 1', [], 0);

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
include APP_ROOT . '/templates/header.php';

function stat_card(string $label, $value, string $variant = '', string $sub = '', string $href = ''): void
{
    $inner = '<div class="card stat-card ' . h($variant) . ' h-100"><div class="card-body py-3">'
           . '<div class="stat-value">' . h($value) . '</div>'
           . '<div class="stat-label">' . h($label) . '</div>'
           . ($sub ? '<div class="small text-muted mt-1">' . $sub . '</div>' : '')
           . '</div></div>';
    echo $href
        ? '<div class="col"><a class="text-decoration-none" href="' . h($href) . '">' . $inner . '</a></div>'
        : '<div class="col">' . $inner . '</div>';
}
?>

<?php $runCounts = run_counts(); ?>
<div class="card mb-3">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
    <span class="fw-semibold me-1"><i class="bi bi-check2-square text-success"></i> Run checks</span>
    <?php foreach (run_tabs() as $k => $t): if ($k === 'other') continue; ?>
      <a class="btn btn-sm <?= $runCounts[$k]['due'] ? 'btn-success' : 'btn-outline-secondary' ?>"
         href="<?= h(url('modules/maintenance/run.php?freq=' . $k)) ?>">
        <?= h($t['label']) ?> <span class="badge text-bg-light"><?= (int) $runCounts[$k]['due'] ?></span></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-3 mb-4">
  <?php
  stat_card('Assets in service', (int) ($stats['total_assets'] ?? 0), '', '', url('modules/assets/index.php'));
  stat_card('Active', (int) ($stats['active_assets'] ?? 0), 'success', '', url('modules/assets/index.php?status=active'));
  stat_card('Down', (int) ($stats['down_assets'] ?? 0), 'danger', '', url('modules/assets/index.php?status=down_repair'));
  stat_card('PM overdue', (int) ($pm['overdue'] ?? 0), 'danger', '', url('modules/maintenance/index.php?view=overdue'));
  stat_card('PM due today', (int) ($pm['due_today'] ?? 0), 'warning', '', url('modules/maintenance/index.php?view=today'));
  stat_card('Open issues', (int) ($openIssues['n'] ?? 0), (int) ($openIssues['down_n'] ?? 0) > 0 ? 'danger' : '',
      (int) ($openIssues['down_n'] ?? 0) . ' machine-down', url('modules/issues/index.php'));
  ?>
</div>

<?php if ($reqTotal === 0): ?>
  <div class="alert alert-info d-flex align-items-start gap-2">
    <i class="bi bi-info-circle mt-1"></i>
    <div>
      <strong>No maintenance requirements are loaded yet.</strong><br>
      Import <code>sql/seed_admi_machines.sql</code> to load the 15-machine fleet and its PM schedule,
      or <a href="<?= h(url('modules/maintenance/requirements.php')) ?>">build requirements per asset</a>
      in the requirement builder.
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-xl-8">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle text-danger"></i> Open issues</span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('modules/issues/index.php')) ?>">View all</a>
      </div>
      <?php if (!$recentIssues): ?>
        <div class="card-body text-muted small">No open issues. Every asset is running clean.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-admi mb-0">
          <thead><tr><th>Issue</th><th>Asset</th><th>Severity</th><th>Status</th><th>Reported</th></tr></thead>
          <tbody>
          <?php foreach ($recentIssues as $i): ?>
            <tr data-href="<?= h(url('modules/issues/view.php?id=' . $i['id'])) ?>">
              <td><span class="fw-semibold"><?= h($i['issue_no']) ?></span><br>
                  <span class="small text-muted"><?= h($i['title']) ?></span></td>
              <td><span class="text-muted small"><?= h($i['asset_tag']) ?></span><br><?= h($i['asset_name']) ?></td>
              <td><?= badge($i['severity'], severities()) ?></td>
              <td><?= badge($i['status'], issue_statuses()) ?></td>
              <td class="small"><?= h(fmt_age($i['reported_at'])) ?><br>
                  <span class="text-muted"><?= h($i['reporter']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-clipboard-check text-success"></i> Recent PM completions</div>
      <?php if (!$recentCompletions): ?>
        <div class="card-body text-muted small">No completions logged yet.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-admi mb-0">
          <thead><tr><th>Task</th><th>Asset</th><th>Result</th><th>Completed</th><th>By</th></tr></thead>
          <tbody>
          <?php foreach ($recentCompletions as $c): ?>
            <tr>
              <td><?= h($c['task_name_snapshot']) ?>
                  <span class="badge text-bg-light"><?= h(label_of($c['frequency_snapshot'], frequencies())) ?></span></td>
              <td><span class="text-muted small"><?= h($c['asset_tag']) ?></span> <?= h($c['asset_name']) ?></td>
              <td><?php $ok = in_array($c['result'], ['pass', 'done'], true); ?>
                  <span class="badge text-bg-<?= $ok ? 'success' : 'danger' ?>"><?= h(ucfirst(str_replace('_', ' ', $c['result']))) ?></span></td>
              <td class="small"><?= h(fmt_datetime($c['completed_at'])) ?></td>
              <td class="small"><?= h($c['completed_by_name']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-xl-4">
    <?php if ($downAssets): ?>
    <div class="card mb-3 border-danger">
      <div class="card-header text-danger"><i class="bi bi-wrench-adjustable"></i> Not in production</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($downAssets as $a): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start">
            <div>
              <a class="fw-semibold text-decoration-none"
                 href="<?= h(url('modules/assets/view.php?id=' . $a['id'])) ?>"><?= h($a['name']) ?></a>
              <div class="small text-muted"><?= h($a['asset_tag']) ?>
                <?= $a['status_note'] ? '&middot; ' . h($a['status_note']) : '' ?></div>
            </div>
            <?= badge($a['status'], asset_statuses()) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><i class="bi bi-diagram-3"></i> Fleet by category</div>
      <ul class="list-group list-group-flush">
        <?php foreach ($byCategory as $c): ?>
          <li class="list-group-item d-flex justify-content-between py-2">
            <span><?= h($c['name']) ?></span>
            <span class="badge text-bg-secondary"><?= (int) $c['n'] ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (!$byCategory): ?>
          <li class="list-group-item text-muted small">No assets registered yet.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php include APP_ROOT . '/templates/footer.php'; ?>
