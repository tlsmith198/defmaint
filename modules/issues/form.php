<?php
/**
 * Log an issue against an asset.
 * Pre-fills from a failed PM check when reached from the completion flow.
 * Notification email is sent by includes/notify.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

if (!can('log_issue')) {
    http_response_code(403);
    include APP_ROOT . '/templates/403.php';
    exit;
}

$assetId       = req_int('asset_id');
$requirementId = req_int('requirement_id');
$completionId  = req_int('completion_id');
$dueId         = req_int('due_instance_id');

$requirement = $requirementId
    ? db_one('SELECT * FROM maintenance_requirements WHERE id = :id', [':id' => $requirementId])
    : null;
$completion = $completionId
    ? db_one('SELECT * FROM maintenance_completions WHERE id = :id', [':id' => $completionId])
    : null;
if ($completion && !$assetId) {
    $assetId = (int) $completion['asset_id'];
}

$errors = [];
$form = [
    'asset_id'    => $assetId,
    'title'       => $requirement ? $requirement['task_name'] . ' — check failed' : '',
    'description' => $completion['notes'] ?? '',
    'severity'    => 'minor',
    'assigned_to' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $form = array_merge($form, pick($_POST, ['asset_id', 'title', 'description', 'severity', 'assigned_to']));
    foreach ($form as $k => $v) {
        if (is_string($v)) $form[$k] = trim($v);
    }

    if (!$form['asset_id'] || !asset_get((int) $form['asset_id'])) {
        $errors['asset_id'] = 'Choose the machine this affects.';
    }
    if ($form['title'] === '') {
        $errors['title'] = 'Give the issue a short title.';
    }
    if (!array_key_exists($form['severity'], severities())) {
        $errors['severity'] = 'Choose a severity.';
    }

    $photoRel = null;
    if (!empty($_FILES['photo']['name'])) {
        [$photoRel, $upErr] = store_upload($_FILES['photo'], 'issues', UPLOAD_IMAGE_TYPES);
        if ($upErr) {
            $errors['photo'] = $upErr;
        }
    }

    if (!$errors) {
        $issueId = issue_create([
            'asset_id'        => (int) $form['asset_id'],
            'requirement_id'  => $requirementId ?: null,
            'completion_id'   => $completionId ?: null,
            'due_instance_id' => $dueId ?: null,
            'title'           => $form['title'],
            'description'     => $form['description'],
            'severity'        => $form['severity'],
            'assigned_to'     => nn($form['assigned_to']),
            'source_note'     => $requirement
                ? 'Raised from PM check "' . $requirement['task_name'] . '".'
                : null,
        ]);
        if ($photoRel) {
            attach('issue', $issueId, $photoRel, $_FILES['photo'], 'photo');
        }
        if ($completionId) {
            db_query('UPDATE maintenance_completions SET issue_id = :i WHERE id = :c',
                [':i' => $issueId, ':c' => $completionId]);
        }
        flash('Issue logged.'
            . ($form['severity'] === 'down' ? ' The machine has been marked Down for Repair.' : ''),
            $form['severity'] === 'minor' ? 'success' : 'warning');
        redirect('modules/issues/view.php?id=' . $issueId);
    }
}

$pageTitle   = 'Log an issue';
$activeNav   = 'issues';
$breadcrumbs = [['label' => 'Issues', 'url' => 'modules/issues/index.php'], ['label' => 'New']];
include APP_ROOT . '/templates/header.php';
?>
<?php if ($errors): ?><div class="alert alert-danger">Check the highlighted fields.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="requirement_id" value="<?= (int) $requirementId ?>">
  <input type="hidden" name="completion_id" value="<?= (int) $completionId ?>">
  <input type="hidden" name="due_instance_id" value="<?= (int) $dueId ?>">

  <div class="row g-3"><div class="col-lg-7">
    <div class="card">
      <div class="card-body">
        <?php if ($requirement): ?>
          <div class="alert alert-warning py-2 small">
            Raised from the PM check <strong><?= h($requirement['task_name']) ?></strong>.
            The issue stays linked to that completion record.
          </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label required" for="asset_id">Machine</label>
          <select class="form-select<?= isset($errors['asset_id']) ? ' is-invalid' : '' ?>"
                  id="asset_id" name="asset_id" required>
            <option value="">— choose —</option>
            <?php foreach (asset_options() as $a): ?>
              <option value="<?= (int) $a['id'] ?>"
                <?= (string) $form['asset_id'] === (string) $a['id'] ? 'selected' : '' ?>>
                <?= h($a['asset_tag']) ?> — <?= h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['asset_id'])): ?>
            <div class="invalid-feedback"><?= h($errors['asset_id']) ?></div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label required" for="title">What is wrong</label>
          <input class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" id="title"
                 name="title" value="<?= h($form['title']) ?>" required
                 placeholder="e.g. Way-lube pump not building pressure">
          <?php if (isset($errors['title'])): ?>
            <div class="invalid-feedback"><?= h($errors['title']) ?></div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label" for="description">Detail</label>
          <textarea class="form-control" id="description" name="description" rows="5"
                    placeholder="What you saw, what you already tried, anything a tech should know"><?= h($form['description']) ?></textarea>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="photo">Photo</label>
            <input class="form-control<?= isset($errors['photo']) ? ' is-invalid' : '' ?>" type="file"
                   id="photo" name="photo" accept="image/*" capture="environment">
            <?php if (isset($errors['photo'])): ?>
              <div class="invalid-feedback"><?= h($errors['photo']) ?></div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="assigned_to">Assign to</label>
            <select class="form-select" id="assigned_to" name="assigned_to">
              <option value="">— unassigned —</option>
              <?php foreach (active_users() as $u2): ?>
                <option value="<?= (int) $u2['id'] ?>"
                  <?= (string) $form['assigned_to'] === (string) $u2['id'] ? 'selected' : '' ?>>
                  <?= h($u2['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Severity</div>
      <div class="card-body">
        <?php
        $help = [
            'minor' => 'Keep running. Fix at the next convenient opportunity.',
            'major' => 'Still running, but it affects quality, safety or throughput.',
            'down'  => 'The machine cannot run. This also sets the asset to Down for Repair immediately.',
        ];
        foreach (severities() as $k => $m): ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="severity" id="sev<?= h($k) ?>"
                   value="<?= h($k) ?>" <?= $form['severity'] === $k ? 'checked' : '' ?>>
            <label class="form-check-label" for="sev<?= h($k) ?>">
              <?= badge($k, severities()) ?>
              <div class="small text-muted"><?= h($help[$k]) ?></div>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="alert alert-info small">
      The asset owner is emailed automatically, and Major / Machine-Down reports are escalated to the
      administrators. Every issue is recorded and audited.
    </div>

    <div class="d-grid gap-2">
      <button class="btn btn-danger big-submit" type="submit">
        <i class="bi bi-exclamation-triangle"></i> Log the issue</button>
      <a class="btn btn-outline-secondary" href="<?= h(url('modules/issues/index.php')) ?>">Cancel</a>
    </div>
  </div></div>
</form>
<?php include APP_ROOT . '/templates/footer.php'; ?>
