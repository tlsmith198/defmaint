<?php
/** Create / edit a user account. Admin only. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$id     = req_int('id');
$user   = $id ? db_one('SELECT * FROM users WHERE id = :id', [':id' => $id]) : null;
$isEdit = $user !== null;
if ($id && !$user) {
    flash('User not found.', 'danger');
    redirect('modules/admin/users.php');
}

$errors = [];
$form = $isEdit ? $user : [
    'username' => '', 'email' => '', 'full_name' => '', 'initials' => '',
    'role' => 'user', 'job_title' => '', 'phone' => '', 'is_active' => 1,
];
$tempPassword = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $form = array_merge($form, pick($_POST, ['username', 'email', 'full_name', 'initials', 'role', 'job_title', 'phone']));
    foreach ($form as $k => $v) {
        if (is_string($v)) $form[$k] = trim($v);
    }
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $password = (string) ($_POST['password'] ?? '');

    if ($form['full_name'] === '')                       $errors['full_name'] = 'Full name is required.';
    if ($form['username'] === '')                        $errors['username']  = 'Username is required.';
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors['email']  = 'Enter a valid email address.';
    if (!in_array($form['role'], ['admin', 'user'], true))  $errors['role']   = 'Choose a role.';

    if (db_value('SELECT id FROM users WHERE username = :u AND id <> :id',
        [':u' => $form['username'], ':id' => $id ?: 0])) {
        $errors['username'] = 'That username is already taken.';
    }
    if (db_value('SELECT id FROM users WHERE email = :e AND id <> :id',
        [':e' => $form['email'], ':id' => $id ?: 0])) {
        $errors['email'] = 'That email address is already registered.';
    }
    if (!$isEdit && $password !== '' && strlen($password) < 10) {
        $errors['password'] = 'Password must be at least 10 characters.';
    }
    if ($isEdit && $id === current_user_id() && $form['role'] !== 'admin') {
        $errors['role'] = 'You cannot remove your own administrator access.';
    }
    if ($isEdit && $user['role'] === 'admin' && $form['role'] !== 'admin'
        && (int) db_value('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1', [], 0) <= 1) {
        $errors['role'] = 'At least one active administrator must remain.';
    }

    if (!$errors) {
        $data = [
            'username'  => $form['username'],
            'email'     => $form['email'],
            'full_name' => $form['full_name'],
            'initials'  => nn(strtoupper((string) $form['initials'])),
            'role'      => $form['role'],
            'job_title' => nn($form['job_title']),
            'phone'     => nn($form['phone']),
            'is_active' => $form['is_active'],
            'updated_by'=> current_user_id(),
        ];
        if ($isEdit) {
            db_update('users', $data, $id);
            audit('user.update', 'user', $id, diff_fields($user, $data));
            flash('Saved changes to <strong>' . h($data['full_name']) . '</strong>.');
            redirect('modules/admin/users.php');
        } else {
            if ($password === '') {
                $password = bin2hex(random_bytes(6));
                $tempPassword = $password;
            }
            $data['password_hash']  = password_hash($password, PASSWORD_DEFAULT);
            $data['must_change_pw'] = 1;
            $data['created_by']     = current_user_id();
            $newId = db_insert('users', $data);
            audit('user.create', 'user', $newId, ['username' => $data['username'], 'role' => $data['role']]);
            flash('Created <strong>' . h($data['full_name']) . '</strong>. Temporary password: <code>'
                . h($password) . '</code><br>They must change it at first sign-in.', 'warning');
            redirect('modules/admin/users.php');
        }
    }
}

$pageTitle   = $isEdit ? 'Edit ' . $user['full_name'] : 'New user';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'Users', 'url' => 'modules/admin/users.php'],
                ['label' => $isEdit ? $user['username'] : 'New']];
include APP_ROOT . '/templates/header.php';
?>
<div class="row"><div class="col-lg-7">
  <form method="post" data-guard>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="card">
      <div class="card-body">
        <?php if ($errors): ?><div class="alert alert-danger">Please correct the highlighted fields.</div><?php endif; ?>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label required" for="full_name">Full name</label>
            <input class="form-control<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                   id="full_name" name="full_name" value="<?= h($form['full_name']) ?>" required>
            <?php if (isset($errors['full_name'])): ?><div class="invalid-feedback"><?= h($errors['full_name']) ?></div><?php endif; ?>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="initials">Initials</label>
            <input class="form-control text-uppercase" id="initials" name="initials"
                   maxlength="8" value="<?= h($form['initials']) ?>">
            <div class="form-text">Shown on printed logs.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label required" for="role">Role</label>
            <select class="form-select<?= isset($errors['role']) ? ' is-invalid' : '' ?>" id="role" name="role">
              <option value="user"  <?= $form['role'] === 'user'  ? 'selected' : '' ?>>User</option>
              <option value="admin" <?= $form['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
            </select>
            <?php if (isset($errors['role'])): ?><div class="invalid-feedback"><?= h($errors['role']) ?></div><?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label required" for="username">Username</label>
            <input class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>"
                   id="username" name="username" value="<?= h($form['username']) ?>" required autocomplete="off">
            <?php if (isset($errors['username'])): ?><div class="invalid-feedback"><?= h($errors['username']) ?></div><?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label required" for="email">Email</label>
            <input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" type="email"
                   id="email" name="email" value="<?= h($form['email']) ?>" required>
            <?php if (isset($errors['email'])): ?><div class="invalid-feedback"><?= h($errors['email']) ?></div><?php endif; ?>
            <div class="form-text">Notifications for owned assets go here.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="job_title">Job title</label>
            <input class="form-control" id="job_title" name="job_title" value="<?= h($form['job_title']) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" id="phone" name="phone" value="<?= h($form['phone']) ?>">
          </div>
          <?php if (!$isEdit): ?>
          <div class="col-md-6">
            <label class="form-label" for="password">Initial password</label>
            <input class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>" type="text"
                   id="password" name="password" autocomplete="off" placeholder="Leave blank to auto-generate">
            <?php if (isset($errors['password'])): ?><div class="invalid-feedback"><?= h($errors['password']) ?></div><?php endif; ?>
            <div class="form-text">The user is required to change it at first sign-in.</div>
          </div>
          <?php endif; ?>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                     <?= $form['is_active'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_active">Account is active</label>
            </div>
          </div>
        </div>
      </div>
      <div class="card-footer d-flex gap-2">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a class="btn btn-outline-secondary" href="<?= h(url('modules/admin/users.php')) ?>">Cancel</a>
      </div>
    </div>
  </form>
</div></div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
