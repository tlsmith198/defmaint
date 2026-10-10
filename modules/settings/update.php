<?php
/**
 * Admin > Settings > Updates.
 * Upload the latest release zip, review what will change, install it, roll back if needed.
 */
define('DIAGNOSTICS', true);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once APP_ROOT . '/includes/updater.php';
require_admin();

$self = 'modules/settings/update.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // An oversized upload arrives with an empty $_POST, which would otherwise look like a bad token.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('The zip is bigger than this server accepts (post_max_size ' . h(ini_get('post_max_size'))
            . '). Raise it in cPanel → Select PHP Version → Options.', 'danger');
        redirect($self);
    }
    require_post_csrf();
    $action = (string) req('action', '');

    if ($action === 'upload') {
        [$err] = updater_stage($_FILES['package'] ?? []);
        if ($err) {
            flash(h($err), 'danger');
        } else {
            audit('update.staged', 'system', null, ['file' => $_FILES['package']['name'] ?? '']);
        }
        redirect($self);
    }

    if ($action === 'use_inbox') {
        [$err] = updater_stage_inbox((string) req('inbox_file', ''));
        if ($err) {
            flash(h($err), 'danger');
        } else {
            audit('update.staged', 'system', null, ['file' => (string) req('inbox_file', ''), 'via' => 'inbox']);
        }
        redirect($self);
    }

    if ($action === 'cancel') {
        updater_clear_pending();
        flash('Update discarded.', 'info');
        redirect($self);
    }

    if ($action === 'install') {
        $meta = updater_pending();
        $older = $meta && updater_build_date($meta['build']) > 0
              && updater_build_date($meta['build']) < updater_build_date(APP_BUILD);
        if ($older && req('confirm_older') !== '1') {
            flash('That package is older than the installed version. Tick the box to confirm you want to downgrade.', 'warning');
            redirect($self);
        }
        [$ok, $msg] = updater_apply();
        flash(h($msg), $ok ? 'success' : 'danger');
        redirect($self);
    }

    if ($action === 'rollback') {
        [$ok, $msg] = updater_rollback((string) req('backup', ''));
        flash(h($msg), $ok ? 'success' : 'danger');
        redirect($self);
    }

    if ($action === 'delete_backup') {
        updater_delete_backup((string) req('backup', ''));
        flash('Backup deleted.', 'info');
        redirect($self);
    }
    redirect($self);
}

$zipOk   = UpdZip::available();
$inbox   = $zipOk ? updater_inbox() : [];
$engine  = class_exists('ZipArchive') ? 'PHP zip extension' : 'built-in reader (zip extension not needed)';
$limit   = min(updater_ini_bytes(ini_get('upload_max_filesize')), updater_ini_bytes(ini_get('post_max_size')));
$tmpOk   = is_dir(updater_dir()) && is_writable(updater_dir());
$meta    = $zipOk ? updater_pending() : null;
$plan    = $meta ? updater_plan() : null;
$backups = $zipOk ? updater_backups() : [];
$lastUpd = db_one("SELECT created_at, detail FROM audit_log WHERE action = 'update.applied' ORDER BY id DESC LIMIT 1");
$older   = $meta && updater_build_date($meta['build']) > 0
        && updater_build_date($meta['build']) < updater_build_date(APP_BUILD);

$pageTitle   = 'Updates';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'Updates']];
$pageActions = '<a class="btn btn-outline-secondary" href="' . h(url('modules/settings/index.php'))
             . '"><i class="bi bi-sliders"></i> Settings</a>';
include APP_ROOT . '/templates/header.php';

function upd_list(array $items, int $max = 60): void
{
    echo '<ul class="small mb-0" style="max-height:14rem;overflow:auto">';
    foreach (array_slice($items, 0, $max) as $i) {
        echo '<li><code>' . h($i) . '</code></li>';
    }
    if (count($items) > $max) {
        echo '<li class="text-muted">… and ' . (count($items) - $max) . ' more</li>';
    }
    echo '</ul>';
}
?>
<?php if (!$zipOk): ?>
  <div class="alert alert-danger">
    <i class="bi bi-x-circle"></i> This server has neither the PHP <strong>zip</strong> extension nor <strong>zlib</strong>,
    so zips cannot be read here. Use the manual steps in <code>docs/UPDATE.md</code>.
  </div>
<?php endif; ?>
<?php if (!$tmpOk): ?>
  <div class="alert alert-danger">
    <i class="bi bi-x-circle"></i> The <code>/tmp</code> folder inside the app is not writable, so updates cannot be staged.
    cPanel → File Manager → right-click <code>tmp</code> → Change Permissions → 755.
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-info-circle"></i> Installed version</div>
      <div class="card-body">
        <div class="fw-semibold"><?= h(APP_BUILD) ?></div>
        <div class="small text-muted mt-1">Zip engine: <?= h($engine) ?> · max web upload: <?= h(round($limit / 1048576, 1)) ?> MB</div>
        <div class="small text-muted mt-1">
          <?php if ($lastUpd): ?>Last in-app update: <?= h(fmt_datetime($lastUpd['created_at'])) ?>
          <?php else: ?>No in-app update has been run yet.<?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-cloud-upload"></i> Upload an update</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload">
          <label class="form-label">Release zip</label>
          <input type="file" name="package" accept=".zip" class="form-control" required <?= $zipOk ? '' : 'disabled' ?>>
          <div class="form-text">
            Upload the same <code>ADMI_Maintenance_System.zip</code> you would have extracted by hand.
            Nothing is installed yet - you get a review screen first.
          </div>
          <button class="btn btn-primary mt-3" <?= $zipOk ? '' : 'disabled' ?>>
            <i class="bi bi-search"></i> Upload &amp; review
          </button>
        </form>
        <hr>
        <div class="fw-semibold small mb-1"><i class="bi bi-folder2-open"></i> Or use the inbox folder</div>
        <div class="small text-muted mb-2">
          If the upload above is refused (some hosts' firewalls block it), put the zip in
          <code>/tmp/updates/inbox/</code> with cPanel → File Manager → Upload, then pick it here.
          This route has no size limit and needs no server settings changed.
        </div>
        <?php if ($inbox): ?>
          <form method="post" class="d-flex gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="use_inbox">
            <select name="inbox_file" class="form-select form-select-sm">
              <?php foreach ($inbox as $f): ?>
                <option value="<?= h($f['name']) ?>"><?= h($f['name']) ?> (<?= h(round($f['size'] / 1024)) ?> KB, <?= h(fmt_datetime($f['at'])) ?>)</option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary text-nowrap">Review</button>
          </form>
        <?php else: ?>
          <div class="small text-body-secondary fst-italic">Nothing in the inbox right now.</div>
        <?php endif; ?>
        <hr>
        <div class="small text-muted">
          <strong>Always kept safe:</strong> your <code>config.php</code> (database login), everything in
          <code>/uploads</code> (photos, documents, logo, completion evidence) and <code>install.php</code>.
          Your data is never touched unless the update includes a database change, which is listed on the
          review screen first. Files are never deleted by an update.
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($meta && $plan && !$plan['error']): ?>
      <div class="card border-primary mb-3">
        <div class="card-header bg-primary text-white"><i class="bi bi-clipboard-check"></i> Review before installing</div>
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-6"><div class="small text-muted">Installed</div><div class="fw-semibold"><?= h(APP_BUILD) ?></div></div>
            <div class="col-6"><div class="small text-muted">In the zip (<?= h($meta['name']) ?>)</div>
              <div class="fw-semibold"><?= h($meta['build']) ?></div></div>
          </div>

          <?php if ($older): ?>
            <div class="alert alert-warning">
              <i class="bi bi-exclamation-triangle"></i> This package is <strong>older</strong> than the installed version.
            </div>
          <?php endif; ?>

          <div class="d-flex gap-2 flex-wrap mb-3">
            <span class="badge text-bg-warning"><?= count($plan['changed']) ?> changed</span>
            <span class="badge text-bg-success"><?= count($plan['new']) ?> new</span>
            <span class="badge text-bg-secondary"><?= (int) $plan['same'] ?> unchanged</span>
            <?php if ($plan['skipped']): ?><span class="badge text-bg-light border"><?= count($plan['skipped']) ?> protected / skipped</span><?php endif; ?>
            <?php if ($plan['migrations']): ?><span class="badge text-bg-danger"><?= count($plan['migrations']) ?> database change(s)</span><?php endif; ?>
          </div>

          <?php if ($plan['migrations']): ?>
            <div class="alert alert-info small">
              <i class="bi bi-database"></i> This update changes the database. Take a phpMyAdmin export first if you have
              not recently. Files can be rolled back; database changes cannot.
              <?php upd_list($plan['migrations']); ?>
            </div>
          <?php endif; ?>

          <?php if ($plan['changed']): ?>
            <details class="mb-2"><summary class="fw-semibold">Changed files</summary><?php upd_list($plan['changed']); ?></details>
          <?php endif; ?>
          <?php if ($plan['new']): ?>
            <details class="mb-2"><summary class="fw-semibold">New files</summary><?php upd_list($plan['new']); ?></details>
          <?php endif; ?>
          <?php if ($plan['skipped']): ?>
            <details class="mb-2"><summary class="fw-semibold">Skipped (never overwritten)</summary><?php upd_list($plan['skipped']); ?></details>
          <?php endif; ?>

          <?php if (!$plan['changed'] && !$plan['new']): ?>
            <div class="alert alert-secondary mb-0">This zip is identical to what is installed. Nothing to do.</div>
          <?php endif; ?>

          <form method="post" class="mt-3 d-flex gap-2 align-items-center flex-wrap">
            <?= csrf_field() ?>
            <?php if ($older): ?>
              <div class="form-check me-2">
                <input class="form-check-input" type="checkbox" name="confirm_older" value="1" id="co">
                <label class="form-check-label" for="co">Yes, install the older version</label>
              </div>
            <?php endif; ?>
            <?php if ($plan['changed'] || $plan['new']): ?>
              <button class="btn btn-success" name="action" value="install"
                      onclick="return confirm('Install this update now? A backup is taken first.')">
                <i class="bi bi-rocket-takeoff"></i> Install update
              </button>
            <?php endif; ?>
            <button class="btn btn-outline-secondary" name="action" value="cancel">Discard</button>
          </form>
        </div>
      </div>
    <?php elseif ($plan && $plan['error']): ?>
      <div class="alert alert-danger"><?= h($plan['error']) ?></div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> Backups &amp; rollback</div>
      <?php if (!$backups): ?>
        <div class="card-body text-muted small">
          Each install first saves a copy of every file it replaces. They appear here, and the newest
          <?= (int) UPDATER_KEEP_BACKUPS ?> are kept.
        </div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead><tr><th>When</th><th>Change</th><th class="text-end">Files</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $b): ?>
              <tr>
                <td class="small"><?= h(fmt_datetime($b['at'])) ?></td>
                <td class="small"><?= h($b['from'] ?: '?') ?> → <?= h($b['to'] ?: '?') ?></td>
                <td class="text-end small"><?= (int) $b['files'] ?></td>
                <td class="text-end text-nowrap">
                  <form method="post" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="backup" value="<?= h($b['file']) ?>">
                    <button class="btn btn-sm btn-outline-warning" name="action" value="rollback"
                            onclick="return confirm('Restore the files from this backup? Database changes are not undone.')">Roll back</button>
                    <button class="btn btn-sm btn-outline-danger" name="action" value="delete_backup"
                            onclick="return confirm('Delete this backup?')"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
