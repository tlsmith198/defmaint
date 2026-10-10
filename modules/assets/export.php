<?php
/** CSV export of the current asset-register filter set. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$rows = asset_list([
    'q'               => (string) req('q', ''),
    'status'          => (string) req('status', ''),
    'category_id'     => req_int('category_id'),
    'location_id'     => req_int('location_id'),
    'owner_id'        => req_int('owner_id'),
    'due'             => (string) req('due', ''),
    'sort'            => (string) req('sort', 'tag'),
    'dir'             => (string) req('dir', 'asc'),
    'include_retired' => req('retired') === '1',
]);

$filename = 'ADMI_Asset_Register_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");            // BOM so Excel reads UTF-8 cleanly
fputcsv($out, ['Asset Tag', 'Name', 'Category', 'Location', 'Department', 'Manufacturer', 'Model',
    'Serial', 'Machine Type', 'Control', 'Year', 'Status', 'Owner', 'In Service',
    'Active PM Tasks', 'Next PM Due', 'Open Issues']);

foreach ($rows as $a) {
    fputcsv($out, [
        $a['asset_tag'], $a['name'], $a['category_name'], $a['location_name'], $a['department'],
        $a['manufacturer'], $a['model'], $a['serial_number'], $a['machine_type'], $a['control_system'],
        $a['year_built'], label_of($a['status'], asset_statuses()), $a['owner_name'],
        $a['in_service_date'], $a['req_count'], $a['next_due'], $a['open_issues'],
    ]);
}
fclose($out);
audit('asset.export_csv', 'asset', null, ['rows' => count($rows)]);
