<?php
/** PM Requirement Builder — per-asset requirement list. Admin only. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$assetId = req_int('asset_id');

/* ---- POST actions ---------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) req('action');
    $back   = 'modules/maintenance/requirements.php?asset_id=' . $assetId;

    /* ---- actions on a set of tasks ----------------------------------- */
    if (in_array($action, ['bulk', 'delete_all_retired', 'delete_all_on_asset'], true)) {
        if ($action === 'bulk') {
            $ids  = array_map('intval', (array) ($_POST['ids'] ?? []));
            $what = (string) req('bulk_action');
        } else {
            $what = 'delete';
            $ids  = array_column(db_all(
                'SELECT id FROM maintenance_requirements WHERE asset_id = :a'
                . ($action === 'delete_all_retired' ? ' AND is_active = 0' : ''),
                [':a' => $assetId]
            ), 'id');
        }

        if (!$ids) {
            flash('Nothing was selected.', 'warning');
            redirect($back);
        }

        if ($what === 'delete') {
            $res = purge_requirements($ids);
            if ($res['deleted']) {
                flash('Deleted ' . $res['deleted'] . ' task' . ($res['deleted'] === 1 ? '' : 's') . '.');
            }
            if ($res['skipped']) {
                flash('Kept ' . $res['skipped'] . ' task' . ($res['skipped'] === 1 ? '' : 's')
                    . ' that could not be deleted:<br>' . implode('<br>', array_map('h', $res['messages'])),
                    'warning');
            }

        } elseif (in_array($what, ['retire', 'reactivate'], true)) {
            $in = implode(',', array_map('intval', $ids));
            if ($what === 'retire') {
                db_query("UPDATE maintenance_requirements
                             SET is_active = 0, retired_at = NOW(), retired_by = :u, updated_by = :u2
                           WHERE id IN ($in)",
                    [':u' => current_user_id(), ':u2' => current_user_id()]);
                db_query("UPDATE maintenance_due_instances SET status = 'cancelled',
                                 skip_reason = 'Requirement retired', skipped_by = :u
                           WHERE requirement_id IN ($in) AND status = 'scheduled'",
                    [':u' => current_user_id()]);
                flash('Retired ' . count($ids) . ' task' . (count($ids) === 1 ? '' : 's')
                    . '. Their history is untouched.', 'info');
            } else {
                db_query("UPDATE maintenance_requirements
                             SET is_active = 1, retired_at = NULL, retired_by = NULL, updated_by = :u
                           WHERE id IN ($in)", [':u' => current_user_id()]);
                foreach ($ids as $rid) {
                    due_engine_generate((int) $rid);
                }
                flash('Reactivated ' . count($ids) . ' task' . (count($ids) === 1 ? '' : 's') . '.');
            }
            audit('requirement.bulk_' . $what, 'asset', $assetId, ['count' => count($ids)]);
        }
        redirect($back);
    }

    /* ---- whole-asset schedule rebuild -------------------------------- */
    if ($action === 'reset_schedule') {
        [, $msg] = purge_reset_schedule($assetId ?: null);
        flash(h($msg), 'info');
        redirect($back);
    }

    /* ---- actions on one task ----------------------------------------- */
    $reqId  = req_int('requirement_id');
    $reqRow = $reqId ? db_one('SELECT * FROM maintenance_requirements WHERE id = :id', [':id' => $reqId]) : null;

    if (!$reqRow) {
        flash('Requirement not found.', 'danger');
        redirect($back);
    }

    if ($action === 'delete') {
        $assetId = (int) $reqRow['asset_id'];
        [$ok, $msg] = purge_requirement($reqId);
        flash(h($msg), $ok ? 'success' : 'danger');
        redirect('modules/maintenance/requirements.php?asset_id=' . $assetId);

    } elseif ($action === 'retire') {
        db_update('maintenance_requirements', [
            'is_active'  => 0,
            'retired_at' => date('Y-m-d H:i:s'),
            'retired_by' => current_user_id(),
            'updated_by' => current_user_id(),
        ], $reqId);
        db_query("UPDATE maintenance_due_instances SET status = 'cancelled',
                         skip_reason = 'Requirement retired', skipped_by = :u
                   WHERE requirement_id = :r AND status = 'scheduled'",
            [':u' => current_user_id(), ':r' => $reqId]);
        audit('requirement.retire', 'requirement', $reqId, ['task' => $reqRow['task_name']]);
        flash('Retired <strong>' . h($reqRow['task_name']) . '</strong>. Its completion history is untouched.', 'info');

    } elseif ($action === 'reactivate') {
        db_update('maintenance_requirements', [
            'is_active'  => 1,
            'retired_at' => null,
            'retired_by' => null,
            'updated_by' => current_user_id(),
        ], $reqId);
        due_engine_generate($reqId);
        audit('requirement.reactivate', 'requirement', $reqId);
        flash('Reactivated <strong>' . h($reqRow['task_name']) . '</strong> and scheduled its next occurrence.');

    } elseif ($action === 'regenerate') {
        $n = due_engine_generate($reqId);
        flash($n ? 'Schedule refreshed.' : 'Schedule was already up to date.', 'info');
    }
    redirect('modules/maintenance/requirements.php?asset_id=' . (int) $reqRow['asset_id']);
}

/* ---- no asset chosen: show the picker ------------------------------- */
if (!$assetId) {
    $assets = db_all(
        "SELECT a.id, a.asset_tag, a.name, a.status, c.name AS category_name,
                SUM(r.is_active = 1) AS active_reqs, COUNT(r.id) AS total_reqs
           FROM assets a
           LEFT JOIN asset_categories c ON c.id = a.category_id
           LEFT JOIN maintenance_requirements r ON r.asset_id = a.id
          WHERE a.status <> 'retired'
          GROUP BY a.id, a.asset_tag, a.name, a.status, c.name
          ORDER BY a.asset_tag"
    );
    $pageTitle   = 'PM Requirement Builder';
    $activeNav   = 'maintenance';
    $breadcrumbs = [['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'], ['label' => 'Requirements']];
    include APP_ROOT . '/templates/header.php';
    ?>
    <p class="text-muted">Choose an asset to build or edit its preventive-maintenance schedule.</p>
    <div class="card"><div class="table-responsive">
      <table class="table table-admi table-hover mb-0">
        <thead><tr><th>Tag</th><th>Asset</th><th>Category</th><th>Status</th>
          <th class="text-center">Active PM tasks</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
          <tr data-href="<?= h(url('modules/maintenance/requirements.php?asset_id=' . $a['id'])) ?>">
            <td class="font-monospace small"><?= h($a['asset_tag']) ?></td>
            <td class="fw-semibold"><?= h($a['name']) ?></td>
            <td class="small"><?= h($a['category_name'] ?: '—') ?></td>
            <td><?= badge($a['status'], asset_statuses()) ?></td>
            <td class="text-center"><?= (int) $a['active_reqs'] ?>
              <?php if ($a['total_reqs'] > $a['active_reqs']): ?>
                <span class="text-muted small">(+<?= (int) $a['total_reqs'] - (int) $a['active_reqs'] ?> retired)</span>
              <?php endif; ?>
            </td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary"
              href="<?= h(url('modules/maintenance/requirements.php?asset_id=' . $a['id'])) ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <?php
    include APP_ROOT . '/templates/footer.php';
    exit;
}

/* ---- one asset ------------------------------------------------------- */
$asset = asset_get($assetId);
if (!$asset) {
    flash('Asset not found.', 'danger');
    redirect('modules/maintenance/requirements.php');
}

$reqs = db_all(
    "SELECT r.*, u.full_name AS assignee_name,
            (SELECT COUNT(*) FROM requirement_steps s WHERE s.requirement_id = r.id AND s.is_active = 1) AS step_count,
            (SELECT COUNT(*) FROM maintenance_completions c WHERE c.requirement_id = r.id) AS completion_count,
            (SELECT MIN(d.due_date) FROM maintenance_due_instances d
              WHERE d.requirement_id = r.id AND d.status = 'scheduled') AS next_due
       FROM maintenance_requirements r
       LEFT JOIN users u ON u.id = r.assigned_user_id
      WHERE r.asset_id = :id
      ORDER BY r.is_active DESC,
               FIELD(r.frequency,'daily','weekly','monthly','quarterly','semiannual','annual','custom'),
               r.sort_order, r.id",
    [':id' => $assetId]
);

$pageTitle   = 'PM Schedule — ' . $asset['name'];
$activeNav   = 'maintenance';
$breadcrumbs = [
    ['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'],
    ['label' => 'Requirements', 'url' => 'modules/maintenance/requirements.php'],
    ['label' => $asset['asset_tag']],
];
$pageActions =
    '<a class="btn btn-primary" href="' . h(url('modules/maintenance/requirement_form.php?asset_id=' . $assetId))
  . '"><i class="bi bi-plus-lg"></i> New requirement</a> '
  . '<a class="btn btn-outline-primary" href="' . h(url('modules/maintenance/clone.php?asset_id=' . $assetId))
  . '"><i class="bi bi-files"></i> Clone to other assets</a> '
  . '<a class="btn btn-outline-secondary" href="' . h(url('modules/assets/view.php?id=' . $assetId))
  . '"><i class="bi bi-hdd-stack"></i> Asset</a>';

include APP_ROOT . '/templates/header.php';

$active  = array_filter($reqs, fn($r) => $r['is_active']);
$retired = array_filter($reqs, fn($r) => !$r['is_active']);
?>

<div class="d-flex flex-wrap gap-2 mb-3">
  <?php $summary = []; foreach ($active as $r) { $summary[$r['frequency']] = ($summary[$r['frequency']] ?? 0) + 1; } ?>
  <?php foreach (frequencies() as $k => $m): if (empty($summary[$k])) continue; ?>
    <span class="badge text-bg-light border fs-6"><?= h($m['label']) ?> <strong><?= (int) $summary[$k] ?></strong></span>
  <?php endforeach; ?>
  <?php if (!$summary): ?><span class="text-muted">No active requirements yet.</span><?php endif; ?>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Requirements</span>
    <input class="form-control form-control-sm" style="max-width:16rem" placeholder="Filter tasks…"
           data-filter-table="#reqTable">
  </div>
  <div class="table-responsive">
    <table class="table table-admi mb-0" id="reqTable">
      <thead><tr>
        <th style="width:2.4rem" class="text-center">
          <input class="form-check-input" type="checkbox" id="checkAll" title="Select all">
        </th>
        <th style="width:3rem"></th><th>Task</th><th>Spec / notes</th>
        <th class="text-center">Steps</th><th>Assigned</th><th>Last done</th><th>Next due</th>
        <th class="text-center">Records</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$reqs): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">
          No requirements yet.
          <a href="<?= h(url('modules/maintenance/requirement_form.php?asset_id=' . $assetId)) ?>">Add the first one</a>,
          or <a href="<?= h(url('modules/maintenance/clone.php?asset_id=' . $assetId)) ?>">clone them from a similar machine</a>.
        </td></tr>
      <?php endif; ?>
      <?php $lastFreq = null; foreach ($reqs as $r): ?>
        <?php if ($r['is_active'] && $r['frequency'] !== $lastFreq): $lastFreq = $r['frequency']; ?>
          <tr class="table-light"><td colspan="10" class="fw-semibold small text-uppercase">
            <?= h(label_of($r['frequency'], frequencies())) ?>
            <span class="text-muted text-lowercase">— every <?= (int) $r['interval_days'] ?> days<?php
              if ($r['schedule_mode'] === 'fixed_calendar') echo ', fixed calendar'; ?></span>
          </td></tr>
        <?php endif; ?>
        <tr class="<?= $r['is_active'] ? '' : 'is-retired' ?>">
          <td class="text-center">
            <input class="form-check-input req-check" type="checkbox" form="bulkForm"
                   name="ids[]" value="<?= (int) $r['id'] ?>"
                   data-records="<?= (int) $r['completion_count'] ?>">
          </td>
          <td class="text-center">
            <?php if (!$r['is_active']): ?><span class="badge text-bg-secondary">Off</span>
            <?php elseif ($r['is_safety_critical']): ?><i class="bi bi-shield-exclamation text-danger" title="Safety critical"></i>
            <?php endif; ?>
          </td>
          <td>
            <a class="fw-semibold text-decoration-none"
               href="<?= h(url('modules/maintenance/requirement_form.php?id=' . $r['id'])) ?>"><?= h($r['task_name']) ?></a>
            <?php if ($r['est_minutes']): ?>
              <span class="text-muted small">· <?= (int) $r['est_minutes'] ?> min</span>
            <?php endif; ?>
            <?php if ($r['tools_consumables']): ?>
              <div class="small text-muted"><i class="bi bi-tools"></i> <?= h($r['tools_consumables']) ?></div>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= h($r['spec_note'] ?: '—') ?></td>
          <td class="text-center small"><?= $r['step_count'] ? (int) $r['step_count'] : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= h($r['assignee_name'] ?: ($r['assigned_role'] ?: '—')) ?></td>
          <td class="small"><?= h(fmt_date($r['last_completed_at'])) ?></td>
          <td class="small">
            <?php if ($r['next_due']): $c = $r['next_due'] < date('Y-m-d') ? 'due-overdue' : 'due-soon'; ?>
              <span class="<?= $c ?>"><?= h(fmt_date($r['next_due'])) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-center small"><?= (int) $r['completion_count'] ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary"
               href="<?= h(url('modules/maintenance/requirement_form.php?id=' . $r['id'])) ?>"><i class="bi bi-pencil"></i></a>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item"
                    href="<?= h(url('modules/maintenance/clone.php?requirement_id=' . $r['id'])) ?>">Clone to other assets</a></li>
                <?php if ($r['is_active']): ?>
                <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                  <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
                  <input type="hidden" name="requirement_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="action" value="regenerate">
                  <button class="dropdown-item px-1">Refresh schedule</button>
                </form></li>
                <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                  <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
                  <input type="hidden" name="requirement_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="action" value="retire">
                  <button class="dropdown-item px-1 text-danger"
                    data-confirm="Retire this requirement? It stops being scheduled, but every completion record is kept.">
                    Retire</button>
                </form></li>
                <?php else: ?>
                <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                  <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
                  <input type="hidden" name="requirement_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="action" value="reactivate">
                  <button class="dropdown-item px-1">Reactivate</button>
                </form></li>
                <?php endif; ?>

                <li><hr class="dropdown-divider"></li>
                <?php
                $blockers  = purge_requirement_blockers((int) $r['id']);
                $recordMsg = $blockers['completions'] > 0
                    ? 'Delete "' . $r['task_name'] . '" permanently?\n\nThis also deletes '
                      . $blockers['completions'] . ' sign-off record'
                      . ($blockers['completions'] === 1 ? '' : 's') . ' and cannot be undone.'
                    : 'Delete "' . $r['task_name'] . '" permanently? It has no sign-off history, '
                      . 'so nothing is lost.';
                ?>
                <?php if ($blockers['blocked']): ?>
                  <li><span class="dropdown-item-text small text-muted">
                    Cannot delete — <?= (int) $blockers['completions'] ?> sign-off record<?= $blockers['completions'] === 1 ? '' : 's' ?>.
                    Retire it instead.
                  </span></li>
                <?php else: ?>
                  <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                    <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
                    <input type="hidden" name="requirement_id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="dropdown-item px-1 text-danger fw-semibold"
                      data-confirm="<?= h($recordMsg) ?>">
                      <i class="bi bi-trash"></i> Delete permanently
                      <?php if ($blockers['completions'] > 0): ?>
                        <span class="d-block small fw-normal">
                          takes <?= (int) $blockers['completions'] ?> record<?= $blockers['completions'] === 1 ? '' : 's' ?> with it
                        </span>
                      <?php endif; ?>
                    </button>
                  </form></li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($reqs): ?>
  <div class="bulk-bar d-none" id="bulkBar">
    <form method="post" id="bulkForm" class="d-flex flex-wrap align-items-center gap-2">
      <?= csrf_field() ?>
      <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
      <input type="hidden" name="action" value="bulk">
      <span class="fw-semibold me-2"><span id="bulkCount">0</span> selected</span>

      <button class="btn btn-sm btn-outline-danger" name="bulk_action" value="delete"
              id="bulkDelete" data-confirm="Delete the selected tasks permanently? This cannot be undone.">
        <i class="bi bi-trash"></i> Delete
      </button>
      <button class="btn btn-sm btn-outline-secondary" name="bulk_action" value="retire"
              data-confirm="Retire the selected tasks? They stop being scheduled but keep their history.">
        Retire
      </button>
      <button class="btn btn-sm btn-outline-success" name="bulk_action" value="reactivate">
        Reactivate
      </button>
      <span class="small text-muted ms-auto" id="bulkWarn"></span>
    </form>
  </div>
  <?php endif; ?>
</div>

<p class="small text-muted mt-2">
  <strong>Retire</strong> stops a task being scheduled but keeps its sign-off history —
  <?= count($retired) ?> retired task<?= count($retired) === 1 ? '' : 's' ?> on this machine.
  <strong>Delete</strong> removes it for good.
  <?= h(setup_mode_note()) ?>
  Either way the action is written to the audit trail.
</p>

<?php if (can('manage_requirements')): ?>
<div class="card danger-zone mt-3">
  <div class="card-header"><i class="bi bi-exclamation-octagon"></i> Clean-up tools</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
          <input type="hidden" name="action" value="reset_schedule">
          <button class="btn btn-outline-secondary w-100" type="submit"
                  data-confirm="Rebuild this machine's schedule? Unsigned due dates are recalculated from the current frequencies. Sign-off history is not touched.">
            Rebuild the schedule
          </button>
        </form>
        <div class="form-text">
          Recalculates due dates from the current frequencies. Use this after changing a batch of
          intervals. Signed-off work is left alone.
        </div>
      </div>

      <div class="col-md-4">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
          <input type="hidden" name="action" value="delete_all_retired">
          <button class="btn btn-outline-danger w-100" type="submit"
                  <?= count($retired) ? '' : 'disabled' ?>
                  data-confirm="Delete all <?= count($retired) ?> retired task(s) on this machine permanently?">
            Delete retired tasks (<?= count($retired) ?>)
          </button>
        </form>
        <div class="form-text">
          Clears out tasks you have already switched off. Any that carry sign-off history are kept
          unless Setup mode is on.
        </div>
      </div>

      <div class="col-md-4">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
          <input type="hidden" name="action" value="delete_all_on_asset">
          <button class="btn btn-danger w-100" type="submit"
                  <?= $reqs ? '' : 'disabled' ?>
                  data-confirm="Delete ALL <?= count($reqs) ?> maintenance tasks on <?= h($asset['name']) ?>? This cannot be undone.">
            Delete every task (<?= count($reqs) ?>)
          </button>
        </form>
        <div class="form-text">
          Wipes this machine's whole PM schedule so you can rebuild or re-clone it from scratch.
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  var all    = document.getElementById('checkAll');
  var bar    = document.getElementById('bulkBar');
  var count  = document.getElementById('bulkCount');
  var warn   = document.getElementById('bulkWarn');
  if (!bar) return;

  function boxes() { return Array.prototype.slice.call(document.querySelectorAll('.req-check')); }

  function sync() {
    var picked = boxes().filter(function (b) { return b.checked; });
    count.textContent = picked.length;
    bar.classList.toggle('d-none', picked.length === 0);

    var records = picked.reduce(function (n, b) { return n + parseInt(b.dataset.records || '0', 10); }, 0);
    warn.textContent = records
      ? records + ' sign-off record' + (records === 1 ? '' : 's') + ' attached to the selection'
      : 'No sign-off history in the selection';
    warn.className = 'small ms-auto ' + (records ? 'text-danger fw-semibold' : 'text-muted');
  }

  if (all) {
    all.addEventListener('change', function () {
      boxes().forEach(function (b) {
        if (b.closest('tr').style.display !== 'none') b.checked = all.checked;
      });
      sync();
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('req-check')) sync();
  });
  sync();
})();
</script>
<?php include APP_ROOT . '/templates/footer.php'; ?>
