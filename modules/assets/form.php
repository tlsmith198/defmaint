<?php
/** Create / edit an asset. Admin only. Handles both GET (render) and POST (save). */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$id     = req_int('id');
$asset  = $id ? asset_get($id) : null;
$isEdit = $asset !== null;

if ($id && !$asset) {
    http_response_code(404);
    flash('That asset no longer exists.', 'danger');
    redirect('modules/assets/index.php');
}

$errors = [];
$form = $isEdit ? $asset : [
    'asset_tag' => next_asset_tag(), 'name' => '', 'short_name' => '', 'category_id' => '',
    'location_id' => '', 'manufacturer' => '', 'model' => '', 'serial_number' => '',
    'machine_type' => '', 'control_system' => '', 'year_built' => '', 'date_acquired' => '',
    'in_service_date' => '', 'status' => 'active', 'status_note' => '', 'owner_user_id' => '',
    'hour_meter' => '', 'notes' => '', 'photo_path' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();

    $form = array_merge($form, pick($_POST, [
        'asset_tag', 'name', 'short_name', 'category_id', 'location_id', 'manufacturer', 'model',
        'serial_number', 'machine_type', 'control_system', 'year_built', 'date_acquired',
        'in_service_date', 'status', 'status_note', 'owner_user_id', 'hour_meter', 'notes',
    ]));
    foreach ($form as $k => $v) {
        if (is_string($v)) {
            $form[$k] = trim($v);
        }
    }

    // ---- validation --------------------------------------------------
    if ($form['name'] === '') {
        $errors['name'] = 'An asset name is required.';
    }
    if ($form['asset_tag'] === '') {
        $form['asset_tag'] = next_asset_tag();
    }
    $dupe = db_value('SELECT id FROM assets WHERE asset_tag = :t AND id <> :id',
        [':t' => $form['asset_tag'], ':id' => $id ?: 0]);
    if ($dupe) {
        $errors['asset_tag'] = 'That asset tag is already used by another asset.';
    }
    if ($form['year_built'] !== '' && (!ctype_digit((string) $form['year_built'])
        || $form['year_built'] < 1900 || $form['year_built'] > (int) date('Y') + 1)) {
        $errors['year_built'] = 'Enter a four-digit year between 1900 and ' . (date('Y') + 1) . '.';
    }
    if (!array_key_exists($form['status'], asset_statuses())) {
        $errors['status'] = 'Choose a valid status.';
    }
    foreach (['date_acquired', 'in_service_date'] as $d) {
        if ($form[$d] !== '' && !strtotime((string) $form[$d])) {
            $errors[$d] = 'Enter a valid date.';
        }
    }

    // ---- photo -------------------------------------------------------
    $newPhoto = null;
    if (!empty($_FILES['photo']['name'])) {
        [$rel, $err] = store_upload($_FILES['photo'], 'assets', UPLOAD_IMAGE_TYPES);
        if ($err) {
            $errors['photo'] = $err;
        } elseif ($rel) {
            $newPhoto = $rel;
        }
    }

    if (!$errors) {
        $data = [
            'asset_tag'       => $form['asset_tag'],
            'name'            => $form['name'],
            'short_name'      => nn($form['short_name']),
            'category_id'     => nn($form['category_id']),
            'location_id'     => nn($form['location_id']),
            'manufacturer'    => nn($form['manufacturer']),
            'model'           => nn($form['model']),
            'serial_number'   => nn($form['serial_number']),
            'machine_type'    => nn($form['machine_type']),
            'control_system'  => nn($form['control_system']),
            'year_built'      => nn($form['year_built']),
            'date_acquired'   => nn($form['date_acquired']),
            'in_service_date' => nn($form['in_service_date']),
            'status'          => $form['status'],
            'status_note'     => nn($form['status_note']),
            'owner_user_id'   => nn($form['owner_user_id']),
            'hour_meter'      => nn($form['hour_meter']),
            'notes'           => nn($form['notes']),
            'updated_by'      => current_user_id(),
        ];
        if ($newPhoto) {
            $data['photo_path'] = $newPhoto;
        }
        if ($form['hour_meter'] !== '') {
            $data['hour_meter_at'] = date('Y-m-d H:i:s');
        }

        if ($isEdit) {
            $changes = diff_fields($asset, $data);
            if (($asset['status'] ?? '') !== $data['status']) {
                $data['status_changed_at'] = date('Y-m-d H:i:s');
            }
            db_update('assets', $data, $id);
            audit('asset.update', 'asset', $id, $changes);
            flash('Saved changes to <strong>' . h($data['name']) . '</strong>.');
        } else {
            $data['created_by']        = current_user_id();
            $data['status_changed_at'] = date('Y-m-d H:i:s');
            $id = db_insert('assets', $data);
            audit('asset.create', 'asset', $id, ['asset_tag' => $data['asset_tag'], 'name' => $data['name']]);
            flash('Added <strong>' . h($data['name']) . '</strong> to the asset register.');
        }
        redirect('modules/assets/view.php?id=' . $id);
    }
}

$pageTitle   = $isEdit ? 'Edit ' . $asset['name'] : 'New asset';
$activeNav   = 'assets';
$breadcrumbs = [
    ['label' => 'Assets', 'url' => 'modules/assets/index.php'],
    ['label' => $isEdit ? $asset['asset_tag'] : 'New'],
];
include APP_ROOT . '/templates/header.php';

$cats  = category_options();
$locs  = location_options();
$users = active_users();

function err_class(array $errors, string $field): string
{
    return isset($errors[$field]) ? ' is-invalid' : '';
}
function err_text(array $errors, string $field): string
{
    return isset($errors[$field]) ? '<div class="invalid-feedback">' . h($errors[$field]) . '</div>' : '';
}
?>

<?php if ($errors): ?>
  <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $id ?>">

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body">
          <div class="form-section-title mt-0">Identification</div>
          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label required" for="asset_tag">Asset tag</label>
              <input class="form-control font-monospace<?= err_class($errors, 'asset_tag') ?>"
                     id="asset_tag" name="asset_tag" value="<?= h($form['asset_tag']) ?>" required>
              <?= err_text($errors, 'asset_tag') ?>
              <div class="form-text">Auto-generated; override only if it must match a physical tag.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label required" for="name">Asset name</label>
              <input class="form-control<?= err_class($errors, 'name') ?>" id="name" name="name"
                     value="<?= h($form['name']) ?>" required placeholder="e.g. Hurco VMX42SRTi">
              <?= err_text($errors, 'name') ?>
            </div>
            <div class="col-md-3">
              <label class="form-label" for="short_name">Shop shorthand</label>
              <input class="form-control" id="short_name" name="short_name"
                     value="<?= h($form['short_name']) ?>" placeholder="VMX42SRTi">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="category_id">Category</label>
              <select class="form-select" id="category_id" name="category_id">
                <option value="">— none —</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"
                    <?= (string) $form['category_id'] === (string) $c['id'] ? 'selected' : '' ?>>
                    <?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="location_id">Location / department</label>
              <select class="form-select" id="location_id" name="location_id">
                <option value="">— none —</option>
                <?php foreach ($locs as $l): ?>
                  <option value="<?= (int) $l['id'] ?>"
                    <?= (string) $form['location_id'] === (string) $l['id'] ? 'selected' : '' ?>>
                    <?= h($l['name']) ?><?= $l['department'] ? ' (' . h($l['department']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="owner_user_id">Responsible owner</label>
              <select class="form-select" id="owner_user_id" name="owner_user_id">
                <option value="">— unassigned —</option>
                <?php foreach ($users as $u2): ?>
                  <option value="<?= (int) $u2['id'] ?>"
                    <?= (string) $form['owner_user_id'] === (string) $u2['id'] ? 'selected' : '' ?>>
                    <?= h($u2['full_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Receives issue and PM notifications for this asset.</div>
            </div>
          </div>

          <div class="form-section-title">Equipment detail</div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="manufacturer">Manufacturer</label>
              <input class="form-control" id="manufacturer" name="manufacturer" value="<?= h($form['manufacturer']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="model">Model</label>
              <input class="form-control" id="model" name="model" value="<?= h($form['model']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="serial_number">Serial number</label>
              <input class="form-control" id="serial_number" name="serial_number" value="<?= h($form['serial_number']) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="machine_type">Machine type</label>
              <input class="form-control" id="machine_type" name="machine_type"
                     value="<?= h($form['machine_type']) ?>" placeholder="5-Axis Trunnion VMC">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="control_system">Control</label>
              <input class="form-control" id="control_system" name="control_system"
                     value="<?= h($form['control_system']) ?>" placeholder="Hurco WinMax">
            </div>
            <div class="col-md-4">
              <label class="form-label" for="year_built">Year built</label>
              <input class="form-control<?= err_class($errors, 'year_built') ?>" id="year_built"
                     name="year_built" value="<?= h($form['year_built']) ?>" inputmode="numeric">
              <?= err_text($errors, 'year_built') ?>
            </div>
          </div>

          <div class="form-section-title">Service life</div>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label" for="date_acquired">Date acquired</label>
              <input class="form-control<?= err_class($errors, 'date_acquired') ?>" type="date"
                     id="date_acquired" name="date_acquired" value="<?= h($form['date_acquired']) ?>">
              <?= err_text($errors, 'date_acquired') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="in_service_date">In-service date</label>
              <input class="form-control<?= err_class($errors, 'in_service_date') ?>" type="date"
                     id="in_service_date" name="in_service_date" value="<?= h($form['in_service_date']) ?>">
              <?= err_text($errors, 'in_service_date') ?>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="hour_meter">Hour meter</label>
              <input class="form-control" type="number" step="0.1" id="hour_meter" name="hour_meter"
                     value="<?= h($form['hour_meter']) ?>">
              <div class="form-text">Optional. Timestamped when saved.</div>
            </div>
          </div>

          <div class="form-section-title">Notes</div>
          <textarea class="form-control" name="notes" rows="4"
                    placeholder="Anything a tech should know: quirks, known weak points, spare-part notes…"><?= h($form['notes']) ?></textarea>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-header">Status</div>
        <div class="card-body">
          <label class="form-label" for="status">Current status</label>
          <select class="form-select<?= err_class($errors, 'status') ?>" id="status" name="status">
            <?php foreach (asset_statuses() as $k => $m): ?>
              <option value="<?= h($k) ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <?= err_text($errors, 'status') ?>
          <label class="form-label mt-3" for="status_note">Status note</label>
          <input class="form-control" id="status_note" name="status_note" value="<?= h($form['status_note']) ?>"
                 placeholder="e.g. Waiting on spindle bearings">
          <div class="form-text mt-2">
            Retiring an asset keeps every PM and issue record intact — nothing is deleted.
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">Photo</div>
        <div class="card-body">
          <?php if (!empty($form['photo_path'])): ?>
            <img class="asset-photo mb-2" src="<?= h(upload_url($form['photo_path'])) ?>" alt="">
          <?php endif; ?>
          <img id="photoPreview" class="asset-photo mb-2 d-none" alt="">
          <input class="form-control<?= err_class($errors, 'photo') ?>" type="file" name="photo"
                 accept="image/*" data-preview-target="#photoPreview">
          <?= err_text($errors, 'photo') ?>
          <div class="form-text">JPG, PNG, GIF or WebP up to <?= setting_int('max_upload_mb', 12) ?> MB.</div>
        </div>
      </div>

      <div class="d-grid gap-2">
        <button class="btn btn-primary btn-lg" type="submit">
          <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save changes' : 'Add asset' ?>
        </button>
        <a class="btn btn-outline-secondary"
           href="<?= h($isEdit ? url('modules/assets/view.php?id=' . $id) : url('modules/assets/index.php')) ?>">Cancel</a>
      </div>
    </div>
  </div>
</form>

<?php if ($isEdit):
  $blockers = purge_asset_blockers($id);
  $reqCount = (int) db_value('SELECT COUNT(*) FROM maintenance_requirements WHERE asset_id = :a',
      [':a' => $id], 0);
?>
<div class="row g-3 mt-1"><div class="col-lg-8">
  <div class="card danger-zone">
    <div class="card-header"><i class="bi bi-exclamation-octagon"></i> Delete this machine</div>
    <div class="card-body">
      <p class="small mb-2">
        Deleting removes the machine, its <?= $reqCount ?> maintenance task<?= $reqCount === 1 ? '' : 's' ?>,
        its specifications, documents and photo. For a machine you have genuinely stopped using,
        set the status to <strong>Retired</strong> instead — that keeps the record and takes it off
        the schedule.
      </p>

      <?php if ($blockers['blocked']): ?>
        <div class="alert alert-warning py-2 small mb-0">
          <strong>Deletion is locked for this machine.</strong><br>
          <?= h($blockers['reason']) ?>
        </div>
      <?php else: ?>
        <?php if ($blockers['completions'] || $blockers['issues']): ?>
          <div class="alert alert-danger py-2 small">
            This will also delete <strong><?= (int) $blockers['completions'] ?></strong> sign-off
            record<?= $blockers['completions'] === 1 ? '' : 's' ?> and
            <strong><?= (int) $blockers['issues'] ?></strong> issue<?= $blockers['issues'] === 1 ? '' : 's' ?>.
            Possible only because Setup mode is on.
          </div>
        <?php endif; ?>
        <form method="post" action="<?= h(url('modules/assets/delete.php')) ?>"
              class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="asset_id" value="<?= (int) $id ?>">
          <div class="col-sm-6">
            <label class="form-label" for="confirm_tag">
              Type <code><?= h($asset['asset_tag']) ?></code> to confirm
            </label>
            <input class="form-control font-monospace" id="confirm_tag" name="confirm_tag"
                   autocomplete="off" placeholder="<?= h($asset['asset_tag']) ?>">
          </div>
          <div class="col-sm-6">
            <button class="btn btn-danger w-100" type="submit"
                    data-confirm="Delete <?= h($asset['name']) ?> permanently? This cannot be undone.">
              <i class="bi bi-trash"></i> Delete permanently
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div></div>
<?php endif; ?>
<?php include APP_ROOT . '/templates/footer.php'; ?>
