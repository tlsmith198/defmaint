<?php $pageTitle = 'Access denied'; $activeNav = ''; include APP_ROOT . '/templates/header.php'; ?>
<div class="card border-danger">
  <div class="card-body text-center py-5">
    <i class="bi bi-shield-lock display-4 text-danger"></i>
    <h2 class="mt-3">Administrator access required</h2>
    <p class="text-muted mb-4">
      Your account (<?= h(current_user_name()) ?>) does not have permission for this page.<br>
      Maintenance requirement definitions, user accounts and notification settings are admin-only.
    </p>
    <a class="btn btn-primary" href="<?= h(url('dashboard.php')) ?>">Back to dashboard</a>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
