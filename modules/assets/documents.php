<?php
/** POST-only: attach or void a document on an asset. Admin only. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();
require_post_csrf();

$assetId = req_int('asset_id');
$action  = (string) req('action');
$back    = 'modules/assets/view.php?id=' . $assetId . '&tab=documents';

if (!$assetId || !asset_get($assetId)) {
    flash('Asset not found.', 'danger');
    redirect('modules/assets/index.php');
}

if ($action === 'upload') {
    [$rel, $err] = store_upload($_FILES['document'] ?? [], 'documents', UPLOAD_DOC_TYPES);
    if ($err) {
        flash(h($err), 'danger');
    } elseif (!$rel) {
        flash('Choose a file to upload.', 'warning');
    } else {
        $attId = attach('asset', $assetId, $rel, $_FILES['document'],
            (string) req('doc_type', 'other'), (string) req('title', '') ?: null);
        audit('asset.document_add', 'asset', $assetId, ['attachment_id' => $attId, 'file' => $rel]);
        flash('Document attached.');
    }
} elseif ($action === 'void') {
    $attId = req_int('attachment_id');
    $att   = $attId ? db_one('SELECT * FROM attachments WHERE id = :id AND entity_type = "asset" AND entity_id = :a',
        [':id' => $attId, ':a' => $assetId]) : null;
    if (!$att) {
        flash('Document not found.', 'danger');
    } else {
        // Soft-void only: the row and the file stay on disk for audit purposes.
        db_query('UPDATE attachments SET is_void = 1, voided_by = :u, voided_at = NOW() WHERE id = :id',
            [':u' => current_user_id(), ':id' => $attId]);
        audit('asset.document_void', 'asset', $assetId, ['attachment_id' => $attId]);
        flash('Document removed from the asset. The file is retained for audit.', 'info');
    }
}

redirect($back);
