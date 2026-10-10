<?php
/** Asset detail: overview, specs, PM requirements, full history, documents. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id    = req_int('id');
$asset = $id ? asset_get($id) : null;
if (!$asset) {
    http_response_code(404);
    flash('That asset was not found.', 'danger');
    redirect('modules/assets/index.php');
}

$tab      = (string) req('tab', 'overview');
$specs    = asset_specs($id);
$reqs     = db_all(
    "SELECT r.*, u.full_name AS assignee_name,
            (SELECT MIN(d.due_date) FROM maintenance_due_instances d
              WHERE d.requirement_id = r.id AND d.status = 'scheduled') AS next_due
       FROM maintenance_requirements r
       LEFT JOIN users u ON u.id = r.assigned_user_id
      WHERE r.asset_id = :id
      ORDER BY r.is_active DESC,
               FIELD(r.frequency,'daily','weekly','monthly','quarterly','semiannual','annual','custom'),
               r.sort_order, r.id",
    [':id' => $id]
);
$history  = asset_history($id);
$docs     = attachments_for('asset', $id);
$openIssues = db_all(
    "SELECT i.*, u.full_name AS reporter FROM issues i JOIN users u ON u.id = i.reported_by
      WHERE i.asset_id = :id AND i.status IN ('open','acknowledged','in_progress')
      ORDER BY FIELD(i.severity,'down','major','minor'), i.reported_at DESC",
    [':id' => $id]
);
$compStats = db_one(
    "SELECT COUNT(*) AS n, MAX(completed_at) AS last_at,
            SUM(result IN ('fail','not_done')) AS fails
       FROM maintenance_completions WHERE asset_id = :id",
    [':id' => $id]
) ?: [];

$byFreq = [];
foreach ($reqs as $r) {
    if ($r['is_active']) {
        $byFreq[$r['frequency']] = ($byFreq[$r['frequency']] ?? 0) + 1;
    }
}

$pageTitle   = $asset['name'];
$activeNav   = 'assets';
$breadcrumbs = [['label' => 'Assets', 'url' => 'modules/assets/index.php'], ['label' => $asset['asset_tag']]];
$pageActions = '';
if (can('log_issue')) {
    $pageActions .= '<a class="btn btn-danger" href="' . h(url('modules/issues/form.php?asset_id=' . $id))
        . '"><i class="bi bi-exclamation-triangle"></i> Log issue</a> ';
}
if (can('manage_assets')) {
    $pageActions .= '<a class="btn btn-outline-primary" href="' . h(url('modules/assets/form.php?id=' . $id))
        . '"><i class="bi bi-pencil"></i> Edit</a> '
        . '<button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#statusModal">'
        . '<i class="bi bi-arrow-repeat"></i> Change status</button>';
}

include APP_ROOT . '/templates/header.php';

function tab_link(string $key, string $label, string $current, int $assetId, int $count = -1): void
{
    printf(
        '<li class="nav-item"><a class="nav-link %s" href="%s">%s%s</a></li>',
        $key === $current ? 'active' : '',
        h(url('modules/assets/view.php?id=' . $assetId . '&tab=' . $key)),
        h($label),
        $count >= 0 ? ' <span class="badge text-bg-secondary">' . $count . '</span>' : ''
    );
}
?>

<div class="row g-3 mb-3">
  <div class="col-lg-3">
    <?php if ($asset['photo_path']): ?>
      <img class="asset-photo" src="<?= h(upload_url($asset['photo_path'])) ?>" alt="<?= h($asset['name']) ?>">
    <?php else: ?>
      <div class="asset-photo-empty"><i class="bi bi-camera fs-3"></i></div>
    <?php endif; ?>
  </div>
  <div class="col-lg-9">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
          <span class="font-monospace text-muted"><?= h($asset['asset_tag']) ?></span>
          <?= badge($asset['status'], asset_statuses()) ?>
          <?php if ($asset['status_note']): ?>
            <span class="small text-muted"><?= h($asset['status_note']) ?></span>
          <?php endif; ?>
        </div>
        <div class="row row-cols-2 row-cols-md-4 g-2 small">
          <div><div class="text-muted">Manufacturer</div><?= h($asset['manufacturer'] ?: '—') ?></div>
          <div><div class="text-muted">Model</div><?= h($asset['model'] ?: '—') ?></div>
          <div><div class="text-muted">Serial</div><?= h($asset['serial_number'] ?: '—') ?></div>
          <div><div class="text-muted">Year</div><?= h($asset['year_built'] ?: '—') ?></div>
          <div><div class="text-muted">Category</div><?= h($asset['category_name'] ?: '—') ?></div>
          <div><div class="text-muted">Location</div><?= h($asset['location_name'] ?: '—') ?></div>
          <div><div class="text-muted">Control</div><?= h($asset['control_system'] ?: '—') ?></div>
          <div><div class="text-muted">Owner</div><?= h($asset['owner_name'] ?: 'Unassigned') ?></div>
          <div><div class="text-muted">In service</div><?= h(fmt_date($asset['in_service_date'])) ?></div>
          <div><div class="text-muted">Acquired</div><?= h(fmt_date($asset['date_acquired'])) ?></div>
          <div><div class="text-muted">Hour meter</div><?= h(fmt_number($asset['hour_meter'], 1)) ?></div>
          <div><div class="text-muted">Last PM</div><?= h(fmt_date($compStats['last_at'] ?? null)) ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($openIssues): ?>
<div class="alert alert-<?= $openIssues[0]['severity'] === 'down' ? 'danger' : 'warning' ?>">
  <strong><?= count($openIssues) ?> open issue<?= count($openIssues) === 1 ? '' : 's' ?></strong> on this asset:
  <?php foreach ($openIssues as $i): ?>
    <a class="alert-link" href="<?= h(url('modules/issues/view.php?id=' . $i['id'])) ?>"><?= h($i['issue_no']) ?></a>
    <?= h($i['title']) ?> (<?= h(label_of($i['severity'], severities())) ?>)<?= $i === end($openIssues) ? '' : ';' ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <?php
  tab_link('overview',    'Overview',      $tab, $id);
  tab_link('maintenance', 'PM Schedule',   $tab, $id, count(array_filter($reqs, fn($r) => $r['is_active'])));
  tab_link('history',     'History',       $tab, $id, count($history));
  tab_link('documents',   'Documents',     $tab, $id, count($docs));
  ?>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">Specifications</div>
        <div class="card-body">
          <?php if (!$specs): ?>
            <p class="text-muted small mb-0">No specifications recorded for this asset.</p>
          <?php else: ?>
            <div class="row">
            <?php foreach ($specs as $group => $rows): ?>
              <div class="col-md-6">
                <div class="spec-group-title"><?= h($group) ?></div>
                <table class="table table-sm spec-table mb-2">
                  <tbody>
                  <?php foreach ($rows as $s): ?>
                    <tr><td><?= h($s['spec_key']) ?></td><td><?= h($s['spec_value']) ?></td></tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-header">PM coverage</div>
        <div class="card-body">
          <?php if (!$byFreq): ?>
            <p class="text-muted small mb-0">No active maintenance requirements yet.</p>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach (frequencies() as $k => $m): if (empty($byFreq[$k])) continue; ?>
                <span class="badge text-bg-light border fs-6">
                  <?= h($m['label']) ?> <strong><?= (int) $byFreq[$k] ?></strong></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <hr>
          <div class="row text-center small">
            <div class="col"><div class="fs-5 fw-bold"><?= (int) ($compStats['n'] ?? 0) ?></div>
              <div class="text-muted">PM records</div></div>
            <div class="col"><div class="fs-5 fw-bold text-danger"><?= (int) ($compStats['fails'] ?? 0) ?></div>
              <div class="text-muted">Failed checks</div></div>
            <div class="col"><div class="fs-5 fw-bold"><?= count($openIssues) ?></div>
              <div class="text-muted">Open issues</div></div>
          </div>
        </div>
      </div>
      <?php if ($asset['notes']): ?>
      <div class="card">
        <div class="card-header">Notes</div>
        <div class="card-body"><div class="small" style="white-space:pre-wrap"><?= h($asset['notes']) ?></div></div>
      </div>
      <?php endif; ?>
      <div class="card mt-3">
        <div class="card-body small text-muted">
          Created <?= h(fmt_datetime($asset['created_at'])) ?>
          <?= $asset['created_by_name'] ? 'by ' . h($asset['created_by_name']) : '' ?><br>
          Last updated <?= h(fmt_datetime($asset['updated_at'])) ?>
          <?= $asset['updated_by_name'] ? 'by ' . h($asset['updated_by_name']) : '' ?>
        </div>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'maintenance'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Preventive maintenance requirements</span>
      <?php if (can('manage_requirements')): ?>
        <a class="btn btn-sm btn-primary" href="<?= h(url('modules/maintenance/requirements.php?asset_id=' . $id)) ?>">
          <i class="bi bi-sliders"></i> Manage requirements</a>
      <?php endif; ?>
    </div>
    <?php if (!$reqs): ?>
      <div class="card-body text-muted small">
        No maintenance requirements are defined for this asset yet.
      </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-admi mb-0">
        <thead><tr>
          <th style="width:9rem">Frequency</th><th>Task</th><th>Spec / notes</th>
          <th>Assigned</th><th>Last done</th><th>Next due</th>
        </tr></thead>
        <tbody>
        <?php $lastFreq = null; foreach ($reqs as $r):
          if ($r['frequency'] !== $lastFreq):
            $lastFreq = $r['frequency']; ?>
            <tr class="table-light"><td colspan="6" class="fw-semibold small text-uppercase">
              <?= h(label_of($r['frequency'], frequencies())) ?>
              <?php if ($r['frequency'] === 'custom'): ?>
                <span class="text-muted">(every <?= (int) $r['interval_days'] ?> days)</span>
              <?php endif; ?>
            </td></tr>
        <?php endif; ?>
          <tr class="<?= $r['is_active'] ? '' : 'is-retired' ?>">
            <td class="small text-muted"><?= $r['is_active'] ? '' : '<span class="badge text-bg-secondary">Retired</span>' ?>
              <?= $r['is_safety_critical'] ? '<span class="badge text-bg-danger">Safety</span>' : '' ?></td>
            <td><?= h($r['task_name']) ?></td>
            <td class="small text-muted"><?= h($r['spec_note'] ?: '—') ?></td>
            <td class="small"><?= h($r['assignee_name'] ?: ($r['assigned_role'] ?: '—')) ?></td>
            <td class="small"><?= h(fmt_date($r['last_completed_at'])) ?></td>
            <td class="small">
              <?php if ($r['next_due']): $c = $r['next_due'] < date('Y-m-d') ? 'due-overdue' : 'due-soon'; ?>
                <span class="<?= $c ?>"><?= h(fmt_date($r['next_due'])) ?></span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'history'): ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Complete history — PM completions and issues, newest first</span>
      <a class="btn btn-sm btn-outline-secondary"
         href="<?= h(url('modules/reports/asset_history.php?asset_id=' . $id)) ?>">
        <i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div>
    <div class="card-body">
      <?php if (!$history): ?>
        <p class="text-muted small mb-0">Nothing has been logged against this asset yet.</p>
      <?php else: ?>
      <div class="timeline">
        <?php foreach ($history as $ev):
          $fail = in_array($ev['state'], ['fail', 'not_done'], true); ?>
          <div class="timeline-item kind-<?= h($ev['kind']) ?> <?= $fail ? 'fail' : '' ?>">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div>
                <?php if ($ev['kind'] === 'issue'): ?>
                  <a class="fw-semibold text-decoration-none"
                     href="<?= h(url('modules/issues/view.php?id=' . $ev['ref_id'])) ?>"><?= h($ev['title']) ?></a>
                  <?= badge($ev['meta'], severities()) ?>
                  <?= badge($ev['state'], issue_statuses()) ?>
                <?php else: ?>
                  <span class="fw-semibold"><?= h($ev['title']) ?></span>
                  <span class="badge text-bg-light"><?= h(label_of($ev['meta'], frequencies())) ?></span>
                  <span class="badge text-bg-<?= $fail ? 'danger' : 'success' ?>">
                    <?= h(ucfirst(str_replace('_', ' ', $ev['state']))) ?></span>
                <?php endif; ?>
              </div>
              <div class="timeline-meta text-end">
                <?= h(fmt_datetime($ev['happened_at'])) ?><br><?= h($ev['actor']) ?>
              </div>
            </div>
            <?php if (!empty($ev['body'])): ?>
              <div class="small text-muted mt-1" style="white-space:pre-wrap"><?= h($ev['body']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

<?php else: /* documents */ ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">Linked documents</div>
        <?php if (!$docs): ?>
          <div class="card-body text-muted small">
            No manuals, warranty documents or lube charts attached yet.
          </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-admi mb-0">
            <thead><tr><th>Document</th><th>Type</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($docs as $d): ?>
              <tr>
                <td><i class="bi <?= h(file_icon($d['mime_type'])) ?>"></i>
                  <a href="<?= h(upload_url($d['file_path'])) ?>" target="_blank" rel="noopener">
                    <?= h($d['title'] ?: $d['original_name']) ?></a></td>
                <td class="small text-muted"><?= h(ucfirst(str_replace('_', ' ', $d['doc_type']))) ?></td>
                <td class="small"><?= h(human_filesize((int) $d['file_size'])) ?></td>
                <td class="small"><?= h(fmt_date($d['uploaded_at'])) ?><br>
                  <span class="text-muted"><?= h($d['uploaded_by_name']) ?></span></td>
                <td class="text-end">
                  <?php if (can('manage_assets')): ?>
                  <form method="post" action="<?= h(url('modules/assets/documents.php')) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="void">
                    <input type="hidden" name="attachment_id" value="<?= (int) $d['id'] ?>">
                    <input type="hidden" name="asset_id" value="<?= (int) $id ?>">
                    <button class="btn btn-sm btn-outline-danger" data-confirm="Remove this document from the asset? The file and its record are retained for audit.">
                      <i class="bi bi-x-lg"></i></button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php if (can('manage_assets')): ?>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">Attach a document</div>
        <div class="card-body">
          <form method="post" action="<?= h(url('modules/assets/documents.php')) ?>"
                enctype="multipart/form-data" data-guard>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="asset_id" value="<?= (int) $id ?>">
            <div class="mb-2">
              <label class="form-label" for="doc_title">Title</label>
              <input class="form-control" id="doc_title" name="title" placeholder="Operator manual, lube chart…">
            </div>
            <div class="mb-2">
              <label class="form-label" for="doc_type">Type</label>
              <select class="form-select" id="doc_type" name="doc_type">
                <option value="manual">Manual</option>
                <option value="lube_chart">Lube chart</option>
                <option value="procedure">Procedure / work instruction</option>
                <option value="warranty">Warranty</option>
                <option value="calibration">Calibration certificate</option>
                <option value="photo">Photo</option>
                <option value="other" selected>Other</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label required" for="doc_file">File</label>
              <input class="form-control" type="file" id="doc_file" name="document" required>
              <div class="form-text">PDF, image, Word or Excel up to <?= setting_int('max_upload_mb', 12) ?> MB.</div>
            </div>
            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-upload"></i> Upload</button>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (can('manage_assets')): ?>
<div class="modal fade" id="statusModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="<?= h(url('modules/assets/status.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="asset_id" value="<?= (int) $id ?>">
      <div class="modal-header">
        <h5 class="modal-title">Change status — <?= h($asset['name']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <label class="form-label" for="new_status">New status</label>
        <select class="form-select mb-3" id="new_status" name="status">
          <?php foreach (asset_statuses() as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $asset['status'] === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="form-label" for="new_note">Reason / note</label>
        <input class="form-control" id="new_note" name="status_note" value="<?= h($asset['status_note']) ?>">
        <div class="form-text mt-2">Recorded in the audit trail with your name and timestamp.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" type="submit">Update status</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include APP_ROOT . '/templates/footer.php'; ?>
