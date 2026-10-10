<?php
/** Admin > Settings > System Check. Reuses the same checks as the installer. */
define('DIAGNOSTICS', true);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (req('action') === 'prepare') {
        $prep = health_prepare_uploads();
        if ($prep['created']) {
            flash('Created: ' . h(implode(', ', $prep['created'])));
        }
        if ($prep['failed']) {
            flash('Could not create: ' . h(implode(', ', $prep['failed']))
                . '. Set /uploads to permission 755 in cPanel → File Manager.', 'danger');
        }
        if (!$prep['created'] && !$prep['failed']) {
            flash('Everything was already in place.', 'info');
        }
    }
    redirect('modules/settings/system.php');
}

$env = health_environment_checks();
$db  = health_database_checks();
$all = array_merge($env, $db);
$worst = health_worst($all);
$fails = count(array_filter($all, fn($c) => $c['status'] === 'fail'));
$warns = count(array_filter($all, fn($c) => $c['status'] === 'warn'));

$pageTitle   = 'System Check';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'System check']];
$pageActions = '<a class="btn btn-outline-secondary" href="' . h(url('modules/settings/index.php'))
             . '"><i class="bi bi-sliders"></i> Settings</a>';
include APP_ROOT . '/templates/header.php';

function check_row(array $c): void
{
    $icon = ['ok' => 'bi-check-circle-fill text-success',
             'warn' => 'bi-exclamation-triangle-fill text-warning',
             'fail' => 'bi-x-circle-fill text-danger'][$c['status']];
    echo '<tr><td style="width:2.2rem"><i class="bi ' . $icon . '"></i></td>'
       . '<td class="fw-semibold">' . h($c['label']) . '</td>'
       . '<td class="small text-muted">' . h($c['detail'])
       . ($c['status'] !== 'ok' && $c['fix'] ? '<div class="small text-body-secondary mt-1"><em>'
            . h($c['fix']) . '</em></div>' : '')
       . '</td></tr>';
}
?>
<div class="alert alert-<?= $worst === 'ok' ? 'success' : ($worst === 'warn' ? 'warning' : 'danger') ?>">
  <?php if ($worst === 'ok'): ?>
    <i class="bi bi-check-circle"></i> Everything checks out — <?= count($all) ?> checks passed.
  <?php else: ?>
    <i class="bi bi-exclamation-triangle"></i>
    <?= $fails ?> problem<?= $fails === 1 ? '' : 's' ?> and <?= $warns ?>
    advisory item<?= $warns === 1 ? '' : 's' ?> out of <?= count($all) ?> checks.
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hdd-network"></i> Server environment</span>
        <form method="post" class="d-inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="prepare">
          <button class="btn btn-sm btn-outline-secondary">Repair folders</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-admi mb-0"><tbody>
          <?php foreach ($env as $c) { check_row($c); } ?>
        </tbody></table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-database"></i> Application</div>
      <div class="table-responsive">
        <table class="table table-admi mb-0"><tbody>
          <?php foreach ($db as $c) { check_row($c); } ?>
        </tbody></table>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle"></i> Build information</div>
      <table class="table table-admi mb-0"><tbody>
        <tr><td>Application build</td><td><?= h(defined('APP_BUILD') ? APP_BUILD : 'unknown') ?></td></tr>
        <tr><td>Schema version</td><td><?= h(setting('schema_version', '1.0.0')) ?></td></tr>
        <tr><td>Installed</td><td><?= h(fmt_datetime(setting('installed_at'), 'unknown')) ?></td></tr>
        <tr><td>Application URL</td><td class="small font-monospace"><?= h(BASE_URL) ?></td></tr>
        <tr><td>Application path</td><td class="small font-monospace"><?= h(APP_ROOT) ?></td></tr>
        <tr><td>Time zone</td><td><?= h(date_default_timezone_get()) ?>
            &middot; <?= h(fmt_datetime(date('Y-m-d H:i:s'))) ?></td></tr>
        <tr><td>Server software</td><td class="small"><?= h($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') ?></td></tr>
      </tbody></table>
    </div>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
