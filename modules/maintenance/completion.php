<?php
/**
 * One maintenance completion record — the AS9100 objective evidence.
 * The record itself is never overwritten: corrections are appended to
 * completion_revisions with who, when and why.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id = req_int('id');
$c  = $id ? db_one(
    'SELECT mc.*, a.asset_tag, a.name AS asset_name, u.full_name AS completed_by_name,
            u.initials, r.schedule_mode, r.spec_note, r.is_active AS requirement_active
       FROM maintenance_completions mc
       JOIN assets a ON a.id = mc.asset_id
       JOIN users u  ON u.id = mc.completed_by
       LEFT JOIN maintenance_requirements r ON r.id = mc.requirement_id
      WHERE mc.id = :id',
    [':id' => $id]
) : null;

if (!$c) {
    flash('Completion record not found.', 'danger');
    redirect('modules/maintenance/index.php');
}

/* ---- revise (admin only, always appended) ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (!can('edit_completions')) {
        http_response_code(403);
        include APP_ROOT . '/templates/403.php';
        exit;
    }

    // Deleting a sign-off record is setup-mode only; purge_completion() enforces that.
    if (req('action') === 'delete') {
        $assetId = (int) $c['asset_id'];
        [$ok, $msg] = purge_completion($id);
        flash(h($msg), $ok ? 'info' : 'danger');
        redirect($ok
            ? 'modules/assets/view.php?id=' . $assetId . '&tab=history'
            : 'modules/maintenance/completion.php?id=' . $id);
    }

    $reason = trim((string) req('reason', ''));
    $fields = [
        'result'       => (string) req('result', $c['result']),
        'notes'        => (string) req('notes', (string) $c['notes']),
        'completed_at' => (string) req('completed_at', $c['completed_at']),
    ];
    $errors = [];

    if ($reason === '') {
        $errors[] = 'A reason is required — it becomes part of the permanent record.';
    }
    if (!array_key_exists($fields['result'],
        ['pass' => 1, 'fail' => 1, 'done' => 1, 'not_done' => 1, 'na' => 1])) {
        $errors[] = 'Choose a valid result.';
    }
    $ts = strtotime($fields['completed_at']);
    if (!$ts) {
        $errors[] = 'Enter a valid completion time.';
    } else {
        $fields['completed_at'] = date('Y-m-d H:i:s', $ts);
    }

    $changes = [];
    foreach ($fields as $k => $v) {
        if ((string) $c[$k] !== (string) $v) {
            $changes[$k] = [(string) $c[$k], (string) $v];
        }
    }
    if (!$changes) {
        $errors[] = 'Nothing was changed.';
    }

    if ($errors) {
        foreach ($errors as $e) {
            flash(h($e), 'danger');
        }
        redirect('modules/maintenance/completion.php?id=' . $id);
    }

    $rev = (int) $c['revision_no'] + 1;
    db()->beginTransaction();
    try {
        foreach ($changes as $field => [$old, $new]) {
            db_insert('completion_revisions', [
                'completion_id' => $id,
                'revision_no'   => $rev,
                'field_name'    => $field,
                'old_value'     => $old,
                'new_value'     => $new,
                'reason'        => $reason,
                'revised_by'    => current_user_id(),
            ]);
        }
        db_update('maintenance_completions', $fields + ['revision_no' => $rev], $id);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    audit('completion.revise', 'completion', $id, ['revision' => $rev, 'fields' => array_keys($changes)]);
    flash('Revision ' . $rev . ' recorded. The original values are kept in the revision history.', 'info');
    redirect('modules/maintenance/completion.php?id=' . $id);
}

$steps     = db_all('SELECT * FROM completion_step_results WHERE completion_id = :c ORDER BY step_no, id',
    [':c' => $id]);
$revisions = db_all(
    'SELECT cr.*, u.full_name FROM completion_revisions cr
       JOIN users u ON u.id = cr.revised_by
      WHERE cr.completion_id = :c ORDER BY cr.revision_no DESC, cr.id DESC',
    [':c' => $id]
);
$photos = attachments_for('completion', $id);
$issue  = $c['issue_id'] ? issue_get((int) $c['issue_id']) : null;

$resultMeta = [
    'pass'     => ['Pass', 'success'], 'done' => ['Done', 'success'],
    'fail'     => ['Fail', 'danger'],  'not_done' => ['Not done', 'danger'],
    'na'       => ['N/A', 'secondary'],
];
[$resLabel, $resClass] = $resultMeta[$c['result']] ?? ['—', 'secondary'];

$pageTitle   = 'Completion record';
$activeNav   = 'maintenance';
$breadcrumbs = [
    ['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'],
    ['label' => $c['asset_tag'], 'url' => 'modules/assets/view.php?id=' . $c['asset_id']],
    ['label' => 'Record #' . $id],
];
include APP_ROOT . '/templates/header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Record #<?= (int) $id ?></span>
        <span>
          <span class="badge text-bg-<?= $resClass ?>"><?= h($resLabel) ?></span>
          <?php if ($c['revision_no'] > 0): ?>
            <span class="badge text-bg-warning">Revision <?= (int) $c['revision_no'] ?></span>
          <?php endif; ?>
        </span>
      </div>
      <div class="card-body">
        <h2 class="h5 mb-1"><?= h($c['task_name_snapshot']) ?></h2>
        <p class="text-muted small mb-3">
          <?= h(label_of($c['frequency_snapshot'], frequencies())) ?> ·
          <a href="<?= h(url('modules/assets/view.php?id=' . $c['asset_id'])) ?>"><?= h($c['asset_name']) ?></a>
          <span class="font-monospace"><?= h($c['asset_tag']) ?></span>
          <?php if (!$c['requirement_active']): ?>
            · <span class="badge text-bg-secondary">requirement since retired</span>
          <?php endif; ?>
        </p>

        <div class="row row-cols-2 row-cols-md-4 g-2 small mb-3">
          <div><div class="text-muted">Completed</div><?= h(fmt_datetime($c['completed_at'])) ?></div>
          <div><div class="text-muted">By</div><?= h($c['completed_by_name']) ?>
            <?= $c['initials'] ? '(' . h($c['initials']) . ')' : '' ?></div>
          <div><div class="text-muted">Due</div><?= h(fmt_date($c['due_date_snapshot'])) ?></div>
          <div><div class="text-muted">Duration</div>
            <?= $c['duration_minutes'] ? (int) $c['duration_minutes'] . ' min' : '—' ?></div>
          <div><div class="text-muted">Hour meter</div><?= h(fmt_number($c['hour_meter'], 1)) ?></div>
          <div><div class="text-muted">Logged</div><?= h(fmt_datetime($c['created_at'])) ?></div>
        </div>

        <?php if ($c['notes']): ?>
          <div class="form-section-title">Notes</div>
          <div style="white-space:pre-wrap"><?= h($c['notes']) ?></div>
        <?php endif; ?>

        <?php if ($steps): ?>
          <div class="form-section-title">Checks</div>
          <div class="table-responsive">
            <table class="table table-admi mb-0">
              <thead><tr><th style="width:2rem">#</th><th>Step</th><th>Result</th>
                <th>Reading</th><th>Note</th></tr></thead>
              <tbody>
              <?php foreach ($steps as $s):
                [$lbl, $cls] = $resultMeta[$s['result']] ?? ['—', 'secondary']; ?>
                <tr>
                  <td class="text-muted small"><?= (int) $s['step_no'] ?></td>
                  <td><?= h($s['step_text_snapshot']) ?></td>
                  <td><span class="badge text-bg-<?= $cls ?>"><?= h($lbl) ?></span></td>
                  <td class="small">
                    <?php if ($s['measured_value'] !== null): ?>
                      <span class="<?= $s['in_range'] === '0' || $s['in_range'] === 0 ? 'out-of-range' : '' ?>">
                        <?= h(rtrim(rtrim($s['measured_value'], '0'), '.')) ?> <?= h($s['value_unit']) ?>
                      </span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                  </td>
                  <td class="small text-muted"><?= h($s['note'] ?: '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php if ($photos): ?>
          <div class="form-section-title">Photos</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($photos as $p): ?>
              <a href="<?= h(upload_url($p['file_path'])) ?>" target="_blank" rel="noopener">
                <img src="<?= h(upload_url($p['file_path'])) ?>" alt="" style="height:120px;border-radius:.3rem">
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($revisions): ?>
    <div class="card mt-3 border-warning">
      <div class="card-header text-warning-emphasis">Revision history</div>
      <div class="table-responsive">
        <table class="table table-admi mb-0">
          <thead><tr><th>Rev</th><th>Field</th><th>From</th><th>To</th><th>Reason</th><th>By</th></tr></thead>
          <tbody>
          <?php foreach ($revisions as $r): ?>
            <tr>
              <td class="small"><?= (int) $r['revision_no'] ?></td>
              <td class="small font-monospace"><?= h($r['field_name']) ?></td>
              <td class="small text-muted"><?= h($r['old_value']) ?></td>
              <td class="small"><?= h($r['new_value']) ?></td>
              <td class="small"><?= h($r['reason']) ?></td>
              <td class="small"><?= h($r['full_name']) ?><br>
                <span class="text-muted"><?= h(fmt_datetime($r['revised_at'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <?php if ($issue): ?>
      <div class="card mb-3 border-danger">
        <div class="card-header text-danger">Issue raised from this check</div>
        <div class="card-body">
          <a class="fw-semibold" href="<?= h(url('modules/issues/view.php?id=' . $issue['id'])) ?>">
            <?= h($issue['issue_no']) ?></a>
          <div><?= h($issue['title']) ?></div>
          <div class="mt-2"><?= badge($issue['severity'], severities()) ?>
            <?= badge($issue['status'], issue_statuses()) ?></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (can('edit_completions')): ?>
    <div class="card">
      <div class="card-header">Correct this record</div>
      <div class="card-body">
        <p class="small text-muted">
          The record is never silently overwritten. Every change is appended to the revision
          history with your name, the old value and the reason.
        </p>
        <form method="post" data-guard>
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <label class="form-label" for="result">Result</label>
          <select class="form-select mb-2" id="result" name="result">
            <?php foreach ($resultMeta as $k => [$lbl, $cls]): ?>
              <option value="<?= h($k) ?>" <?= $c['result'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label" for="completed_at">Completed at</label>
          <input class="form-control mb-2" type="datetime-local" id="completed_at" name="completed_at"
                 value="<?= h(date('Y-m-d\TH:i', strtotime($c['completed_at']))) ?>">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control mb-2" id="notes" name="notes" rows="3"><?= h($c['notes']) ?></textarea>
          <label class="form-label required" for="reason">Reason for the correction</label>
          <textarea class="form-control mb-3" id="reason" name="reason" rows="2" required
                    placeholder="e.g. Wrong machine selected; reading transposed"></textarea>
          <button class="btn btn-warning w-100" type="submit"
                  data-confirm="Record this correction? The original values stay in the revision history.">
            Record revision</button>
        </form>
      </div>
    </div>

    <?php if (setup_mode()): ?>
    <div class="card danger-zone mt-3">
      <div class="card-header"><i class="bi bi-trash"></i> Delete this record</div>
      <div class="card-body">
        <p class="small text-muted">
          Available because <strong>Setup mode</strong> is on. Deleting puts the task back on the
          schedule as due, and the deletion itself is written to the audit trail. Once you lock the
          record in Settings, sign-off records can only be corrected, never removed.
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-outline-danger w-100" type="submit"
                  data-confirm="Delete this sign-off record permanently? The task goes back on the schedule as due.">
            Delete this record
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="d-grid gap-2 mt-3">
      <a class="btn btn-outline-secondary"
         href="<?= h(url('modules/assets/view.php?id=' . $c['asset_id'] . '&tab=history')) ?>">
        Asset history</a>
    </div>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
