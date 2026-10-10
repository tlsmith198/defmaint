<?php
/** Create / edit one maintenance requirement and its checklist steps. Admin only. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$id  = req_int('id');
$req = $id ? db_one('SELECT * FROM maintenance_requirements WHERE id = :id', [':id' => $id]) : null;
if ($id && !$req) {
    flash('Requirement not found.', 'danger');
    redirect('modules/maintenance/requirements.php');
}

$assetId = $req ? (int) $req['asset_id'] : req_int('asset_id');
$asset   = $assetId ? asset_get($assetId) : null;
if (!$asset) {
    flash('Choose an asset first.', 'warning');
    redirect('modules/maintenance/requirements.php');
}

$isEdit = $req !== null;
$errors = [];

$form = $isEdit ? $req : [
    'task_name' => '', 'spec_note' => '', 'instructions' => '', 'frequency' => 'monthly',
    'interval_days' => 30, 'schedule_mode' => 'rolling', 'fixed_day_of_month' => '',
    'fixed_weekday' => '', 'grace_days' => setting_int('overdue_grace_default', 0),
    'tools_consumables' => '', 'est_minutes' => '', 'assigned_role' => '', 'assigned_user_id' => '',
    'is_safety_critical' => 0, 'sort_order' => 100, 'is_active' => 1, 'next_due_date' => '',
];

$steps = $isEdit
    ? db_all('SELECT * FROM requirement_steps WHERE requirement_id = :id AND is_active = 1 ORDER BY step_no, id',
        [':id' => $id])
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    $form = array_merge($form, pick($_POST, [
        'task_name', 'spec_note', 'instructions', 'frequency', 'interval_days', 'schedule_mode',
        'fixed_day_of_month', 'fixed_weekday', 'grace_days', 'tools_consumables', 'est_minutes',
        'assigned_role', 'assigned_user_id', 'sort_order', 'next_due_date',
    ]));
    foreach ($form as $k => $v) {
        if (is_string($v)) $form[$k] = trim($v);
    }
    $form['is_safety_critical'] = isset($_POST['is_safety_critical']) ? 1 : 0;
    $form['is_active']          = isset($_POST['is_active']) ? 1 : 0;

    // ---- validation ---------------------------------------------------
    if ($form['task_name'] === '') {
        $errors['task_name'] = 'Give the task a name.';
    }
    if (!array_key_exists($form['frequency'], frequencies())) {
        $errors['frequency'] = 'Choose a frequency.';
    }
    if ($form['frequency'] === 'custom') {
        if ((int) $form['interval_days'] < 1 || (int) $form['interval_days'] > 3650) {
            $errors['interval_days'] = 'Enter the number of days between 1 and 3650.';
        }
    } else {
        $form['interval_days'] = frequencies()[$form['frequency']]['days'];
    }
    if ($form['next_due_date'] !== '' && !strtotime((string) $form['next_due_date'])) {
        $errors['next_due_date'] = 'Enter a valid date.';
    }
    $dupe = db_value(
        'SELECT id FROM maintenance_requirements
          WHERE asset_id = :a AND frequency = :f AND task_name = :t AND id <> :id',
        [':a' => $assetId, ':f' => $form['frequency'], ':t' => $form['task_name'], ':id' => $id ?: 0]
    );
    if ($dupe) {
        $errors['task_name'] = 'This asset already has a ' . label_of($form['frequency'], frequencies())
                             . ' task with that exact name.';
    }

    // ---- steps ---------------------------------------------------------
    $postedSteps = [];
    foreach ((array) ($_POST['steps'] ?? []) as $row) {
        $text = trim((string) ($row['step_text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $type = in_array($row['response_type'] ?? '', ['done', 'passfail', 'value', 'text'], true)
            ? $row['response_type'] : 'passfail';
        $min = ($row['value_min'] ?? '') === '' ? null : (float) $row['value_min'];
        $max = ($row['value_max'] ?? '') === '' ? null : (float) $row['value_max'];
        if ($type === 'value' && $min !== null && $max !== null && $min > $max) {
            $errors['steps'] = 'A step has a minimum higher than its maximum.';
        }
        $postedSteps[] = [
            'id'          => (int) ($row['id'] ?? 0),
            'step_text'   => mb_substr($text, 0, 500),
            'response_type' => $type,
            'value_unit'  => nn(trim((string) ($row['value_unit'] ?? ''))),
            'value_min'   => $type === 'value' ? $min : null,
            'value_max'   => $type === 'value' ? $max : null,
            'is_required' => empty($row['optional']) ? 1 : 0,
        ];
    }

    if (!$errors) {
        $data = [
            'asset_id'          => $assetId,
            'task_name'         => $form['task_name'],
            'spec_note'         => nn($form['spec_note']),
            'instructions'      => nn($form['instructions']),
            'frequency'         => $form['frequency'],
            'interval_days'     => (int) $form['interval_days'],
            'schedule_mode'     => $form['schedule_mode'] === 'fixed_calendar' ? 'fixed_calendar' : 'rolling',
            'fixed_day_of_month' => nn($form['fixed_day_of_month']),
            'fixed_weekday'     => nn($form['fixed_weekday']),
            'grace_days'        => (int) $form['grace_days'],
            'tools_consumables' => nn($form['tools_consumables']),
            'est_minutes'       => nn($form['est_minutes']),
            'assigned_role'     => nn($form['assigned_role']),
            'assigned_user_id'  => nn($form['assigned_user_id']),
            'is_safety_critical' => $form['is_safety_critical'],
            'sort_order'        => (int) ($form['sort_order'] ?: 100),
            'is_active'         => $form['is_active'],
            'updated_by'        => current_user_id(),
        ];

        db()->beginTransaction();
        try {
            if ($isEdit) {
                db_update('maintenance_requirements', $data, $id);
                audit('requirement.update', 'requirement', $id, diff_fields($req, $data));
            } else {
                $data['created_by']   = current_user_id();
                $data['template_key'] = 'req_' . bin2hex(random_bytes(5));
                $id = db_insert('maintenance_requirements', $data);
                audit('requirement.create', 'requirement', $id,
                    ['asset_id' => $assetId, 'task' => $data['task_name']]);
            }

            // Steps: update in place, add new, deactivate removed ones so
            // historical step results keep pointing at something real.
            $keptIds = [];
            $no = 0;
            foreach ($postedSteps as $s) {
                $no++;
                $row = [
                    'requirement_id' => $id,
                    'step_no'        => $no,
                    'step_text'      => $s['step_text'],
                    'response_type'  => $s['response_type'],
                    'value_unit'     => $s['value_unit'],
                    'value_min'      => $s['value_min'],
                    'value_max'      => $s['value_max'],
                    'is_required'    => $s['is_required'],
                    'is_active'      => 1,
                ];
                if ($s['id'] && db_value('SELECT id FROM requirement_steps WHERE id = :i AND requirement_id = :r',
                        [':i' => $s['id'], ':r' => $id])) {
                    db_update('requirement_steps', $row, $s['id']);
                    $keptIds[] = $s['id'];
                } else {
                    $keptIds[] = db_insert('requirement_steps', $row);
                }
            }
            if ($keptIds) {
                $in = implode(',', array_map('intval', $keptIds));
                db_query("UPDATE requirement_steps SET is_active = 0
                           WHERE requirement_id = :r AND id NOT IN ($in)", [':r' => $id]);
            } else {
                db_query('UPDATE requirement_steps SET is_active = 0 WHERE requirement_id = :r', [':r' => $id]);
            }

            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        // ---- reschedule ---------------------------------------------
        if ($data['is_active']) {
            $open = due_open_instance($id);
            if ($form['next_due_date'] !== '') {
                $wanted = date('Y-m-d', strtotime((string) $form['next_due_date']));
                if ($open && $open['due_date'] !== $wanted) {
                    db_query('UPDATE maintenance_due_instances SET due_date = :d WHERE id = :id',
                        [':d' => $wanted, ':id' => $open['id']]);
                } elseif (!$open) {
                    db_query('UPDATE maintenance_requirements SET next_due_date = :d WHERE id = :id',
                        [':d' => $wanted, ':id' => $id]);
                }
            }
            due_engine_generate($id);
        } else {
            db_query("UPDATE maintenance_due_instances SET status = 'cancelled',
                             skip_reason = 'Requirement switched off', skipped_by = :u
                       WHERE requirement_id = :r AND status = 'scheduled'",
                [':u' => current_user_id(), ':r' => $id]);
        }

        flash('Saved <strong>' . h($data['task_name']) . '</strong>.');
        redirect('modules/maintenance/requirements.php?asset_id=' . $assetId);
    }

    // repopulate the step editor with what was submitted
    $steps = $postedSteps;
}

$openInstance = $isEdit ? due_open_instance($id) : null;
$pageTitle    = $isEdit ? 'Edit requirement' : 'New requirement';
$activeNav    = 'maintenance';
$breadcrumbs  = [
    ['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'],
    ['label' => 'Requirements', 'url' => 'modules/maintenance/requirements.php'],
    ['label' => $asset['asset_tag'], 'url' => 'modules/maintenance/requirements.php?asset_id=' . $assetId],
    ['label' => $isEdit ? 'Edit' : 'New'],
];
include APP_ROOT . '/templates/header.php';
$users = active_users();

function fe(array $errors, string $f): string { return isset($errors[$f]) ? ' is-invalid' : ''; }
function ft(array $errors, string $f): string {
    return isset($errors[$f]) ? '<div class="invalid-feedback">' . h($errors[$f]) . '</div>' : '';
}
?>
<?php if ($errors): ?><div class="alert alert-danger">Please correct the highlighted fields.</div><?php endif; ?>

<form method="post" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body">
          <div class="form-section-title mt-0">The task</div>
          <div class="mb-3">
            <label class="form-label required" for="task_name">Task name</label>
            <input class="form-control<?= fe($errors, 'task_name') ?>" id="task_name" name="task_name"
                   value="<?= h($form['task_name']) ?>" required
                   placeholder="e.g. Check way-lube pump oil level">
            <?= ft($errors, 'task_name') ?>
          </div>
          <div class="mb-3">
            <label class="form-label" for="spec_note">Spec / short note</label>
            <input class="form-control" id="spec_note" name="spec_note" value="<?= h($form['spec_note']) ?>"
                   placeholder="e.g. Shop std: FUCHS RENEP KN 68 (ISO VG 68)">
            <div class="form-text">Shown beside the task on the due list — keep it to one line.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="instructions">Full instructions</label>
            <textarea class="form-control" id="instructions" name="instructions" rows="5"
                      placeholder="Step-by-step detail, torque values, cautions…"><?= h($form['instructions']) ?></textarea>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="tools_consumables">Tools &amp; consumables</label>
              <input class="form-control" id="tools_consumables" name="tools_consumables"
                     value="<?= h($form['tools_consumables']) ?>" placeholder="Grease gun, LHL-X100-7 cartridge">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="est_minutes">Estimated minutes</label>
              <input class="form-control" type="number" min="1" max="2880" id="est_minutes"
                     name="est_minutes" value="<?= h($form['est_minutes']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="sort_order">Order</label>
              <input class="form-control" type="number" id="sort_order" name="sort_order"
                     value="<?= h($form['sort_order']) ?>">
              <div class="form-text">Lower shows first.</div>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Checklist steps <span class="text-muted small">optional</span></span>
          <button class="btn btn-sm btn-outline-primary" type="button" id="addStep">
            <i class="bi bi-plus-lg"></i> Add step</button>
        </div>
        <div class="card-body">
          <p class="small text-muted">
            Leave this empty for a simple done / not-done task. Add steps when the tech should record
            individual results — a <strong>measured value</strong> step with min and max turns a reading
            (coolant concentration, pH, air pressure) into data the reports can trend.
          </p>
          <?= ft($errors, 'steps') ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle" id="stepTable">
              <thead><tr>
                <th style="width:2rem">#</th><th>Step</th><th style="width:10rem">Response</th>
                <th style="width:6rem">Unit</th><th style="width:6rem">Min</th><th style="width:6rem">Max</th>
                <th style="width:5rem">Optional</th><th style="width:3rem"></th>
              </tr></thead>
              <tbody>
              <?php foreach ($steps as $i => $s): ?>
                <tr>
                  <td class="text-muted step-no"><?= $i + 1 ?></td>
                  <td>
                    <input type="hidden" name="steps[<?= $i ?>][id]" value="<?= (int) ($s['id'] ?? 0) ?>">
                    <input class="form-control form-control-sm" name="steps[<?= $i ?>][step_text]"
                           value="<?= h($s['step_text']) ?>">
                  </td>
                  <td>
                    <select class="form-select form-select-sm" name="steps[<?= $i ?>][response_type]">
                      <?php foreach (['passfail' => 'Pass / Fail', 'done' => 'Done', 'value' => 'Measured value',
                                      'text' => 'Free text'] as $k => $lbl): ?>
                        <option value="<?= h($k) ?>" <?= ($s['response_type'] ?? '') === $k ? 'selected' : '' ?>>
                          <?= h($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input class="form-control form-control-sm" name="steps[<?= $i ?>][value_unit]"
                             value="<?= h($s['value_unit'] ?? '') ?>" placeholder="%"></td>
                  <td><input class="form-control form-control-sm" type="number" step="any"
                             name="steps[<?= $i ?>][value_min]" value="<?= h($s['value_min'] ?? '') ?>"></td>
                  <td><input class="form-control form-control-sm" type="number" step="any"
                             name="steps[<?= $i ?>][value_max]" value="<?= h($s['value_max'] ?? '') ?>"></td>
                  <td class="text-center">
                    <input class="form-check-input" type="checkbox" name="steps[<?= $i ?>][optional]" value="1"
                           <?= empty($s['is_required']) ? 'checked' : '' ?>>
                  </td>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger removeStep"><i class="bi bi-x"></i></button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-header">Schedule</div>
        <div class="card-body">
          <label class="form-label required" for="frequency">Frequency</label>
          <select class="form-select<?= fe($errors, 'frequency') ?>" id="frequency" name="frequency">
            <?php foreach (frequencies() as $k => $m): ?>
              <option value="<?= h($k) ?>" <?= $form['frequency'] === $k ? 'selected' : '' ?>
                data-days="<?= (int) $m['days'] ?>"><?= h($m['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= ft($errors, 'frequency') ?>

          <div class="mt-3" id="customDays" style="<?= $form['frequency'] === 'custom' ? '' : 'display:none' ?>">
            <label class="form-label" for="interval_days">Every … days</label>
            <input class="form-control<?= fe($errors, 'interval_days') ?>" type="number" min="1" max="3650"
                   id="interval_days" name="interval_days" value="<?= h($form['interval_days']) ?>">
            <?= ft($errors, 'interval_days') ?>
          </div>

          <label class="form-label mt-3" for="schedule_mode">Scheduling mode</label>
          <select class="form-select" id="schedule_mode" name="schedule_mode">
            <option value="rolling" <?= $form['schedule_mode'] === 'rolling' ? 'selected' : '' ?>>
              Rolling — next due counts from the last sign-off</option>
            <option value="fixed_calendar" <?= $form['schedule_mode'] === 'fixed_calendar' ? 'selected' : '' ?>>
              Fixed calendar — always the same day</option>
          </select>
          <div class="form-text">
            Rolling suits condition checks. Fixed calendar suits anything that must land on a set date.
          </div>

          <div class="row g-2 mt-1" id="fixedOpts" style="<?= $form['schedule_mode'] === 'fixed_calendar' ? '' : 'display:none' ?>">
            <div class="col-6">
              <label class="form-label" for="fixed_weekday">Weekday</label>
              <select class="form-select" id="fixed_weekday" name="fixed_weekday">
                <option value="">—</option>
                <?php foreach (['1'=>'Monday','2'=>'Tuesday','3'=>'Wednesday','4'=>'Thursday',
                                '5'=>'Friday','6'=>'Saturday','7'=>'Sunday'] as $k => $lbl): ?>
                  <option value="<?= $k ?>" <?= (string) $form['fixed_weekday'] === $k ? 'selected' : '' ?>>
                    <?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="fixed_day_of_month">Day of month</label>
              <input class="form-control" type="number" min="1" max="28" id="fixed_day_of_month"
                     name="fixed_day_of_month" value="<?= h($form['fixed_day_of_month']) ?>">
              <div class="form-text">1–28.</div>
            </div>
          </div>

          <div class="row g-2 mt-1">
            <div class="col-6">
              <label class="form-label" for="grace_days">Grace days</label>
              <input class="form-control" type="number" min="0" max="60" id="grace_days"
                     name="grace_days" value="<?= h($form['grace_days']) ?>">
              <div class="form-text">Before it counts overdue.</div>
            </div>
            <div class="col-6">
              <label class="form-label" for="next_due_date">Next due</label>
              <input class="form-control<?= fe($errors, 'next_due_date') ?>" type="date" id="next_due_date"
                     name="next_due_date"
                     value="<?= h($openInstance['due_date'] ?? $form['next_due_date'] ?? '') ?>">
              <?= ft($errors, 'next_due_date') ?>
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">Responsibility</div>
        <div class="card-body">
          <label class="form-label" for="assigned_role">Role</label>
          <input class="form-control" id="assigned_role" name="assigned_role"
                 value="<?= h($form['assigned_role']) ?>" list="roleList" placeholder="Operator">
          <datalist id="roleList">
            <option value="Operator"><option value="Maintenance Tech">
            <option value="Lead"><option value="Outside Service">
          </datalist>

          <label class="form-label mt-3" for="assigned_user_id">Specific person</label>
          <select class="form-select" id="assigned_user_id" name="assigned_user_id">
            <option value="">— anyone —</option>
            <?php foreach ($users as $u2): ?>
              <option value="<?= (int) $u2['id'] ?>"
                <?= (string) $form['assigned_user_id'] === (string) $u2['id'] ? 'selected' : '' ?>>
                <?= h($u2['full_name']) ?></option>
            <?php endforeach; ?>
          </select>

          <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="is_safety_critical" name="is_safety_critical"
                   value="1" <?= $form['is_safety_critical'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_safety_critical">Safety critical</label>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                   <?= $form['is_active'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_active">Active — include in the schedule</label>
          </div>
        </div>
      </div>

      <div class="d-grid gap-2">
        <button class="btn btn-primary btn-lg" type="submit">
          <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save requirement' : 'Create requirement' ?></button>
        <a class="btn btn-outline-secondary"
           href="<?= h(url('modules/maintenance/requirements.php?asset_id=' . $assetId)) ?>">Cancel</a>
      </div>
    </div>
  </div>
</form>

<template id="stepTemplate">
  <tr>
    <td class="text-muted step-no"></td>
    <td>
      <input type="hidden" name="steps[IDX][id]" value="0">
      <input class="form-control form-control-sm" name="steps[IDX][step_text]">
    </td>
    <td>
      <select class="form-select form-select-sm" name="steps[IDX][response_type]">
        <option value="passfail">Pass / Fail</option>
        <option value="done">Done</option>
        <option value="value">Measured value</option>
        <option value="text">Free text</option>
      </select>
    </td>
    <td><input class="form-control form-control-sm" name="steps[IDX][value_unit]" placeholder="%"></td>
    <td><input class="form-control form-control-sm" type="number" step="any" name="steps[IDX][value_min]"></td>
    <td><input class="form-control form-control-sm" type="number" step="any" name="steps[IDX][value_max]"></td>
    <td class="text-center"><input class="form-check-input" type="checkbox" name="steps[IDX][optional]" value="1"></td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger removeStep"><i class="bi bi-x"></i></button></td>
  </tr>
</template>

<script>
(function () {
  var body = document.querySelector('#stepTable tbody');
  var tpl  = document.getElementById('stepTemplate');
  var next = <?= count($steps) ?>;

  function renumber() {
    body.querySelectorAll('tr').forEach(function (tr, i) {
      var cell = tr.querySelector('.step-no');
      if (cell) cell.textContent = i + 1;
    });
  }
  document.getElementById('addStep').addEventListener('click', function () {
    var html = tpl.innerHTML.replace(/IDX/g, next++);
    var tmp  = document.createElement('tbody');
    tmp.innerHTML = html.trim();
    body.appendChild(tmp.firstElementChild);
    renumber();
  });
  body.addEventListener('click', function (e) {
    var btn = e.target.closest('.removeStep');
    if (!btn) return;
    btn.closest('tr').remove();
    renumber();
  });

  var freq = document.getElementById('frequency');
  var mode = document.getElementById('schedule_mode');
  function sync() {
    document.getElementById('customDays').style.display = freq.value === 'custom' ? '' : 'none';
    document.getElementById('fixedOpts').style.display  = mode.value === 'fixed_calendar' ? '' : 'none';
  }
  freq.addEventListener('change', sync);
  mode.addEventListener('change', sync);
  sync();
})();
</script>
<?php include APP_ROOT . '/templates/footer.php'; ?>
