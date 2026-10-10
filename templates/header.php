<?php
/**
 * Page chrome. Set before including:
 *   $pageTitle   string
 *   $activeNav   string  (dashboard|assets|maintenance|issues|reports|admin)
 *   $breadcrumbs array   [['label'=>'', 'url'=>''], ...]
 *   $pageActions string   raw HTML for the top-right button group
 */
$pageTitle   = $pageTitle   ?? APP_NAME;
$activeNav   = $activeNav   ?? '';
$breadcrumbs = $breadcrumbs ?? [];
$pageActions = $pageActions ?? '';
$u           = current_user();
$logo        = setting('logo_path', '');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($pageTitle) ?> &middot; <?= h(setting('company_short_name', 'ADMI')) ?> Maintenance</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= h(url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg admi-navbar sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(url('dashboard.php')) ?>">
      <?php if ($logo): ?>
        <img src="<?= h(upload_url($logo)) ?>" alt="" class="admi-logo">
      <?php else: ?>
        <span class="admi-mark"><?= h(setting('company_short_name', 'ADMI')) ?></span>
      <?php endif; ?>
      <span class="brand-text">Maintenance</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainnav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainnav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>"
            href="<?= h(url('dashboard.php')) ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'assets' ? 'active' : '' ?>"
            href="<?= h(url('modules/assets/index.php')) ?>"><i class="bi bi-hdd-stack"></i> Assets</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'maintenance' ? 'active' : '' ?>"
            href="<?= h(url('modules/maintenance/index.php')) ?>"><i class="bi bi-clipboard-check"></i> Maintenance</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'run' ? 'active' : '' ?>"
            href="<?= h(url('modules/maintenance/run.php')) ?>"><i class="bi bi-check2-square"></i> Run Checks</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'issues' ? 'active' : '' ?>"
            href="<?= h(url('modules/issues/index.php')) ?>"><i class="bi bi-exclamation-triangle"></i> Issues</a></li>
        <li class="nav-item"><a class="nav-link <?= $activeNav === 'reports' ? 'active' : '' ?>"
            href="<?= h(url('modules/reports/index.php')) ?>"><i class="bi bi-file-earmark-pdf"></i> Reports</a></li>
      </ul>
      <ul class="navbar-nav">
        <?php if (is_admin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= $activeNav === 'admin' ? 'active' : '' ?>" href="#"
             data-bs-toggle="dropdown"><i class="bi bi-gear"></i> Admin</a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="<?= h(url('modules/admin/users.php')) ?>">
                <i class="bi bi-people"></i> Users</a></li>
            <li><a class="dropdown-item" href="<?= h(url('modules/settings/index.php')) ?>">
                <i class="bi bi-sliders"></i> Settings &amp; Branding</a></li>
            <li><a class="dropdown-item" href="<?= h(url('modules/settings/system.php')) ?>">
                <i class="bi bi-activity"></i> System Check</a></li>
            <li><a class="dropdown-item" href="<?= h(url('modules/admin/notifications.php')) ?>">
                <i class="bi bi-envelope-paper"></i> Notifications</a></li>
            <li><a class="dropdown-item" href="<?= h(url('modules/settings/update.php')) ?>">
                <i class="bi bi-cloud-arrow-up"></i> Updates</a></li>
            <li><a class="dropdown-item" href="<?= h(url('modules/admin/audit.php')) ?>">
                <i class="bi bi-shield-check"></i> Audit Trail</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
            <i class="bi bi-person-circle"></i> <?= h($u['full_name'] ?? 'Account') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><h6 class="dropdown-header"><?= h(ucfirst($u['role'] ?? '')) ?></h6></li>
            <li><a class="dropdown-item" href="<?= h(url('password.php')) ?>">
                <i class="bi bi-key"></i> Change password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= h(url('logout.php')) ?>">
                <i class="bi bi-box-arrow-right"></i> Sign out</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container-fluid py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <?php if ($breadcrumbs): ?>
      <nav aria-label="breadcrumb"><ol class="breadcrumb mb-1">
        <?php foreach ($breadcrumbs as $i => $b): $last = $i === count($breadcrumbs) - 1; ?>
          <li class="breadcrumb-item <?= $last ? 'active' : '' ?>">
            <?php if (!$last && !empty($b['url'])): ?>
              <a href="<?= h(url($b['url'])) ?>"><?= h($b['label']) ?></a>
            <?php else: ?>
              <?= h($b['label']) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol></nav>
      <?php endif; ?>
      <h1 class="page-title mb-0"><?= h($pageTitle) ?></h1>
    </div>
    <div class="d-flex gap-2 flex-wrap"><?= $pageActions ?></div>
  </div>

  <?php if (function_exists('setup_mode') && setup_mode() && is_admin()): ?>
    <div class="alert alert-warning setup-banner d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
      <span>
        <i class="bi bi-tools"></i>
        <strong>Setup mode.</strong> Maintenance tasks, machines and sign-off records can be
        deleted outright while you dial the system in.
      </span>
      <a class="btn btn-sm btn-outline-dark" href="<?= h(url('modules/settings/index.php?tab=general')) ?>">
        Lock the record when you are done
      </a>
    </div>
  <?php endif; ?>

  <?php if (function_exists('due_before_go_live') && due_before_go_live()): ?>
    <div class="alert alert-info go-live-banner d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
      <span>
        <i class="bi bi-calendar-check"></i>
        <strong>Scheduled to go live <?= h(fmt_date(due_schedule_start())) ?>.</strong>
        Nothing is due before then, so the due list stays empty until the floor starts using it.
      </span>
      <?php if (is_admin()): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(url('modules/settings/index.php?tab=schedule')) ?>">
          Change the date
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php foreach (flash_take() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show" role="alert">
      <?= $f['message'] ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>
