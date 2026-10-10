<?php
/** User administration: list, activate/deactivate, reset password. Admin only. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) req('action');
    $uid    = req_int('user_id');
    $target = $uid ? db_one('SELECT * FROM users WHERE id = :id', [':id' => $uid]) : null;

    if (!$target) {
        flash('User not found.', 'danger');
        redirect('modules/admin/users.php');
    }
    if ($uid === current_user_id() && in_array($action, ['deactivate', 'demote'], true)) {
        flash('You cannot deactivate or demote your own account.', 'warning');
        redirect('modules/admin/users.php');
    }

    switch ($action) {
        case 'activate':
        case 'deactivate':
            $active = $action === 'activate' ? 1 : 0;
            db_query('UPDATE users SET is_active = :a, updated_by = :u WHERE id = :id',
                [':a' => $active, ':u' => current_user_id(), ':id' => $uid]);
            audit('user.' . $action, 'user', $uid);
            flash(h($target['full_name']) . ' has been ' . ($active ? 'reactivated' : 'deactivated') . '.');
            break;

        case 'reset_password':
            $temp = bin2hex(random_bytes(6));
            db_query('UPDATE users SET password_hash = :h, must_change_pw = 1, failed_logins = 0,
                             locked_until = NULL, updated_by = :u WHERE id = :id',
                [':h' => password_hash($temp, PASSWORD_DEFAULT), ':u' => current_user_id(), ':id' => $uid]);
            audit('user.password_reset', 'user', $uid);
            flash('Temporary password for <strong>' . h($target['full_name']) . '</strong>: <code>'
                . h($temp) . '</code><br>Give this to them directly — they must change it at first sign-in.',
                'warning');
            break;

        case 'unlock':
            db_query('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = :id', [':id' => $uid]);
            audit('user.unlock', 'user', $uid);
            flash('Account unlocked.');
            break;
    }
    redirect('modules/admin/users.php');
}

$users = db_all(
    'SELECT u.*,
            (SELECT COUNT(*) FROM assets a WHERE a.owner_user_id = u.id AND a.status <> "retired") AS owned_assets,
            (SELECT COUNT(*) FROM maintenance_completions c WHERE c.completed_by = u.id) AS completions
       FROM users u ORDER BY u.is_active DESC, u.full_name'
);

$pageTitle   = 'Users';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'Users']];
$pageActions = '<a class="btn btn-primary" href="' . h(url('modules/admin/user_form.php'))
             . '"><i class="bi bi-person-plus"></i> New user</a>';
include APP_ROOT . '/templates/header.php';
?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-admi mb-0">
      <thead><tr>
        <th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th>
        <th class="text-center">Assets owned</th><th class="text-center">PM records</th>
        <th>Last sign-in</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u2):
        $locked = $u2['locked_until'] && strtotime($u2['locked_until']) > time(); ?>
        <tr class="<?= $u2['is_active'] ? '' : 'is-retired' ?>">
          <td class="fw-semibold"><?= h($u2['full_name']) ?>
            <?= $u2['initials'] ? '<span class="text-muted small">(' . h($u2['initials']) . ')</span>' : '' ?>
            <?= $u2['id'] == current_user_id() ? '<span class="badge text-bg-info">you</span>' : '' ?></td>
          <td class="font-monospace small"><?= h($u2['username']) ?></td>
          <td class="small"><?= h($u2['email']) ?></td>
          <td><span class="badge text-bg-<?= $u2['role'] === 'admin' ? 'primary' : 'secondary' ?>">
            <?= h(ucfirst($u2['role'])) ?></span></td>
          <td>
            <?php if (!$u2['is_active']): ?><span class="badge text-bg-dark">Deactivated</span>
            <?php elseif ($locked): ?><span class="badge text-bg-warning">Locked</span>
            <?php elseif ($u2['must_change_pw']): ?><span class="badge text-bg-info">Must reset password</span>
            <?php else: ?><span class="badge text-bg-success">Active</span><?php endif; ?>
          </td>
          <td class="text-center small"><?= (int) $u2['owned_assets'] ?></td>
          <td class="text-center small"><?= (int) $u2['completions'] ?></td>
          <td class="small"><?= h(fmt_datetime($u2['last_login_at'], 'never')) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary"
               href="<?= h(url('modules/admin/user_form.php?id=' . $u2['id'])) ?>"><i class="bi bi-pencil"></i></a>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $u2['id'] ?>">
                  <input type="hidden" name="action" value="reset_password">
                  <button class="dropdown-item px-1"
                    data-confirm="Reset this user's password to a temporary value?">Reset password</button>
                </form></li>
                <?php if ($locked): ?>
                <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $u2['id'] ?>">
                  <input type="hidden" name="action" value="unlock">
                  <button class="dropdown-item px-1">Unlock account</button>
                </form></li>
                <?php endif; ?>
                <?php if ($u2['id'] != current_user_id()): ?>
                <li><form method="post" class="px-2 py-1"><?= csrf_field() ?>
                  <input type="hidden" name="user_id" value="<?= (int) $u2['id'] ?>">
                  <input type="hidden" name="action" value="<?= $u2['is_active'] ? 'deactivate' : 'activate' ?>">
                  <button class="dropdown-item px-1 <?= $u2['is_active'] ? 'text-danger' : '' ?>"
                    data-confirm="<?= $u2['is_active'] ? 'Deactivate this account? Their history is kept.' : 'Reactivate this account?' ?>">
                    <?= $u2['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                </form></li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="small text-muted mt-2">
  Accounts are never deleted — deactivating preserves every PM completion and issue transition
  attributed to that person, which AS9100 traceability requires.
</p>
<?php include APP_ROOT . '/templates/footer.php'; ?>
