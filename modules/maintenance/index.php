<?php
/** Maintenance due list — what is due, overdue, and coming up. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

due_engine_maybe_run_web();

$filters = [
    'view'        => (string) req('view', 'week'),
    'asset_id'    => req_int('asset_id'),
    'location_id' => req_int('location_id'),
    'frequency'   => (string) req('frequency', ''),
    'assignee_id' => req_int('assignee_id'),
    'q'           => (string) req('q', ''),
];
$rows    = due_list($filters);
$stats   = due_stats();
$grouped = due_group_by_asset($rows);
$today   = date('Y-m-d');

$pageTitle   = 'Maintenance Due';
$activeNav   = 'maintenance';
$pageActions = '<a class="btn btn-success" href="' . h(url('modules/maintenance/run.php'))
             . '"><i class="bi bi-check2-square"></i> Run checks (quick)</a> '
             . '<a class="btn btn-outline-secondary" href="' . h(url('modules/maintenance/calendar.php'))
             . '"><i class="bi bi-calendar3"></i> Calendar</a>';
if (can('manage_requirements')) {
    $pageActions .= ' <a class="btn btn-outline-primary" href="' . h(url('modules/maintenance/requirements.php'))
                  . '"><i class="bi bi-sliders"></i> Requirement builder</a>';
}
include APP_ROOT . '/templates/header.php';

$views = [
    'overdue' => ['label' => 'Overdue',      'count' => $stats['overdue'], 'class' => 'danger'],
    'today'   => ['label' => 'Due today',    'count' => $stats['today'],   'class' => 'warning'],
    'week'    => ['label' => 'Next 7 days',  'count' => $stats['week'],    'class' => 'primary'],
    'month'   => ['label' => 'Next 30 days', 'count' => $stats['month'],   'class' => 'secondary'],
    'all'     => ['label' => 'Everything',   'count' => $stats['total'],   'class' => 'secondary'],
];
?>

<ul class="nav nav-pills mb-3 flex-wrap gap-1">
  <?php foreach ($views as $k => $v): ?>
    <li class="nav-item">
      <a class="nav-link <?= $filters['view'] === $k ? 'active' : '' ?>"
         href="<?= h(qs(['view' => $k])) ?>">
        <?= h($v['label']) ?>
        <span class="badge text-bg-<?= $filters['view'] === $k ? 'light' : $v['class'] ?>"><?= (int) $v['count'] ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<form class="card mb-3" method="get">
  <input type="hidden" name="view" value="<?= h($filters['view']) ?>">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Task or machine…">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="asset_id">Asset</label>
        <select class="form-select" id="asset_id" name="asset_id" data-autosubmit>
          <option value="">All assets</option>
          <?php foreach (asset_options() as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $filters['asset_id'] === (int) $a['id'] ? 'selected' : '' ?>>
              <?= h($a['asset_tag']) ?> — <?= h($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="location_id">Department</label>
        <select class="form-select" id="location_id" name="location_id" data-autosubmit>
          <option value="">All</option>
          <?php foreach (location_options() as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $filters['location_id'] === (int) $l['id'] ? 'selected' : '' ?>>
              <?= h($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="frequency">Frequency</label>
        <select class="form-select" id="frequency" name="frequency" data-autosubmit>
          <option value="">All</option>
          <?php foreach (frequencies() as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $filters['frequency'] === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="assignee_id">Assigned to</label>
        <select class="form-select" id="assignee_id" name="assignee_id" data-autosubmit>
          <option value="">Anyone</option>
          <?php foreach (active_users() as $u2): ?>
            <option value="<?= (int) $u2['id'] ?>" <?= $filters['assignee_id'] === (int) $u2['id'] ? 'selected' : '' ?>>
              <?= h($u2['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="mt-2 small">
      <?= count($rows) ?> task<?= count($rows) === 1 ? '' : 's' ?> shown
      <?php if (qs(['view' => null])): ?>
        &middot; <a href="<?= h(url('modules/maintenance/index.php?view=' . $filters['view'])) ?>">clear filters</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="card"><div class="card-body text-center py-5">
    <i class="bi bi-check2-circle display-5 text-success"></i>
    <p class="mt-3 mb-0 text-muted">
      <?= $filters['view'] === 'overdue' ? 'Nothing is overdue.' : 'Nothing due in this window.' ?>
    </p>
  </div></div>
<?php endif; ?>

<?php foreach ($grouped as $group):
  $a = $group['asset'];
  $assetOverdue = 0;
  foreach ($group['frequencies'] as $rowsByFreq) {
      foreach ($rowsByFreq as $r) { if ($r['due_date'] < $today) $assetOverdue++; }
  }
?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
      <a class="text-decoration-none fw-semibold"
         href="<?= h(url('modules/assets/view.php?id=' . $a['id'])) ?>"><?= h($a['name']) ?></a>
      <span class="font-monospace text-muted small ms-1"><?= h($a['tag']) ?></span>
      <?php if ($a['status'] !== 'active'): ?><?= badge($a['status'], asset_statuses()) ?><?php endif; ?>
      <?php if ($a['location']): ?><span class="text-muted small ms-1"><?= h($a['location']) ?></span><?php endif; ?>
    </div>
    <div>
      <?php if ($assetOverdue): ?>
        <span class="badge text-bg-danger"><?= $assetOverdue ?> overdue</span>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach ($group['frequencies'] as $freq => $items): ?>
    <div class="border-top">
      <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light">
        <span class="small fw-semibold text-uppercase">
          <?= h(label_of($freq, frequencies())) ?>
          <span class="text-muted">· <?= count($items) ?> task<?= count($items) === 1 ? '' : 's' ?></span>
        </span>
        <?php if (can('log_completion') && count($items) > 1): ?>
          <a class="btn btn-sm btn-success"
             href="<?= h(url('modules/maintenance/complete_batch.php?asset_id=' . $a['id'] . '&frequency=' . $freq)) ?>">
            <i class="bi bi-check2-all"></i> Complete all <?= h(strtolower(label_of($freq, frequencies()))) ?>
          </a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-admi mb-0">
          <tbody>
          <?php foreach ($items as $r):
            $late = (int) $r['days_late'];
            $cls  = $late > 0 ? 'due-overdue' : ($r['due_date'] === $today ? 'due-today' : 'due-soon'); ?>
            <tr>
              <td style="width:2.4rem" class="text-center">
                <?php if ($r['is_safety_critical']): ?>
                  <i class="bi bi-shield-exclamation text-danger" title="Safety critical"></i>
                <?php endif; ?>
              </td>
              <td>
                <span class="fw-semibold"><?= h($r['task_name']) ?></span>
                <?php if ($r['step_count']): ?>
                  <span class="badge text-bg-light border"><?= (int) $r['step_count'] ?> steps</span>
                <?php endif; ?>
                <?php if ($r['spec_note']): ?>
                  <div class="small text-muted"><?= h($r['spec_note']) ?></div>
                <?php endif; ?>
                <?php if ($r['tools_consumables']): ?>
                  <div class="small text-muted"><i class="bi bi-tools"></i> <?= h($r['tools_consumables']) ?></div>
                <?php endif; ?>
              </td>
              <td class="small" style="width:11rem">
                <span class="<?= $cls ?>"><?= h(fmt_date($r['due_date'])) ?></span>
                <div class="text-muted">
                  <?php if ($late > 0): ?><?= $late ?> day<?= $late === 1 ? '' : 's' ?> late
                  <?php elseif ($late === 0): ?>due today
                  <?php else: ?>in <?= abs($late) ?> day<?= abs($late) === 1 ? '' : 's' ?><?php endif; ?>
                </div>
              </td>
              <td class="small" style="width:9rem">
                <?= h($r['assignee_name'] ?: ($r['assigned_role'] ?: '—')) ?>
                <?php if ($r['est_minutes']): ?>
                  <div class="text-muted"><?= (int) $r['est_minutes'] ?> min</div>
                <?php endif; ?>
              </td>
              <td class="text-end" style="width:9rem">
                <?php if (can('log_completion')): ?>
                  <a class="btn btn-sm btn-success"
                     href="<?= h(url('modules/maintenance/complete.php?due_id=' . $r['due_id'])) ?>">
                    <i class="bi bi-check-lg"></i> Complete</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php include APP_ROOT . '/templates/footer.php'; ?>
