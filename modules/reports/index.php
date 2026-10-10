<?php
/** AS9100 report pack: pick a report, set its parameters, view / PDF / CSV. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
if (!can('view_reports')) {
    http_response_code(403);
    include APP_ROOT . '/templates/403.php';
    exit;
}
require_once APP_ROOT . '/includes/reports_lib.php';

$assets = db_all("SELECT id, asset_tag, name FROM assets WHERE status <> 'retired' ORDER BY asset_tag");
$cat    = report_catalog();
$today  = date('Y-m-d');
$from90 = date('Y-m-d', strtotime('-90 days'));
$fromYr = date('Y-01-01');

$pageTitle = 'AS9100 Reports';
$activeNav = 'reports';
$pdfReady  = is_file(APP_ROOT . '/vendor/autoload.php');
include APP_ROOT . '/templates/header.php';

function rp_field(string $kind, array $assets, string $from90, string $today): void
{
    switch ($kind) {
        case 'asset':
        case 'asset_opt':
            echo '<div class="col-12"><label class="form-label small mb-1">Machine' . ($kind === 'asset' ? '' : ' (optional)') . '</label>'
               . '<select class="form-select form-select-sm" name="asset_id"' . ($kind === 'asset' ? ' required' : '') . '>'
               . '<option value="">' . ($kind === 'asset' ? 'Choose…' : 'All machines') . '</option>';
            foreach ($assets as $a) {
                echo '<option value="' . (int) $a['id'] . '">' . h($a['asset_tag'] . ' – ' . $a['name']) . '</option>';
            }
            echo '</select></div>';
            break;
        case 'range':
            echo '<div class="col-6"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="' . h($from90) . '"></div>'
               . '<div class="col-6"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="' . h($today) . '"></div>';
            break;
        case 'frequency':
            echo '<div class="col-6"><label class="form-label small mb-1">Frequency</label><select class="form-select form-select-sm" name="frequency"><option value="">Any</option>';
            foreach (frequencies() as $k => $f) { echo '<option value="' . h($k) . '">' . h($f['label']) . '</option>'; }
            echo '</select></div>';
            break;
        case 'result':
            echo '<div class="col-6"><label class="form-label small mb-1">Result</label><select class="form-select form-select-sm" name="result"><option value="">Any</option>';
            foreach (['done' => 'Done', 'pass' => 'Pass', 'not_done' => 'Not done', 'fail' => 'Fail', 'na' => 'N/A'] as $k => $l) { echo '<option value="' . $k . '">' . $l . '</option>'; }
            echo '</select></div>';
            break;
        case 'severity':
            echo '<div class="col-6"><label class="form-label small mb-1">Severity</label><select class="form-select form-select-sm" name="severity"><option value="">Any</option>';
            foreach (severities() as $k => $s) { echo '<option value="' . h($k) . '">' . h($s['label']) . '</option>'; }
            echo '</select></div>';
            break;
        case 'issue_status':
            echo '<div class="col-6"><label class="form-label small mb-1">Status</label><select class="form-select form-select-sm" name="issue_status"><option value="">Any</option>';
            foreach (issue_statuses() as $k => $s) { echo '<option value="' . h($k) . '">' . h($s['label']) . '</option>'; }
            echo '</select></div>';
            break;
        case 'scope':
            echo '<div class="col-12"><label class="form-label small mb-1">Show</label><select class="form-select form-select-sm" name="scope">'
               . '<option value="overdue">Overdue now</option><option value="next7">Overdue + next 7 days</option>'
               . '<option value="next30">Overdue + next 30 days</option><option value="all">Everything open</option></select></div>';
            break;
        case 'asset_status':
            echo '<div class="col-12"><label class="form-label small mb-1">Status</label><select class="form-select form-select-sm" name="asset_status"><option value="">All (except retired)</option>';
            foreach (asset_statuses() as $k => $s) { echo '<option value="' . h($k) . '">' . h($s['label']) . '</option>'; }
            echo '</select></div>';
            break;
    }
}
?>
<?php if (!$pdfReady): ?>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> The PDF library is not installed
    (<code>vendor/</code> folder missing). On-screen and CSV reports work; upload the <code>vendor</code> folder from the full release to enable PDF.</div>
<?php endif; ?>
<p class="text-muted">Records come straight from the append-only maintenance log: every PM sign-off carries who, when and what the task
  said at the time. Each PDF/CSV export is itself recorded in the audit trail.</p>
<div class="row g-3">
<?php foreach ($cat as $key => $c): ?>
  <div class="col-md-6 col-xl-4">
    <form class="card h-100" method="get" action="<?= h(url('modules/reports/run.php')) ?>" target="_self">
      <input type="hidden" name="report" value="<?= h($key) ?>">
      <div class="card-body">
        <h2 class="h6 mb-1"><i class="bi <?= h($c['icon']) ?> text-primary"></i> <?= h($c['title']) ?></h2>
        <p class="small text-muted"><?= h($c['desc']) ?></p>
        <div class="row g-2">
          <?php foreach ($c['params'] as $kind) { rp_field($kind, $assets, $key === 'asset_history' ? $fromYr : $from90, $today); } ?>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-sm btn-primary" name="format" value="html"><i class="bi bi-eye"></i> View</button>
        <button class="btn btn-sm btn-outline-danger" name="format" value="pdf" <?= $pdfReady ? '' : 'disabled' ?>><i class="bi bi-file-earmark-pdf"></i> PDF</button>
        <button class="btn btn-sm btn-outline-success" name="format" value="csv"><i class="bi bi-filetype-csv"></i> CSV</button>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
