<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $me      = current_user();

    if (!password_verify($current, $me['password_hash'])) {
        $errors[] = 'Your current password is not correct.';
    }
    if (strlen($new) < 10) {
        $errors[] = 'The new password must be at least 10 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'The new password and confirmation do not match.';
    }
    if (!$errors) {
        db_query('UPDATE users SET password_hash = :h, must_change_pw = 0 WHERE id = :id', [
            ':h'  => password_hash($new, PASSWORD_DEFAULT),
            ':id' => $me['id'],
        ]);
        audit('user.password_change', 'user', (int) $me['id']);
        flash('Your password has been changed.');
        redirect('dashboard.php');
    }
}

$pageTitle = 'Change password';
$activeNav = '';
include APP_ROOT . '/templates/header.php';
?>
<div class="row"><div class="col-lg-5">
  <div class="card">
    <div class="card-body">
      <?php if (!empty(current_user()['must_change_pw'])): ?>
        <div class="alert alert-warning py-2">Set your own password before continuing.</div>
      <?php endif; ?>
      <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
      <form method="post" data-guard>
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label required" for="cp">Current password</label>
          <input class="form-control" id="cp" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <div class="mb-3">
          <label class="form-label required" for="np">New password</label>
          <input class="form-control" id="np" name="new_password" type="password" required
                 minlength="10" autocomplete="new-password">
          <div class="form-text">Minimum 10 characters.</div>
        </div>
        <div class="mb-3">
          <label class="form-label required" for="cf">Confirm new password</label>
          <input class="form-control" id="cf" name="confirm_password" type="password" required autocomplete="new-password">
        </div>
        <button class="btn btn-primary" type="submit">Change password</button>
      </form>
    </div>
  </div>
</div></div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
