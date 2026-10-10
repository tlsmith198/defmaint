<?php
/**
 * Month calendar of maintenance work.
 * Persisted occurrences are solid; anything beyond the current open occurrence is
 * a projection, shown faded, because in rolling mode the real date depends on
 * when the previous one is signed off.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

due_engine_maybe_run_web();

$month = (string) req('month', date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$assetId   = req_int('asset_id');
$frequency = (string) req('frequency', '');

$firstDay = $month . '-01';
$start    = date('Y-m-d', strtotime($firstDay));
$end      = date('Y-m-t', strtotime($firstDay));
$prev     = date('Y-m', strtotime($firstDay . ' -1 month'));
$next     = date('Y-m', strtotime($firstDay . ' +1 month'));

$where  = ['d.due_date BETWEEN :start AND :end', "a.status <> 'retired'", 'r.is_active = 1'];
$params = [':start' => $start, ':end' => $end];
if ($assetId) {
    $where[] = 'd.asset_id = :asset';
    $params[':asset'] = $assetId;
}
if ($frequency) {
    $where[] = 'r.frequency = :freq';
    $params[':freq'] = $frequency;
}

$instances = db_all(
    "SELECT d.id, d.due_date, d.status, r.task_name, r.frequency, r.id AS requirement_id,
            a.id AS asset_id, a.asset_tag, a.short_name, a.name AS asset_name
       FROM maintenance_due_instances d
       JOIN maintenance_requirements r ON r.id = d.requirement_id
       JOIN assets a ON a.id = d.asset_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY d.due_date, a.asset_tag",
    $params
);

/* Projected occurrences fill out the rest of the month. */
$projWhere  = ["a.status <> 'retired'", 'r.is_active = 1'];
$projParams = [];
if ($assetId) {
    $projWhere[] = 'r.asset_id = :asset';
    $projParams[':asset'] = $assetId;
}
if ($frequency) {
    $projWhere[] = 'r.frequency = :freq';
    $projParams[':freq'] = $frequency;
}
$activeReqs = db_all(
    'SELECT r.*, a.asset_tag, a.short_name, a.name AS asset_name, a.in_service_date
       FROM maintenance_requirements r JOIN assets a ON a.id = r.asset_id
      WHERE ' . implode(' AND ', $projWhere),
    $projParams
);

$byDay = [];
foreach ($instances as $i) {
    $byDay[$i['due_date']][] = $i + ['projected' => false];
}
if ($end >= date('Y-m-d')) {
    foreach ($activeReqs as $r) {
        foreach (due_engine_project($r, max($start, date('Y-m-d')), $end, 12) as $p) {
            $byDay[$p['due_date']][] = [
                'id' => null, 'due_date' => $p['due_date'], 'status' => 'projected',
                'task_name' => $r['task_name'], 'frequency' => $r['frequency'],
                'requirement_id' => $r['id'], 'asset_id' => $r['asset_id'],
                'asset_tag' => $r['asset_tag'], 'short_name' => $r['short_name'],
                'asset_name' => $r['asset_name'], 'projected' => true,
            ];
        }
    }
}

$pageTitle   = 'Maintenance Calendar';
$activeNav   = 'maintenance';
$pageActions = '<a class="btn btn-outline-secondary" href="' . h(url('modules/maintenance/index.php'))
             . '"><i class="bi bi-list-check"></i> Due list</a>';
include APP_ROOT . '/templates/header.php';

$firstWeekday = (int) date('N', strtotime($start));   // 1 = Mon
$daysInMonth  = (int) date('t', strtotime($start));
$today        = date('Y-m-d');
?>
<form class="card mb-3" method="get">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-3">
        <label class="form-label" for="month">Month</label>
        <input class="form-control" type="month" id="month" name="month" value="<?= h($month) ?>" data-autosubmit>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="asset_id">Asset</label>
        <select class="form-select" id="asset_id" name="asset_id" data-autosubmit>
          <option value="">All assets</option>
          <?php foreach (asset_options() as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $assetId === (int) $a['id'] ? 'selected' : '' ?>>
              <?= h($a['asset_tag']) ?> — <?= h($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="frequency">Frequency</label>
        <select class="form-select" id="frequency" name="frequency" data-autosubmit>
          <option value="">All</option>
          <?php foreach (frequencies() as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $frequency === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-flex gap-1">
        <a class="btn btn-outline-secondary w-100" href="<?= h(qs(['month' => $prev])) ?>">&larr;</a>
        <a class="btn btn-outline-secondary w-100" href="<?= h(qs(['month' => $next])) ?>">&rarr;</a>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-header d-flex justify-content-between flex-wrap gap-2">
    <span><?= h(date('F Y', strtotime($start))) ?></span>
    <span class="small text-muted">
      <span class="badge text-bg-danger">&nbsp;</span> overdue
      <span class="badge text-bg-primary ms-2">&nbsp;</span> scheduled
      <span class="badge text-bg-secondary ms-2 opacity-50">&nbsp;</span> projected
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-bordered mb-0 cal-table">
      <thead><tr class="text-center small text-uppercase">
        <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
          <th style="width:14.28%"><?= $d ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php
      $cell = 1 - ($firstWeekday - 1);
      while ($cell <= $daysInMonth):
          echo '<tr>';
          for ($i = 0; $i < 7; $i++, $cell++):
              if ($cell < 1 || $cell > $daysInMonth) {
                  echo '<td class="bg-light"></td>';
                  continue;
              }
              $date  = sprintf('%s-%02d', $month, $cell);
              $items = $byDay[$date] ?? [];
              $isToday = $date === $today;
              echo '<td class="align-top ' . ($isToday ? 'table-warning' : '') . '" style="height:7rem">';
              echo '<div class="small fw-semibold ' . ($isToday ? '' : 'text-muted') . '">' . $cell . '</div>';

              $shown = array_slice($items, 0, 4);
              foreach ($shown as $it):
                  $overdue = !$it['projected'] && $it['due_date'] < $today && $it['status'] === 'scheduled';
                  $cls = $it['projected'] ? 'text-bg-secondary opacity-50'
                       : ($overdue ? 'text-bg-danger' : ($it['status'] === 'completed' ? 'text-bg-success' : 'text-bg-primary'));
                  $label = ($it['short_name'] ?: $it['asset_tag']) . ' · ' . $it['task_name'];
                  echo '<a class="badge ' . $cls . ' d-block text-truncate text-start mb-1 text-decoration-none" '
                     . 'style="max-width:100%" title="' . h($it['asset_name'] . ' — ' . $it['task_name']
                        . ($it['projected'] ? ' (projected)' : '')) . '" href="'
                     . h(url($it['id'] ? 'modules/maintenance/complete.php?due_id=' . $it['id']
                                       : 'modules/maintenance/requirements.php?asset_id=' . $it['asset_id']))
                     . '">' . h($label) . '</a>';
              endforeach;
              if (count($items) > 4) {
                  echo '<a class="small text-muted text-decoration-none" href="'
                     . h(url('modules/maintenance/index.php?view=month&q=')) . '">+'
                     . (count($items) - 4) . ' more</a>';
              }
              echo '</td>';
          endfor;
          echo '</tr>';
      endwhile;
      ?>
      </tbody>
    </table>
  </div>
</div>
<p class="small text-muted mt-2">
  Faded entries are projections. In rolling mode the real date moves with the actual sign-off, so only
  the current occurrence of each task is committed to the schedule.
</p>
<?php include APP_ROOT . '/templates/footer.php'; ?>
