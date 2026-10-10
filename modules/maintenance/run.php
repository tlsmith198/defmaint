<?php
/**
 * Run Checks -- the fast path for doing the paperwork.
 *
 * One screen per frequency (Daily / Weekly / Monthly / Quarterly / other) listing every
 * task that is due, grouped by machine in walk-round order. Tap a task to mark it Done,
 * "Not done" if it could not be done, then log the whole sheet with one button.
 * Every task is still written as its own immutable completion record under the signed-in
 * user's name, exactly as the single and batch forms do. Tasks that carry measured
 * readings are opened individually so a value is never lost to a bulk tick.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if (!can('log_completion')) {
    http_response_code(403);
    include APP_ROOT . '/templates/403.php';
    exit;
}

due_engine_maybe_run_web();

$tabs     = run_tabs();
$tab      = (string) req('freq', 'daily');
if (!isset($tabs[$tab])) {
    $tab = 'daily';
}
$upcoming = req('upcoming') === '1';
$self     = 'modules/maintenance/run.php?freq=' . $tab . ($upcoming ? '&upcoming=1' : '');

$rows = run_rows($tab, $upcoming);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $ts = !empty($_POST['completed_at']) ? strtotime((string) $_POST['completed_at']) : time();
    if (!$ts || $ts > time() + 3600) {
        flash('Enter a valid sign-off time (it cannot be in the future). Nothing was logged.', 'danger');
        redirect($self);
    }
    $completedAtSql = date('Y-m-d H:i:s', $ts);
    $picked = (array) ($_POST['row'] ?? []);

    $toLog = [];
    foreach ($rows as $r) {                         // only rows still open server-side can be logged
        if ($r['step_count'] > 0) {
            continue;
        }
        $in  = (array) ($picked[$r['due_id']] ?? []);
        $res = (string) ($in['result'] ?? '');
        if (in_array($res, ['done', 'not_done'], true)) {
            $toLog[] = ['row' => $r, 'result' => $res, 'note' => trim((string) ($in['note'] ?? ''))];
        }
    }
    $submitted = 0;
    foreach ($picked as $in) {
        if (in_array((string) ($in['result'] ?? ''), ['done', 'not_done'], true)) {
            $submitted++;
        }
    }
    if (!$toLog) {
        flash($submitted ? 'Those tasks were already signed off by someone else.' : 'Nothing was marked.', 'warning');
        redirect($self);
    }

    $logged = [];
    $failed = [];
    db()->beginTransaction();
    try {
        foreach ($toLog as $item) {
            $r = $item['row'];
            $cid = db_insert('maintenance_completions', [
                'due_instance_id'    => (int) $r['due_id'],
                'requirement_id'     => (int) $r['requirement_id'],
                'asset_id'           => (int) $r['asset_id'],
                'task_name_snapshot' => $r['task_name'],
                'frequency_snapshot' => $r['frequency'],
                'due_date_snapshot'  => $r['due_date'],
                'result'             => $item['result'],
                'completed_by'       => current_user_id(),
                'completed_at'       => $completedAtSql,
                'notes'              => nn($item['note']),
                'submitted_ip'       => client_ip(),
            ]);
            $logged[] = [$r, $cid];
            if ($item['result'] === 'not_done') {
                $failed[] = [$r, $cid];
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    foreach ($logged as [$r, $cid]) {
        due_engine_after_completion((int) $r['requirement_id'], $completedAtSql, (int) $r['due_id'], $cid);
    }
    audit('completion.run', 'system', null, [
        'tab' => $tab, 'logged' => count($logged), 'not_done' => count($failed),
    ]);

    $msg = 'Logged <strong>' . count($logged) . '</strong> ' . strtolower($tabs[$tab]['label'])
         . ' check' . (count($logged) === 1 ? '' : 's') . '.';
    if ($submitted > count($logged)) {
        $msg .= ' ' . ($submitted - count($logged)) . ' had already been signed off by someone else.';
    }
    if ($failed) {
        $links = [];
        foreach ($failed as [$r, $cid]) {
            $links[] = '<a class="alert-link" href="' . h(url('modules/issues/form.php?asset_id=' . $r['asset_id']
                . '&requirement_id=' . $r['requirement_id'] . '&completion_id=' . $cid)) . '">'
                . h($r['asset_tag'] . ' – ' . $r['task_name']) . '</a>';
        }
        $msg .= '<br>Not done: ' . implode('; ', $links) . ' — click to raise an issue.';
    }
    flash($msg, $failed ? 'warning' : 'success');
    redirect($self);
}

$counts  = run_counts();
$groups  = [];
foreach ($rows as $r) {
    $groups[$r['asset_id']]['asset'] = $r;
    $groups[$r['asset_id']]['rows'][] = $r;
}
$simpleTotal = count(array_filter($rows, fn($r) => !$r['step_count']));

$pageTitle   = 'Run Checks';
$activeNav   = 'run';
$breadcrumbs = [['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'], ['label' => 'Run checks']];
$pageActions = '<a class="btn btn-outline-secondary" href="' . h(url('modules/maintenance/index.php'))
             . '"><i class="bi bi-list-check"></i> Full due list</a>';
include APP_ROOT . '/templates/header.php';
?>
<ul class="nav nav-pills run-tabs mb-3 flex-nowrap overflow-auto">
  <?php foreach ($tabs as $k => $t): ?>
    <li class="nav-item">
      <a class="nav-link text-nowrap <?= $tab === $k ? 'active' : '' ?>"
         href="<?= h(url('modules/maintenance/run.php?freq=' . $k)) ?>">
        <i class="bi <?= h($t['icon']) ?>"></i> <?= h($t['label']) ?>
        <span class="badge text-bg-<?= $tab === $k ? 'light' : ($counts[$k]['due'] ? 'danger' : 'secondary') ?>"><?= (int) $counts[$k]['due'] ?></span>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<?php if (!$rows): ?>
  <div class="card text-center py-5">
    <div class="card-body">
      <i class="bi bi-check-circle-fill text-success display-4"></i>
      <h4 class="mt-3">All caught up</h4>
      <p class="text-muted mb-3">No <?= h(strtolower($tabs[$tab]['label'])) ?> checks are due right now.</p>
      <?php if (!$upcoming && $counts[$tab]['total'] > 0): ?>
        <a class="btn btn-outline-primary" href="<?= h(url('modules/maintenance/run.php?freq=' . $tab . '&upcoming=1')) ?>">
          Show the <?= (int) $counts[$tab]['total'] ?> not yet due</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
<form method="post" id="runform" data-guard>
  <?= csrf_field() ?>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div class="text-muted small">
      <?= count($rows) ?> task<?= count($rows) === 1 ? '' : 's' ?> across <?= count($groups) ?> machine<?= count($groups) === 1 ? '' : 's' ?>
      · tap a task to mark it <strong>Done</strong>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($upcoming): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('modules/maintenance/run.php?freq=' . $tab)) ?>">Hide not-yet-due</a>
      <?php elseif ($counts[$tab]['total'] > $counts[$tab]['due']): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(url('modules/maintenance/run.php?freq=' . $tab . '&upcoming=1')) ?>">
          + <?= (int) ($counts[$tab]['total'] - $counts[$tab]['due']) ?> not yet due</a>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-success" id="markAll"><i class="bi bi-check2-all"></i> Mark all done</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" id="clearAll">Clear</button>
    </div>
  </div>

  <?php foreach ($groups as $g): $a = $g['asset']; ?>
    <div class="card run-machine mb-3" data-machine>
      <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div>
          <span class="fw-semibold"><?= h($a['asset_name']) ?></span>
          <span class="text-muted small ms-1"><?= h($a['asset_tag']) ?><?= $a['location_name'] ? ' · ' . h($a['location_name']) : '' ?></span>
          <?php if ($a['asset_status'] !== 'active'): ?>
            <span class="badge text-bg-warning"><?= h(label_of($a['asset_status'], asset_statuses())) ?></span>
          <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="small text-muted" data-machine-count></span>
          <button type="button" class="btn btn-sm btn-outline-success" data-machine-all>
            <i class="bi bi-check2-all"></i> All</button>
        </div>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($g['rows'] as $r): $id = (int) $r['due_id']; ?>
          <?php if ($r['step_count'] > 0): ?>
            <div class="list-group-item run-task d-flex justify-content-between align-items-center gap-2 flex-wrap">
              <div>
                <span class="fw-semibold"><?= h($r['task_name']) ?></span>
                <span class="badge text-bg-light border"><?= (int) $r['step_count'] ?> readings</span>
                <?php if ((int) $r['days_late'] > 0): ?><span class="badge text-bg-danger"><?= (int) $r['days_late'] ?>d late</span><?php endif; ?>
              </div>
              <a class="btn btn-sm btn-primary" href="<?= h(url('modules/maintenance/complete.php?due_id=' . $id)) ?>">Open &amp; record</a>
            </div>
          <?php else: ?>
            <div class="list-group-item run-task" data-task>
              <input type="hidden" name="row[<?= $id ?>][result]" value="" data-result>
              <div class="d-flex align-items-center gap-3">
                <button type="button" class="run-tick" data-tick aria-label="Mark done" aria-pressed="false">
                  <i class="bi bi-check-lg"></i>
                </button>
                <div class="flex-grow-1 run-label" data-label>
                  <span class="fw-semibold"><?= h($r['task_name']) ?></span>
                  <?php if ($r['is_safety_critical']): ?><i class="bi bi-shield-exclamation text-danger" title="Safety critical"></i><?php endif; ?>
                  <?php if ((int) $r['days_late'] > 0): ?>
                    <span class="badge text-bg-danger"><?= (int) $r['days_late'] ?>d late</span>
                  <?php elseif ($r['due_date'] > date('Y-m-d')): ?>
                    <span class="badge text-bg-light border">due <?= h(fmt_date($r['due_date'])) ?></span>
                  <?php endif; ?>
                  <?php if ($r['spec_note']): ?><div class="small text-muted"><?= h($r['spec_note']) ?></div><?php endif; ?>
                  <?php if ($r['tools_consumables']): ?><div class="small text-body-secondary"><i class="bi bi-tools"></i> <?= h($r['tools_consumables']) ?></div><?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm run-no" data-no title="Could not be done">
                  <i class="bi bi-x-lg"></i></button>
              </div>
              <input class="form-control form-control-sm mt-2 d-none" data-note
                     name="row[<?= $id ?>][note]" placeholder="Why wasn't it done? (optional)">
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <details class="mb-5 text-muted small">
    <summary>Sign-off time (defaults to now)</summary>
    <input class="form-control form-control-sm mt-2" style="max-width:15rem" type="datetime-local" name="completed_at"
           value="<?= h(date('Y-m-d\TH:i')) ?>">
  </details>

  <div class="run-bar">
    <div class="container-fluid d-flex justify-content-between align-items-center gap-3">
      <div>
        <strong id="cntDone">0</strong> of <?= (int) $simpleTotal ?> marked
        <span class="text-danger ms-2 d-none" id="cntNo"><strong id="cntNoN">0</strong> not done</span>
      </div>
      <button class="btn btn-success big-submit" type="submit" id="submitBtn" disabled>
        <i class="bi bi-check2-all"></i> Log checks as <?= h(current_user_name()) ?>
      </button>
    </div>
  </div>
</form>

<script>
(function () {
  var form = document.getElementById('runform');
  var tasks = [].slice.call(form.querySelectorAll('[data-task]'));
  function set(t, state) {
    var inp = t.querySelector('[data-result]'), note = t.querySelector('[data-note]');
    inp.value = state;
    t.classList.toggle('is-done', state === 'done');
    t.classList.toggle('is-no', state === 'not_done');
    t.querySelector('[data-tick]').setAttribute('aria-pressed', state === 'done' ? 'true' : 'false');
    note.classList.toggle('d-none', state !== 'not_done');
    if (state === 'not_done') { note.focus(); }
  }
  function refresh() {
    var d = 0, n = 0;
    tasks.forEach(function (t) {
      var v = t.querySelector('[data-result]').value;
      if (v === 'done') d++; else if (v === 'not_done') n++;
    });
    document.getElementById('cntDone').textContent = d + n;
    document.getElementById('cntNoN').textContent = n;
    document.getElementById('cntNo').classList.toggle('d-none', n === 0);
    document.getElementById('submitBtn').disabled = (d + n) === 0;
    [].slice.call(form.querySelectorAll('[data-machine]')).forEach(function (m) {
      var ts = [].slice.call(m.querySelectorAll('[data-task]')), c = 0;
      ts.forEach(function (t) { if (t.querySelector('[data-result]').value) c++; });
      m.querySelector('[data-machine-count]').textContent = c + '/' + ts.length;
      m.classList.toggle('is-complete', ts.length > 0 && c === ts.length);
    });
  }
  tasks.forEach(function (t) {
    var toggle = function () { set(t, t.querySelector('[data-result]').value === 'done' ? '' : 'done'); refresh(); };
    t.querySelector('[data-tick]').addEventListener('click', toggle);
    t.querySelector('[data-label]').addEventListener('click', toggle);
    t.querySelector('[data-no]').addEventListener('click', function () {
      set(t, t.querySelector('[data-result]').value === 'not_done' ? '' : 'not_done'); refresh();
    });
  });
  [].slice.call(form.querySelectorAll('[data-machine-all]')).forEach(function (b) {
    b.addEventListener('click', function () {
      var m = b.closest('[data-machine]');
      [].slice.call(m.querySelectorAll('[data-task]')).forEach(function (t) {
        if (t.querySelector('[data-result]').value !== 'not_done') set(t, 'done');
      });
      refresh();
    });
  });
  document.getElementById('markAll').addEventListener('click', function () {
    if (!confirm('Mark every task on this sheet as done? Only do this once each one has actually been completed.')) return;
    tasks.forEach(function (t) { if (t.querySelector('[data-result]').value !== 'not_done') set(t, 'done'); });
    refresh();
  });
  document.getElementById('clearAll').addEventListener('click', function () {
    tasks.forEach(function (t) { set(t, ''); }); refresh();
  });
  refresh();
})();
</script>
<?php endif; ?>
<?php include APP_ROOT . '/templates/footer.php'; ?>
