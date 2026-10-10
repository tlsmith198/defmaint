<?php
/**
 * Clone maintenance requirements from one asset to others.
 * "Apply the same weekly way-lube check to all five 5-axis mills" in one action.
 * Admin only.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$requirementId = req_int('requirement_id');
$sourceReq     = $requirementId
    ? db_one('SELECT * FROM maintenance_requirements WHERE id = :id', [':id' => $requirementId])
    : null;

$assetId = $sourceReq ? (int) $sourceReq['asset_id'] : req_int('asset_id');
$asset   = $assetId ? asset_get($assetId) : null;
if (!$asset) {
    flash('Choose the asset you want to copy from.', 'warning');
    redirect('modules/maintenance/requirements.php');
}

$sourceReqs = db_all(
    "SELECT r.*, (SELECT COUNT(*) FROM requirement_steps s
                   WHERE s.requirement_id = r.id AND s.is_active = 1) AS step_count
       FROM maintenance_requirements r
      WHERE r.asset_id = :id AND r.is_active = 1
      ORDER BY FIELD(r.frequency,'daily','weekly','monthly','quarterly','semiannual','annual','custom'),
               r.sort_order, r.id",
    [':id' => $assetId]
);

$targets = db_all(
    "SELECT a.id, a.asset_tag, a.name, a.status, c.name AS category_name, c.sort_order AS cat_order
       FROM assets a LEFT JOIN asset_categories c ON c.id = a.category_id
      WHERE a.status <> 'retired' AND a.id <> :id
      ORDER BY c.sort_order, a.asset_tag",
    [':id' => $assetId]
);
$byCategory = [];
foreach ($targets as $t) {
    $byCategory[$t['category_name'] ?: 'Uncategorised'][] = $t;
}

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $pickedReqs   = array_map('intval', (array) ($_POST['requirements'] ?? []));
    $pickedAssets = array_map('intval', (array) ($_POST['assets'] ?? []));
    $overwrite    = !empty($_POST['overwrite']);

    if (!$pickedReqs)   flash('Pick at least one requirement to copy.', 'warning');
    if (!$pickedAssets) flash('Pick at least one target asset.', 'warning');

    if ($pickedReqs && $pickedAssets) {
        $created = $updated = $skipped = 0;
        $touchedRequirements = [];

        db()->beginTransaction();
        try {
            foreach ($pickedReqs as $rid) {
                $src = db_one('SELECT * FROM maintenance_requirements WHERE id = :id AND asset_id = :a',
                    [':id' => $rid, ':a' => $assetId]);
                if (!$src) {
                    continue;
                }
                $templateKey = $src['template_key'] ?: 'req_' . bin2hex(random_bytes(5));
                if (!$src['template_key']) {
                    db_query('UPDATE maintenance_requirements SET template_key = :k WHERE id = :id',
                        [':k' => $templateKey, ':id' => $rid]);
                }
                $srcSteps = db_all(
                    'SELECT * FROM requirement_steps WHERE requirement_id = :r AND is_active = 1 ORDER BY step_no',
                    [':r' => $rid]
                );

                foreach ($pickedAssets as $targetId) {
                    $existing = db_one(
                        'SELECT id FROM maintenance_requirements
                          WHERE asset_id = :a AND frequency = :f AND task_name = :t',
                        [':a' => $targetId, ':f' => $src['frequency'], ':t' => $src['task_name']]
                    );

                    $fields = [
                        'asset_id'           => $targetId,
                        'task_name'          => $src['task_name'],
                        'spec_note'          => $src['spec_note'],
                        'instructions'       => $src['instructions'],
                        'frequency'          => $src['frequency'],
                        'interval_days'      => $src['interval_days'],
                        'schedule_mode'      => $src['schedule_mode'],
                        'fixed_day_of_month' => $src['fixed_day_of_month'],
                        'fixed_weekday'      => $src['fixed_weekday'],
                        'grace_days'         => $src['grace_days'],
                        'tools_consumables'  => $src['tools_consumables'],
                        'est_minutes'        => $src['est_minutes'],
                        'assigned_role'      => $src['assigned_role'],
                        'is_safety_critical' => $src['is_safety_critical'],
                        'sort_order'         => $src['sort_order'],
                        'template_key'       => $templateKey,
                        'is_active'          => 1,
                        'updated_by'         => current_user_id(),
                    ];

                    if ($existing && !$overwrite) {
                        $skipped++;
                        continue;
                    }
                    if ($existing) {
                        $newId = (int) $existing['id'];
                        db_update('maintenance_requirements', $fields, $newId);
                        db_query('UPDATE requirement_steps SET is_active = 0 WHERE requirement_id = :r',
                            [':r' => $newId]);
                        $updated++;
                    } else {
                        $fields['created_by'] = current_user_id();
                        $newId = db_insert('maintenance_requirements', $fields);
                        $created++;
                    }

                    foreach ($srcSteps as $s) {
                        db_insert('requirement_steps', [
                            'requirement_id' => $newId,
                            'step_no'        => $s['step_no'],
                            'step_text'      => $s['step_text'],
                            'response_type'  => $s['response_type'],
                            'value_unit'     => $s['value_unit'],
                            'value_min'      => $s['value_min'],
                            'value_max'      => $s['value_max'],
                            'is_required'    => $s['is_required'],
                            'is_active'      => 1,
                        ]);
                    }
                    $touchedRequirements[] = $newId;
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        foreach ($touchedRequirements as $newId) {
            due_engine_generate($newId);
        }

        audit('requirement.clone', 'asset', $assetId, [
            'requirements' => count($pickedReqs), 'targets' => count($pickedAssets),
            'created' => $created, 'updated' => $updated, 'skipped' => $skipped,
        ]);

        $result = compact('created', 'updated', 'skipped');
        flash(sprintf(
            '%d requirement%s created, %d updated, %d skipped as already present.',
            $created, $created === 1 ? '' : 's', $updated, $skipped
        ), $created || $updated ? 'success' : 'info');
    }
}

$pageTitle   = 'Clone requirements from ' . $asset['name'];
$activeNav   = 'maintenance';
$breadcrumbs = [
    ['label' => 'Maintenance', 'url' => 'modules/maintenance/index.php'],
    ['label' => 'Requirements', 'url' => 'modules/maintenance/requirements.php'],
    ['label' => $asset['asset_tag'], 'url' => 'modules/maintenance/requirements.php?asset_id=' . $assetId],
    ['label' => 'Clone'],
];
include APP_ROOT . '/templates/header.php';
?>
<form method="post" data-guard>
  <?= csrf_field() ?>
  <input type="hidden" name="asset_id" value="<?= (int) $assetId ?>">

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>1. What to copy — from <?= h($asset['asset_tag']) ?></span>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-check-all="#srcList">All / none</button>
        </div>
        <div class="card-body p-0" style="max-height:26rem;overflow:auto" id="srcList">
          <table class="table table-admi mb-0">
            <tbody>
            <?php $lastFreq = null; foreach ($sourceReqs as $r): ?>
              <?php if ($r['frequency'] !== $lastFreq): $lastFreq = $r['frequency']; ?>
                <tr class="table-light"><td colspan="2" class="fw-semibold small text-uppercase">
                  <?= h(label_of($r['frequency'], frequencies())) ?></td></tr>
              <?php endif; ?>
              <tr>
                <td style="width:2.5rem">
                  <input class="form-check-input" type="checkbox" name="requirements[]" value="<?= (int) $r['id'] ?>"
                         id="req<?= (int) $r['id'] ?>"
                         <?= ($requirementId && $requirementId === (int) $r['id']) ? 'checked' : '' ?>>
                </td>
                <td><label class="form-check-label" for="req<?= (int) $r['id'] ?>">
                  <?= h($r['task_name']) ?>
                  <?php if ($r['step_count']): ?>
                    <span class="badge text-bg-light border"><?= (int) $r['step_count'] ?> steps</span>
                  <?php endif; ?>
                  <?php if ($r['spec_note']): ?>
                    <div class="small text-muted"><?= h($r['spec_note']) ?></div>
                  <?php endif; ?>
                </label></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$sourceReqs): ?>
              <tr><td class="text-muted small p-3">This asset has no active requirements to copy.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>2. Which assets to copy to</span>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-check-all="#tgtList">All / none</button>
        </div>
        <div class="card-body p-0" style="max-height:26rem;overflow:auto" id="tgtList">
          <table class="table table-admi mb-0">
            <tbody>
            <?php foreach ($byCategory as $cat => $rows): ?>
              <tr class="table-light">
                <td colspan="2" class="fw-semibold small text-uppercase">
                  <?= h($cat) ?>
                  <button class="btn btn-link btn-sm p-0 ms-2 text-lowercase" type="button"
                          data-check-group="<?= h(md5($cat)) ?>">select all</button>
                </td>
              </tr>
              <?php foreach ($rows as $t): ?>
                <tr>
                  <td style="width:2.5rem">
                    <input class="form-check-input grp-<?= h(md5($cat)) ?>" type="checkbox" name="assets[]"
                           value="<?= (int) $t['id'] ?>" id="ast<?= (int) $t['id'] ?>">
                  </td>
                  <td><label class="form-check-label" for="ast<?= (int) $t['id'] ?>">
                    <span class="font-monospace text-muted small"><?= h($t['asset_tag']) ?></span>
                    <?= h($t['name']) ?>
                    <?php if ($t['status'] !== 'active'): ?>
                      <?= badge($t['status'], asset_statuses()) ?>
                    <?php endif; ?>
                  </label></td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="overwrite" name="overwrite" value="1">
        <label class="form-check-label" for="overwrite">
          Overwrite when the target already has a task with the same name and frequency
          <span class="d-block small text-muted">
            Off by default — existing tasks are left alone and reported as skipped.
            Overwriting updates the definition; completion history is never affected.
          </span>
        </label>
      </div>
      <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-files"></i> Clone</button>
    </div>
  </div>
</form>

<script>
document.querySelectorAll('[data-check-all]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var boxes = document.querySelectorAll(btn.dataset.checkAll + ' input[type=checkbox]');
    var anyOff = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
    boxes.forEach(function (b) { b.checked = anyOff; });
  });
});
document.querySelectorAll('[data-check-group]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var boxes = document.querySelectorAll('.grp-' + btn.dataset.checkGroup);
    var anyOff = Array.prototype.some.call(boxes, function (b) { return !b.checked; });
    boxes.forEach(function (b) { b.checked = anyOff; });
  });
});
</script>
<?php include APP_ROOT . '/templates/footer.php'; ?>
