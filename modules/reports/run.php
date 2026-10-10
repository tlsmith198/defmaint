<?php
/** Render one report on screen, as PDF, or as CSV. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
if (!can('view_reports')) {
    http_response_code(403);
    include APP_ROOT . '/templates/403.php';
    exit;
}
require_once APP_ROOT . '/includes/reports_lib.php';

$key = (string) req('report', '');
$cat = report_catalog();
if (!isset($cat[$key])) {
    flash('Choose a report.', 'warning');
    redirect('modules/reports/index.php');
}
$p = report_params($_GET);
if (in_array('asset', $cat[$key]['params'], true) && !$p['asset_id']) {
    flash('Choose a machine for that report.', 'warning');
    redirect('modules/reports/index.php');
}
$r = report_build($key, $p);
if (!$r) {
    flash('That report could not be built (machine not found).', 'danger');
    redirect('modules/reports/index.php');
}

$format = (string) req('format', 'html');
$base = $key . '_' . date('Ymd_His');
if ($format === 'pdf' || $format === 'csv') {
    audit('report.' . $format, 'report', null, ['report' => $key] + array_filter($p));
}
if ($format === 'pdf') {
    report_pdf($r, $cat[$key]['landscape'], $base . '.pdf', req('inline') === '1');
}
if ($format === 'csv') {
    report_csv($r, $base . '.csv');
}

$qs = $_GET;
unset($qs['format'], $qs['inline']);
$pageTitle   = $cat[$key]['title'];
$activeNav   = 'reports';
$breadcrumbs = [['label' => 'Reports', 'url' => 'modules/reports/index.php'], ['label' => $cat[$key]['title']]];
$pageActions = '<a class="btn btn-danger" href="' . h(url('modules/reports/run.php?' . http_build_query($qs + ['format' => 'pdf']))) . '"><i class="bi bi-file-earmark-pdf"></i> PDF</a> '
             . '<a class="btn btn-outline-success" href="' . h(url('modules/reports/run.php?' . http_build_query($qs + ['format' => 'csv']))) . '"><i class="bi bi-filetype-csv"></i> CSV</a> '
             . '<button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>';
include APP_ROOT . '/templates/header.php';
?>
<style>
  .rpt{background:#fff;border:1px solid var(--admi-line);border-radius:.4rem;padding:1.25rem 1.5rem;overflow-x:auto}
  <?= str_replace(['body{font-family:dejavusanscondensed,sans-serif;font-size:8.5pt;color:#1a1a1a}'], [''], report_css()) ?>
  .rpt table.data th,.rpt table.data td{font-size:.78rem} .rpt table.facts td{font-size:.85rem} .rpt h1{font-size:1.4rem}
  .rpt table.sum .n{font-size:1.25rem} .rpt table.sum .l{font-size:.7rem} .rpt .sub{font-size:1rem}
  @media print{.navbar,.page-head,.breadcrumb,.btn,footer{display:none!important} .rpt{border:0;padding:0}}
</style>
<div class="rpt"><?= report_body_html($r) ?></div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
