<?php
/**
 * Log a maintenance completion — built for speed on a shop-floor tablet.
 * Reached from the due list (?due_id=) or ad hoc against a requirement (?requirement_id=).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if (!can('log_completion')) {
    http_response_code(403);
    include APP_ROOT . '/templates/403.php';
    exit;
}

$dueId = req_int('due_id');
$reqId = req_int('requirement_id');

$due = $dueId
    ? db_one("SELECT * FROM maintenance_due_instances WHERE id = :id", [':id' => $dueId])
    : null;
if ($due && $due['status'] !== 'scheduled') {
    flash('That task has already been closed out.', 'info');
    redirect('modules/maintenance/index.php');
}
if ($due) {
    $reqId = (int) $due['requirement_id'];
}

$req = $reqId ? db_one(
    'SELECT r.*, a.name AS asset_name, a.asset_tag, a.id AS asset_id, a.hour_meter,
            u.full_name AS assignee_name
       FROM maintenance_requirements r
       JOIN assets a ON a.id = r.asset_id
       LEFT JOIN users u ON u.id = r.assigned_user_id
      WHERE r.id = :id',
    [':id' => $reqId]
) : null;

if (!$req) {
    flash('That maintenance task was not found.', 'danger');
    redirect('modules/maintenance/index.php');
}

$steps = db_all(
    'SELECT * FROM requirement_steps WHERE requirement_id = :r AND is_active = 1 ORDER BY step_no, id',
    [':r' => $reqId]
);

$errors = [];
$post   = [
    'completed_at' => date('Y-m-d\TH:i'),
    'outcome'      => 'complete',
    'notes'        => '',
    'duration'     => $req['est_minutes'],
    'hour_meter'   => '',
    'raise_issue'  => 0,
    'issue_severity' => 'minor',
    'issue_title'  => '',
    'issue_description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $post = array_merge($post, pick($_POST, [
        'completed_at', 'outcome', 'notes', 'duration', 'hour_meter',
        'issue_severity', 'issue_title', 'issue_description',
    ]));
    $post['raise_issue'] = isset($_POST['raise_issue']) ? 1 : 0;

    $completedAt = $post['completed_at'] ? strtotime((string) $post['completed_at']) : time();
    if (!$completedAt) {
        $errors['completed_at'] = 'Enter a valid date and time.';
    } elseif ($completedAt > time() + 3600) {
        $errors['completed_at'] = 'The completion time cannot be in the future.';
    }

    // ---- step results -------------------------------------------------
    $stepResults = [];
    $anyFail = false;
    foreach ($steps as $s) {
        $in    = (array) ($_POST['step'][$s['id']] ?? []);
        $res   = (string) ($in['result'] ?? '');
        $value = ($in['value'] ?? '') === '' ? null : (float) $in['value'];
        $note  = trim((string) ($in['note'] ?? ''));
        $inRange = null;

        if ($s['response_type'] === 'value') {
            if ($value === null && $s['is_required'] && $post['outcome'] === 'complete') {
                $errors['step_' . $s['id']] = 'Enter the reading.';
            }
            if ($value !== null) {
                $lowOk  = $s['value_min'] === null || $value >= (float) $s['value_min'];
                $highOk = $s['value_max'] === null || $value <= (float) $s['value_max'];
                $inRange = ($lowOk && $highOk) ? 1 : 0;
                $res = $inRange ? 'pass' : 'fail';
            } else {
                $res = 'na';
            }
        } elseif ($s['response_type'] === 'text') {
            $res = $note !== '' ? 'done' : 'na';
        } else {
            if ($res === '' && $s['is_required'] && $post['outcome'] === 'complete') {
                $errors['step_' . $s['id']] = 'Record a result.';
            }
            if ($res === '') {
                $res = 'na';
            }
        }
        if (in_array($res, ['fail', 'not_done'], true)) {
            $anyFail = true;
        }

        $stepResults[] = [
            'requirement_step_id' => (int) $s['id'],
            'step_no'             => (int) $s['step_no'],
            'step_text_snapshot'  => $s['step_text'],
            'result'              => $res,
            'measured_value'      => $value,
            'value_unit'          => $s['value_unit'],
            'in_range'            => $inRange,
            'note'                => nn($note),
        ];
    }

    // ---- overall result ------------------------------------------------
    if ($post['outcome'] === 'not_done') {
        $result = 'not_done';
        if (trim((string) $post['notes']) === '') {
            $errors['notes'] = 'Say why it could not be completed.';
        }
    } elseif ($anyFail) {
        $result = 'fail';
    } else {
        $result = $steps ? 'pass' : 'done';
    }

    if ($post['raise_issue'] && trim((string) $post['issue_title']) === '') {
        $post['issue_title'] = $req['task_name'] . ' — ' . ($result === 'not_done' ? 'not completed' : 'check failed');
    }

    // ---- photo ----------------------------------------------------------
    $photoRel = null;
    if (!empty($_FILES['photo']['name'])) {
        [$photoRel, $upErr] = store_upload($_FILES['photo'], 'completions', UPLOAD_IMAGE_TYPES);
        if ($upErr) {
            $errors['photo'] = $upErr;
        }
    }

    if (!$errors) {
        $completedAtSql = date('Y-m-d H:i:s', $completedAt);

        db()->beginTransaction();
        try {
            $completionId = db_insert('maintenance_completions', [
                'due_instance_id'    => $dueId ?: null,
                'requirement_id'     => (int) $req['id'],
                'asset_id'           => (int) $req['asset_id'],
                'task_name_snapshot' => $req['task_name'],
                'frequency_snapshot' => $req['frequency'],
                'due_date_snapshot'  => $due['due_date'] ?? null,
                'result'             => $result,
                'completed_by'       => current_user_id(),
                'completed_at'       => $completedAtSql,
                'duration_minutes'   => nn($post['duration']),
                'hour_meter'         => nn($post['hour_meter']),
                'notes'              => nn(trim((string) $post['notes'])),
                'submitted_ip'       => client_ip(),
            ]);

            foreach ($stepResults as $sr) {
                db_insert('completion_step_results', ['completion_id' => $completionId] + $sr);
            }

            if ($photoRel) {
                attach('completion', $completionId, $photoRel, $_FILES['photo'], 'photo');
            }

            if ($post['hour_meter'] !== '' && $post['hour_meter'] !== null) {
                db_query('UPDATE assets SET hour_meter = :h, hour_meter_at = :t WHERE id = :id', [
                    ':h' => (float) $post['hour_meter'], ':t' => $completedAtSql, ':id' => $req['asset_id'],
                ]);
            }

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        due_engine_after_completion((int) $req['id'], $completedAtSql, $dueId ?: null, $completionId);
        audit('completion.create', 'completion', $completionId, [
            'requirement_id' => $req['id'], 'asset_id' => $req['asset_id'], 'result' => $result,
        ]);

        // ---- escalate a failure straight into the issue log --------------
        $issueId = null;
        if ($post['raise_issue']) {
            $issueId = issue_create([
                'asset_id'        => (int) $req['asset_id'],
                'requirement_id'  => (int) $req['id'],
                'due_instance_id' => $dueId ?: null,
                'completion_id'   => $completionId,
                'title'           => (string) $post['issue_title'],
                'description'     => (string) $post['issue_description'] ?: (string) $post['notes'],
                'severity'        => (string) $post['issue_severity'],
                'source_note'     => 'Raised from PM check "' . $req['task_name'] . '" on '
                                   . $req['asset_tag'] . ' by ' . current_user_name() . '.',
            ]);
            db_query('UPDATE maintenance_completions SET issue_id = :i WHERE id = :c',
                [':i' => $issueId, ':c' => $completionId]);
        }

        $msg = 'Logged <strong>' . h($req['task_name']) . '</strong> on ' . h($req['asset_name']) . '.';
        if ($issueId) {
            $msg .= ' Issue <a class="alert-link" href="' . h(url('modules/issues/view.php?id=' . $issueId))
                  . '">raised</a>.';
        }
        flash($msg, $result === 'fail' || $result === 'not_done' ? 'warning' : 'success');

        redirect(req('return') === 'asset'
            ? 'modules/assets/view.php?id=' . $req['asset_id'] . '&tab=history'
            : 'modules/maintenance/index.php');
    }
}

$pageTitle   = $req['task_name'];
$activeNav   = 'maintenance';
$breadcrumbs = [
    ['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'],
    ['label' => $req['asset_tag'], 'url' => 'modules/assets/view.php?id=' . $req['asset_id']],
    ['label' => 'Complete'],
];
include APP_ROOT . '/templates/header.php';

$late = $due ? (int) ((strtotime(date('Y-m-d')) - strtotime($due['due_date'])) / 86400) : null;
?>
<?php if ($errors): ?><div class="alert alert-danger">Check the highlighted fields.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" data-guard id="completeForm">
  <?= csrf_field() ?>
  <input type="hidden" name="due_id" value="<?= (int) $dueId ?>">
  <input type="hidden" name="requirement_id" value="<?= (int) $req['id'] ?>">
  <input type="hidden" name="return" value="<?= h(req('return', '')) ?>">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
            <span class="badge text-bg-light border"><?= h(label_of($req['frequency'], frequencies())) ?></span>
            <?php if ($req['is_safety_critical']): ?>
              <span class="badge text-bg-danger"><i class="bi bi-shield-exclamation"></i> Safety critical</span>
            <?php endif; ?>
            <span class="text-muted small">
              <?= h($req['asset_name']) ?> · <?= h($req['asset_tag']) ?>
            </span>
            <?php if ($due): ?>
              <span class="small <?= $late > 0 ? 'due-overdue' : 'due-soon' ?>">
                Due <?= h(fmt_date($due['due_date'])) ?><?= $late > 0 ? ' · ' . $late . ' day' . ($late === 1 ? '' : 's') . ' late' : '' ?>
              </span>
            <?php else: ?>
              <span class="badge text-bg-secondary">Unscheduled entry</span>
            <?php endif; ?>
          </div>

          <?php if ($req['spec_note']): ?>
            <p class="mb-2"><strong>Spec:</strong> <?= h($req['spec_note']) ?></p>
          <?php endif; ?>
          <?php if ($req['tools_consumables']): ?>
            <p class="mb-2 small text-muted"><i class="bi bi-tools"></i> <?= h($req['tools_consumables']) ?></p>
          <?php endif; ?>
          <?php if ($req['instructions']): ?>
            <details class="mb-0" <?= strlen((string) $req['instructions']) < 240 ? 'open' : '' ?>>
              <summary class="small text-primary">Instructions</summary>
              <div class="small mt-2" style="white-space:pre-wrap"><?= h($req['instructions']) ?></div>
            </details>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($steps): ?>
      <div class="card mb-3">
        <div class="card-header">Checks</div>
        <div class="card-body">
          <?php foreach ($steps as $s):
            $prev = (array) ($_POST['step'][$s['id']] ?? []);
            $err  = $errors['step_' . $s['id']] ?? null; ?>
            <div class="step-card <?= $err ? 'is-fail' : '' ?>">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div class="fw-semibold"><?= (int) $s['step_no'] ?>. <?= h($s['step_text']) ?>
                  <?php if (!$s['is_required']): ?>
                    <span class="badge text-bg-light border">optional</span>
                  <?php endif; ?>
                </div>

                <?php if ($s['response_type'] === 'value'): ?>
                  <div class="d-flex align-items-center gap-2">
                    <input class="form-control" style="max-width:8rem" type="number" step="any"
                           name="step[<?= (int) $s['id'] ?>][value]"
                           value="<?= h($prev['value'] ?? '') ?>"
                           data-min="<?= h($s['value_min']) ?>" data-max="<?= h($s['value_max']) ?>"
                           data-range-check>
                    <span class="text-muted small"><?= h($s['value_unit']) ?></span>
                  </div>
                <?php elseif ($s['response_type'] === 'text'): ?>
                  <input class="form-control" style="max-width:20rem" name="step[<?= (int) $s['id'] ?>][note]"
                         value="<?= h($prev['note'] ?? '') ?>" placeholder="Observation">
                <?php else: ?>
                  <div class="btn-group result-toggle" role="group">
                    <?php
                    $opts = $s['response_type'] === 'done'
                        ? ['done' => ['Done', 'success'], 'not_done' => ['Not done', 'danger'], 'na' => ['N/A', 'secondary']]
                        : ['pass' => ['Pass', 'success'], 'fail' => ['Fail', 'danger'], 'na' => ['N/A', 'secondary']];
                    foreach ($opts as $val => [$lbl, $cls]):
                      $id = 'st' . $s['id'] . $val; ?>
                      <input type="radio" class="btn-check" name="step[<?= (int) $s['id'] ?>][result]"
                             id="<?= $id ?>" value="<?= $val ?>"
                             <?= (($prev['result'] ?? '') === $val) ? 'checked' : '' ?>>
                      <label class="btn btn-outline-<?= $cls ?>" for="<?= $id ?>"><?= $lbl ?></label>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($s['response_type'] === 'value' && ($s['value_min'] !== null || $s['value_max'] !== null)): ?>
                <div class="small text-muted mt-1">
                  Acceptable:
                  <?= $s['value_min'] !== null ? h(rtrim(rtrim($s['value_min'], '0'), '.')) : '—' ?>
                  to
                  <?= $s['value_max'] !== null ? h(rtrim(rtrim($s['value_max'], '0'), '.')) : '—' ?>
                  <?= h($s['value_unit']) ?>
                  <span class="range-flag ms-2"></span>
                </div>
              <?php endif; ?>

              <?php if ($s['response_type'] !== 'text'): ?>
                <input class="form-control form-control-sm mt-2" name="step[<?= (int) $s['id'] ?>][note]"
                       value="<?= h($prev['note'] ?? '') ?>" placeholder="Note (optional)">
              <?php endif; ?>
              <?php if ($err): ?><div class="small text-danger mt-1"><?= h($err) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-body">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control<?= isset($errors['notes']) ? ' is-invalid' : '' ?>" id="notes"
                    name="notes" rows="3"
                    placeholder="Anything worth recording — readings, parts used, what you saw"><?= h($post['notes']) ?></textarea>
          <?php if (isset($errors['notes'])): ?>
            <div class="invalid-feedback"><?= h($errors['notes']) ?></div>
          <?php endif; ?>

          <div class="row g-3 mt-1">
            <div class="col-md-6">
              <label class="form-label" for="photo">Photo</label>
              <input class="form-control<?= isset($errors['photo']) ? ' is-invalid' : '' ?>" type="file"
                     id="photo" name="photo" accept="image/*" capture="environment">
              <?php if (isset($errors['photo'])): ?>
                <div class="invalid-feedback"><?= h($errors['photo']) ?></div>
              <?php endif; ?>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="duration">Minutes taken</label>
              <input class="form-control" type="number" min="1" max="2880" id="duration" name="duration"
                     value="<?= h($post['duration']) ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label" for="hour_meter">Hour meter</label>
              <input class="form-control" type="number" step="0.1" id="hour_meter" name="hour_meter"
                     value="<?= h($post['hour_meter']) ?>" placeholder="<?= h($req['hour_meter']) ?>">
            </div>
          </div>
        </div>
      </div>
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
            <input class="form-control<?= isset($errors['completed_at']) ? ' is-invalid' : '' ?>"
                   type="datetime-local" id="completed_at" name="completed_at"
                   value="<?= h($post['completed_at']) ?>">
            <?php if (isset($errors['completed_at'])): ?>
              <div class="invalid-feedback"><?= h($errors['completed_at']) ?></div>
            <?php endif; ?>
          </div>

          <label class="form-label">Outcome</label>
          <div class="btn-group w-100 result-toggle mb-2" role="group">
            <input type="radio" class="btn-check" name="outcome" id="oc1" value="complete"
                   <?= $post['outcome'] === 'complete' ? 'checked' : '' ?>>
            <label class="btn btn-outline-success" for="oc1">Completed</label>
            <input type="radio" class="btn-check" name="outcome" id="oc2" value="not_done"
                   <?= $post['outcome'] === 'not_done' ? 'checked' : '' ?>>
            <label class="btn btn-outline-danger" for="oc2">Could not complete</label>
          </div>
          <div class="form-text mb-0">
            A failed check or "could not complete" is recorded as-is — the record is never overwritten.
          </div>
        </div>
      </div>

      <div class="card mb-3 border-warning" id="issueBox">
        <div class="card-header text-warning-emphasis">
          <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" id="raise_issue" name="raise_issue" value="1"
                   <?= $post['raise_issue'] ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="raise_issue">Raise an issue</label>
          </div>
        </div>
        <div class="card-body" id="issueFields" style="<?= $post['raise_issue'] ? '' : 'display:none' ?>">
          <label class="form-label" for="issue_severity">Severity</label>
          <select class="form-select mb-2" id="issue_severity" name="issue_severity">
            <?php foreach (severities() as $k => $m): ?>
              <option value="<?= h($k) ?>" <?= $post['issue_severity'] === $k ? 'selected' : '' ?>>
                <?= h($m['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <label class="form-label" for="issue_title">Title</label>
          <input class="form-control mb-2" id="issue_title" name="issue_title"
                 value="<?= h($post['issue_title']) ?>"
                 placeholder="<?= h($req['task_name']) ?> — check failed">
          <label class="form-label" for="issue_description">What is wrong</label>
          <textarea class="form-control" id="issue_description" name="issue_description" rows="3"
                    placeholder="Leave blank to reuse the notes above"><?= h($post['issue_description']) ?></textarea>
          <div class="form-text">
            Machine Down also moves the asset to <em>Down for Repair</em> straight away.
          </div>
        </div>
      </div>

      <div class="d-grid gap-2">
        <button class="btn btn-success big-submit" type="submit">
          <i class="bi bi-check-lg"></i> Log completion</button>
        <a class="btn btn-outline-secondary" href="<?= h(url('modules/maintenance/index.php')) ?>">Cancel</a>
      </div>
    </div>
  </div>
</form>

<script>
(function () {
  // Reveal the issue block automatically when something fails.
  var raise  = document.getElementById('raise_issue');
  var fields = document.getElementById('issueFields');
  function syncIssue() { fields.style.display = raise.checked ? '' : 'none'; }
  raise.addEventListener('change', syncIssue);

  document.querySelectorAll('input[type=radio][value=fail], input[type=radio][value=not_done]')
    .forEach(function (r) {
      r.addEventListener('change', function () {
        if (r.checked && !raise.checked) { raise.checked = true; syncIssue(); }
        var card = r.closest('.step-card');
        if (card) { card.classList.add('is-fail'); card.classList.remove('is-pass'); }
      });
    });
  document.querySelectorAll('input[type=radio][value=pass], input[type=radio][value=done]')
    .forEach(function (r) {
      r.addEventListener('change', function () {
        var card = r.closest('.step-card');
        if (card) { card.classList.remove('is-fail'); card.classList.add('is-pass'); }
      });
    });

  // Live in-range feedback on measured values.
  document.querySelectorAll('[data-range-check]').forEach(function (input) {
    input.addEventListener('input', function () {
      var card = input.closest('.step-card');
      var flag = card ? card.querySelector('.range-flag') : null;
      var v = parseFloat(input.value);
      if (isNaN(v)) { if (flag) flag.textContent = ''; card.classList.remove('is-fail', 'is-pass'); return; }
      var min = input.dataset.min === '' ? null : parseFloat(input.dataset.min);
      var max = input.dataset.max === '' ? null : parseFloat(input.dataset.max);
      var ok = (min === null || v >= min) && (max === null || v <= max);
      if (flag) {
        flag.textContent = ok ? 'in range' : 'OUT OF RANGE';
        flag.className = 'range-flag ms-2 ' + (ok ? 'text-success' : 'out-of-range');
      }
      card.classList.toggle('is-fail', !ok);
      card.classList.toggle('is-pass', ok);
      if (!ok && !raise.checked) { raise.checked = true; syncIssue(); }
    });
  });
})();
</script>
<?php include APP_ROOT . '/templates/footer.php'; ?>
