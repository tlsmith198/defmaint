<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['_csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        [$ok, $error] = auth_attempt((string) req('username'), (string) ($_POST['password'] ?? ''));
        if ($ok) {
            $to = $_SESSION['redirect_after_login'] ?? null;
            unset($_SESSION['redirect_after_login']);
            redirect($to ?: 'dashboard.php');
        }
    }
}
$logo = setting('logo_path', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= h(setting('company_short_name', 'ADMI')) ?> Maintenance</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= h(url('assets/css/app.css')) ?>" rel="stylesheet">
<style>
  body { display:flex; align-items:center; min-height:100vh; background:var(--admi-navy); }
  .login-card { max-width: 420px; width: 100%; margin: auto; }
</style>
</head>
<body>
<div class="login-card px-3">
  <div class="text-center mb-3">
    <?php if ($logo): ?>
      <img src="<?= h(upload_url($logo)) ?>" alt="" style="max-height:64px;background:#fff;padding:6px 10px;border-radius:4px">
    <?php else: ?>
      <div class="h3 text-white mb-0"><?= h(setting('company_name', 'Aerospace & Defense Manufacturing Inc.')) ?></div>
    <?php endif; ?>
    <div class="text-white-50 mt-2">Equipment, Asset &amp; Maintenance Management</div>
  </div>
  <div class="card">
    <div class="card-body p-4">
      <h1 class="h5 mb-3">Sign in</h1>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>
      <?php foreach (flash_take() as $f): ?>
        <div class="alert alert-<?= h($f['type']) ?> py-2"><?= $f['message'] ?></div>
      <?php endforeach; ?>
      <form method="post" data-guard>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="username">Username or email</label>
          <input class="form-control" id="username" name="username" autocomplete="username"
                 value="<?= h(req('username', '')) ?>" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input class="form-control" id="password" name="password" type="password"
                 autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
      </form>
    </div>
  </div>
  <p class="text-center text-white-50 small mt-3 mb-0">
    CAGE <?= h(setting('cage_code', '97WZ5')) ?> &middot; Authorized users only
  </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= h(url('assets/js/app.js')) ?>"></script>
</body>
</html>
