<?php
/** POST-only: change an asset's status. Admin only. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();
require_post_csrf();

$assetId = req_int('asset_id');
$status  = (string) req('status');
$note    = (string) req('status_note', '');

if (!$assetId || !asset_get($assetId)) {
    flash('Asset not found.', 'danger');
    redirect('modules/assets/index.php');
}
if (!array_key_exists($status, asset_statuses())) {
    flash('That is not a valid status.', 'danger');
    redirect('modules/assets/view.php?id=' . $assetId);
}

asset_set_status($assetId, $status, $note);
flash('Status changed to <strong>' . h(label_of($status, asset_statuses())) . '</strong>.');
redirect('modules/assets/view.php?id=' . $assetId);
