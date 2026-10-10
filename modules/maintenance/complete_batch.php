<?php
/**
 * Close out a whole block of due work on one machine in a single pass —
 * the daily washdown / oil & lube / spindle checks that used to be a row of
 * initials on the paper Daily Log.
 *
 * Each task still writes its own immutable completion record. Tasks that carry
 * checklist steps (readings with limits) are opened individually instead, so the
 * measured values are never lost to a bulk tick.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if (!can('log_completion')) {
    http_response_code(403);
    include APP_ROOT . '/templates/403.php';
    exit;
}

$assetId   = req_int('asset_id');
$frequency = (string) req('frequency', 'daily');
$asset     = $assetId ? asset_get($assetId) : null;

if (!$asset || !array_key_exists($frequency, frequencies())) {
    flash('Choose an asset and a frequency.', 'warning');
    redirect('modules/maintenance/index.php');
}

function batch_rows(int $assetId, string $frequency): array
{
    $rows = db_all(
        "SELECT d.id AS due_id, d.due_date, r.id AS requirement_id, r.task_name, r.spec_note,
                r.tools_consumables, r.est_minutes, r.is_safety_critical, r.frequency,
                (SELECT COUNT(*) FROM requirement_steps s
                  WHERE s.requirement_id = r.id AND s.is_active = 1) AS step_count,
                DATEDIFF(CURDATE(), d.due_date) AS days_late
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
          WHERE d.asset_id = :a AND r.frequency = :f AND d.status = 'scheduled'
            AND r.is_active = 1 AND d.due_date <= CURDATE()
          ORDER BY r.sort_order, r.id",
        [':a' => $assetId, ':f' => $frequency]
    );
    if ($rows) {
        return $rows;
    }
    // Nothing due yet — offer whatever is next in that block.
    return db_all(
        "SELECT d.id AS due_id, d.due_date, r.id AS requirement_id, r.task_name, r.spec_note,
                r.tools_consumables, r.est_minutes, r.is_safety_critical, r.frequency,
                (SELECT COUNT(*) FROM requirement_steps s
                  WHERE s.requirement_id = r.id AND s.is_active = 1) AS step_count,
                DATEDIFF(CURDATE(), d.due_date) AS days_late
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
          WHERE d.asset_id = :a AND r.frequency = :f AND d.status = 'scheduled' AND r.is_active = 1
          ORDER BY d.due_date, r.sort_order, r.id",
        [':a' => $assetId, ':f' => $frequency]
    );
}

$rows   = batch_rows($assetId, $frequency);
$errors = [];
$post   = ['completed_at' => date('Y-m-d\TH:i'), 'notes' => '', 'hour_meter' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $post = array_merge($post, pick($_POST, ['completed_at', 'notes', 'hour_meter']));

    $ts = $post['completed_at'] ? strtotime((string) $post['completed_at']) : time();
    if (!$ts) {
        $errors[] = 'Enter a valid date and time.';
    } elseif ($ts > time() + 3600) {
        $errors[] = 'The completion time cannot be in the future.';
    }

    $picked = (array) ($_POST['row'] ?? []);
    $toLog  = [];
    foreach ($rows as $r) {
        if ($r['step_count'] > 0) {
            continue;                                   // handled individually
        }
        $in  = (array) ($picked[$r['due_id']] ?? []);
        $res = (string) ($in['result'] ?? 'skip');
        if ($res === 'skip') {
            continue;
        }
        if (!in_array($res, ['done', 'not_done'], true)) {
            continue;
        }
        $toLog[] = ['row' => $r, 'result' => $res, 'note' => trim((string) ($in['note'] ?? ''))];
    }

    if (!$errors && !$toLog) {
        $errors[] = 'Nothing was marked — set at least one task to Done or Not done.';
    }

    if (!$errors) {
        $completedAtSql = date('Y-m-d H:i:s', $ts);
        $logged = [];
        $failed = [];

        db()->beginTransaction();
        try {
            foreach ($toLog as $item) {
                $r    = $item['row'];
                $note = $item['note'];
                if ($post['notes'] !== '') {
                    $note = $note === '' ? (string) $post['notes'] : $note . ' — ' . $post['notes'];
                }
                $completionId = db_insert('maintenance_completions', [
                    'due_instance_id'    => (int) $r['due_id'],
                    'requirement_id'     => (int) $r['requirement_id'],
                    'asset_id'           => $assetId,
                    'task_name_snapshot' => $r['task_name'],
                    'frequency_snapshot' => $r['frequency'],
                    'due_date_snapshot'  => $r['due_date'],
                    'result'             => $item['result'],
                    'completed_by'       => current_user_id(),
                    'completed_at'       => $completedAtSql,
                    'notes'              => nn($note),
                    'submitted_ip'       => client_ip(),
                ]);
                $logged[] = [$r, $completionId];
                if ($item['result'] === 'not_done') {
                    $failed[] = [$r, $completionId];
                }
            }

            if ($post['hour_meter'] !== '') {
                db_query('UPDATE assets SET hour_meter = :h, hour_meter_at = :t WHERE id = :id', [
                    ':h' => (float) $post['hour_meter'], ':t' => $completedAtSql, ':id' => $assetId,
                ]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        foreach ($logged as [$r, $completionId]) {
            due_engine_after_completion((int) $r['requirement_id'], $completedAtSql,
                (int) $r['due_id'], $completionId);
        }
        audit('completion.batch', 'asset', $assetId, [
            'frequency' => $frequency, 'logged' => count($logged), 'not_done' => count($failed),
        ]);

        $msg = 'Logged ' . count($logged) . ' ' . strtolower(label_of($frequency, frequencies()))
             . ' check' . (count($logged) === 1 ? '' : 's') . ' on <strong>' . h($asset['name']) . '</strong>.';
        if ($failed) {
            $links = [];
            foreach ($failed as [$r, $cid]) {
                $links[] = '<a class="alert-link" href="'
                    . h(url('modules/issues/form.php?asset_id=' . $assetId
                        . '&requirement_id=' . $r['requirement_id'] . '&completion_id=' . $cid))
                    . '">' . h($r['task_name']) . '</a>';
            }
            $msg .= '<br>Not done: ' . implode(', ', $links) . ' — click to raise an issue.';
        }
        flash($msg, $failed ? 'warning' : 'success');
        redirect('modules/maintenance/index.php');
    }
}

$simple    = array_filter($rows, fn($r) => !$r['step_count']);
$withSteps = array_filter($rows, fn($r) => $r['step_count'] > 0);

$pageTitle   = label_of($frequency, frequencies()) . ' checks — ' . $asset['name'];
$activeNav   = 'maintenance';
$breadcrumbs = [
    ['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'],
    ['label' => $asset['asset_tag'], 'url' => 'modules/assets/view.php?id=' . $assetId],
    ['label' => 'Batch close'],
];
include APP_ROOT . '/templates/header.php';
?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">
  <input type="hidden" name="frequency" value="<?= h($frequency) ?>">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span><?= count($simple) ?> task<?= count($simple) === 1 ? '' : 's' ?> to sign off</span>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-success" type="button" data-set-all="done">All done</button>
            <button class="btn btn-outline-secondary" type="button" data-set-all="skip">Clear</button>
          </div>
        </div>
        <div class="card-body">
          <?php if (!$simple): ?>
            <p class="text-muted mb-0">Nothing in this block can be closed in bulk.</p>
          <?php endif; ?>
          <?php foreach ($simple as $r): ?>
            <div class="batch-row">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div class="flex-grow-1">
                  <span class="fw-semibold"><?= h($r['task_name']) ?></span>
                  <?php if ($r['is_safety_critical']): ?>
                    <i class="bi bi-shield-exclamation text-danger" title="Safety critical"></i>
                  <?php endif; ?>
                  <?php if ((int) $r['days_late'] > 0): ?>
                    <span class="badge text-bg-danger"><?= (int) $r['days_late'] ?>d late</span>
                  <?php endif; ?>
                  <?php if ($r['spec_note']): ?>
                    <div class="small text-muted"><?= h($r['spec_note']) ?></div>
                  <?php endif; ?>
                </div>
                <div class="btn-group result-toggle" role="group">
                  <?php foreach (['done' => ['Done', 'success'], 'not_done' => ['Not done', 'danger'],
                                  'skip' => ['Skip', 'secondary']] as $val => [$lbl, $cls]):
                    $id = 'r' . $r['due_id'] . $val; ?>
                    <input type="radio" class="btn-check" name="row[<?= (int) $r['due_id'] ?>][result]"
                           id="<?= $id ?>" value="<?= $val ?>" <?= $val === 'done' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-<?= $cls ?>" for="<?= $id ?>"><?= $lbl ?></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <input class="form-control form-control-sm mt-2" name="row[<?= (int) $r['due_id'] ?>][note]"
                     placeholder="Note (optional)">
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($withSteps): ?>
      <div class="card mt-3 border-primary">
        <div class="card-header text-primary">Open these individually — they record readings</div>
        <ul class="list-group list-group-flush">
          <?php foreach ($withSteps as $r): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div>
                <span class="fw-semibold"><?= h($r['task_name']) ?></span>
                <span class="badge text-bg-light border"><?= (int) $r['step_count'] ?> steps</span>
                <?php if ($r['spec_note']): ?>
                  <div class="small text-muted"><?= h($r['spec_note']) ?></div>
                <?php endif; ?>
              </div>
              <a class="btn btn-sm btn-success"
                 href="<?= h(url('modules/maintenance/complete.php?due_id=' . $r['due_id'])) ?>">Open</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-header">Sign-off</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Completed by</label>
            <input class="form-control" value="<?= h(current_user_name()) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label" for="completed_at">Date &amp; time</label>
            <input class="form-control" type="datetime-local" id="completed_at" name="completed_at"
                   value="<?= h($post['completed_at']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="hour_meter">Hour meter</label>
            <input class="form-control" type="number" step="0.1" id="hour_meter" name="hour_meter"
                   value="<?= h($post['hour_meter']) ?>" placeholder="<?= h($asset['hour_meter']) ?>">
          </div>
          <label class="form-label" for="notes">Note applied to every task</label>
          <textarea class="form-control" id="notes" name="notes" rows="3"><?= h($post['notes']) ?></textarea>
        </div>
      </div>
      <div class="d-grid gap-2">
        <button class="btn btn-success big-submit" type="submit">
          <i class="bi bi-check2-all"></i> Log these checks</button>
        <a class="btn btn-outline-secondary" href="<?= h(url('modules/maintenance/index.php')) ?>">Cancel</a>
        <a class="btn btn-link" href="<?= h(url('modules/maintenance/run.php?freq=' . $frequency)) ?>">
          <i class="bi bi-check2-square"></i> Switch to quick Run Checks</a>
      </div>
      <p class="small text-muted mt-2">
        Each task is written as its own record with your name and this timestamp — the batch form is
        a shortcut for entry, not a shortcut in the audit trail.
      </p>
    </div>
  </div>
</form>

<script>
document.querySelectorAll('[data-set-all]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.querySelectorAll('input.btn-check[value="' + btn.dataset.setAll + '"]')
      .forEach(function (r) { r.checked = true; });
  });
});
</script>
<?php include APP_ROOT . '/templates/footer.php'; ?>
