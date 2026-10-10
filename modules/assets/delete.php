<?php
/**
 * POST-only: permanently delete an asset and everything attached to it.
 * Admin only. purge_asset() refuses when the machine carries history and the
 * record is locked (Setup mode off).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();
require_post_csrf();

$assetId = req_int('asset_id');
$asset   = $assetId ? asset_get($assetId) : null;

if (!$asset) {
    flash('Asset not found.', 'danger');
    redirect('modules/assets/index.php');
}

// Typing the tag is the confirmation — a browser dialog is too easy to click past
// for something that takes a machine's whole maintenance record with it.
$typed = trim((string) req('confirm_tag', ''));
if (strcasecmp($typed, (string) $asset['asset_tag']) !== 0) {
    flash('Type the asset tag <code>' . h($asset['asset_tag'])
        . '</code> exactly to confirm the deletion.', 'warning');
    redirect('modules/assets/form.php?id=' . $assetId);
}

[$ok, $msg] = purge_asset($assetId);

if ($ok) {
    flash(h($msg));
    redirect('modules/assets/index.php');
}

flash(h($msg), 'danger');
redirect('modules/assets/form.php?id=' . $assetId);
