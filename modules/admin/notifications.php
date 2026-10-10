<?php
/**
 * Admin > Notifications: delivery log, recipient rules, message templates.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$tab  = (string) req('tab', 'log');
$self = 'modules/admin/notifications.php';
$kinds = ['email' => 'Fixed email address', 'user' => 'Specific user', 'role' => 'Everyone with role',
          'asset_owner' => 'Asset owner', 'issue_reporter' => 'Issue reporter', 'assignee' => 'Issue assignee'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) req('action', '');

    if ($action === 'retry') {
        $id = req_int('id');
        db_query("UPDATE notification_log SET status='queued', attempts=0, error_message=NULL WHERE id=:id AND status='failed'", [':id' => $id]);
        if (!setting_bool('email_enabled')) {
            flash('Re-queued, but email is switched off in Settings → Email, so nothing will be sent yet.', 'warning');
        } elseif (notify_deliver($id)) {
            flash('Sent.');
        } else {
            flash('Still failing: ' . h((string) db_value('SELECT error_message FROM notification_log WHERE id = :id', [':id' => $id])), 'danger');
        }
        redirect($self . '?tab=log');
    }
    if ($action === 'flush') {
        $n = notify_flush(50);
        flash($n . ' queued message' . ($n === 1 ? '' : 's') . ' sent.', 'info');
        redirect($self . '?tab=log');
    }
    if ($action === 'rule_add') {
        $type = (string) req('type_key', '');
        $kind = (string) req('recipient_kind', 'email');
        $val  = trim((string) req('recipient_value', ''));
        $as   = in_array(req('send_as'), ['to', 'cc', 'bcc'], true) ? req('send_as') : 'to';
        $sev  = in_array(req('min_severity'), ['minor', 'major', 'down'], true) ? req('min_severity') : null;
        $ok = db_value('SELECT id FROM notification_templates WHERE type_key = :t', [':t' => $type])
           && isset($kinds[$kind]);
        if ($ok && $kind === 'email') {
            $ok = (bool) filter_var($val, FILTER_VALIDATE_EMAIL);
        }
        if ($ok && in_array($kind, ['asset_owner', 'issue_reporter', 'assignee'], true)) {
            $val = '';
        }
        if ($ok && in_array($kind, ['user', 'role'], true) && $val === '') {
            $ok = false;
        }
        if ($ok) {
            $id = db_insert('notification_recipients', ['type_key' => $type, 'recipient_kind' => $kind,
                'recipient_value' => nn($val), 'send_as' => $as, 'min_severity' => $sev, 'is_active' => 1]);
            audit('notify.rule_add', 'notification_rule', $id, ['type' => $type, 'kind' => $kind]);
            flash('Recipient rule added.');
        } else {
            flash('That rule is incomplete or the email address is not valid.', 'danger');
        }
        redirect($self . '?tab=rules');
    }
    if ($action === 'rule_delete') {
        $id = req_int('id');
        db_query('DELETE FROM notification_recipients WHERE id = :id', [':id' => $id]);
        audit('notify.rule_delete', 'notification_rule', $id);
        flash('Rule removed.', 'info');
        redirect($self . '?tab=rules');
    }
    if ($action === 'rule_toggle') {
        db_query('UPDATE notification_recipients SET is_active = 1 - is_active WHERE id = :id', [':id' => req_int('id')]);
        redirect($self . '?tab=rules');
    }
    if ($action === 'template_save') {
        $id = req_int('id');
        $subject = trim((string) req('subject', ''));
        $body = (string) req('body_html', '');
        if ($subject === '' || trim($body) === '') {
            flash('Subject and body cannot be empty.', 'danger');
        } else {
            db_query('UPDATE notification_templates SET subject = :s, body_html = :b, is_active = :a, updated_by = :u WHERE id = :id',
                [':s' => $subject, ':b' => $body, ':a' => req('is_active') ? 1 : 0, ':u' => current_user_id(), ':id' => $id]);
            audit('notify.template_save', 'notification_template', $id);
            flash('Template saved.');
        }
        redirect($self . '?tab=templates');
    }
    redirect($self);
}

$pageTitle   = 'Notifications';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'Notifications']];
$pageActions = '<a class="btn btn-outline-secondary" href="' . h(url('modules/settings/index.php?tab=email'))
             . '"><i class="bi bi-gear"></i> Email settings</a>';
include APP_ROOT . '/templates/header.php';

function nt_tab(string $k, string $label, string $cur): void
{
    printf('<li class="nav-item"><a class="nav-link %s" href="%s">%s</a></li>', $k === $cur ? 'active' : '',
        h(url('modules/admin/notifications.php?tab=' . $k)), h($label));
}
$emailOn = setting_bool('email_enabled');
?>
<?php if (!$emailOn): ?>
  <div class="alert alert-warning">
    <i class="bi bi-envelope-slash"></i> Email is <strong>switched off</strong>. Notifications are still recorded below;
    turn email on in <a href="<?= h(url('modules/settings/index.php?tab=email')) ?>">Settings → Email</a> after the test message arrives.
  </div>
<?php endif; ?>
<ul class="nav nav-tabs mb-3">
  <?php nt_tab('log', 'Delivery log', $tab); nt_tab('rules', 'Who gets what', $tab); nt_tab('templates', 'Message templates', $tab); ?>
</ul>

<?php if ($tab === 'rules'): ?>
  <?php
  $types = db_all('SELECT type_key, label FROM notification_templates ORDER BY id');
  $rules = db_all('SELECT * FROM notification_recipients ORDER BY type_key, id');
  $byType = [];
  foreach ($rules as $r) { $byType[$r['type_key']][] = $r; }
  $userNames = [];
  foreach (db_all('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name') as $u) { $userNames[$u['id']] = $u['full_name']; }
  ?>
  <div class="row g-3">
    <div class="col-lg-8">
      <?php foreach ($types as $t): ?>
        <div class="card mb-3">
          <div class="card-header fw-semibold"><?= h($t['label']) ?> <span class="text-muted small"><?= h($t['type_key']) ?></span></div>
          <ul class="list-group list-group-flush">
            <?php foreach ($byType[$t['type_key']] ?? [] as $r): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center gap-2 <?= $r['is_active'] ? '' : 'text-muted' ?>">
                <div class="small">
                  <span class="badge text-bg-secondary text-uppercase"><?= h($r['send_as']) ?></span>
                  <?= h($kinds[$r['recipient_kind']] ?? $r['recipient_kind']) ?>
                  <?php if ($r['recipient_kind'] === 'user'): ?><strong><?= h($userNames[(int) $r['recipient_value']] ?? '#' . $r['recipient_value']) ?></strong>
                  <?php elseif ($r['recipient_value']): ?><strong><?= h($r['recipient_value']) ?></strong><?php endif; ?>
                  <?php if ($r['min_severity']): ?><span class="badge text-bg-light border">≥ <?= h($r['min_severity']) ?></span><?php endif; ?>
                </div>
                <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" name="action" value="rule_toggle"><?= $r['is_active'] ? 'Pause' : 'Resume' ?></button>
                  <button class="btn btn-sm btn-outline-danger" name="action" value="rule_delete" data-confirm="Remove this recipient rule?"><i class="bi bi-trash"></i></button>
                </form>
              </li>
            <?php endforeach; ?>
            <?php if (empty($byType[$t['type_key']])): ?><li class="list-group-item small text-muted">No recipients — this notification will not be sent.</li><?php endif; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">Add a recipient rule</div>
        <form method="post" class="card-body">
          <?= csrf_field() ?><input type="hidden" name="action" value="rule_add">
          <label class="form-label">Notification</label>
          <select class="form-select mb-2" name="type_key"><?php foreach ($types as $t): ?><option value="<?= h($t['type_key']) ?>"><?= h($t['label']) ?></option><?php endforeach; ?></select>
          <label class="form-label">Send to</label>
          <select class="form-select mb-2" name="recipient_kind" id="rk"><?php foreach ($kinds as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?></select>
          <div id="rvEmail" class="mb-2"><input class="form-control" name="recipient_value" placeholder="name@company.com"></div>
          <div id="rvUser" class="mb-2 d-none"><select class="form-select" disabled data-name="recipient_value"><?php foreach ($userNames as $id => $n): ?><option value="<?= (int) $id ?>"><?= h($n) ?></option><?php endforeach; ?></select></div>
          <div id="rvRole" class="mb-2 d-none"><select class="form-select" disabled data-name="recipient_value"><option value="admin">Admins</option><option value="user">Users</option></select></div>
          <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label">As</label><select class="form-select" name="send_as"><option>to</option><option>cc</option><option>bcc</option></select></div>
            <div class="col-6"><label class="form-label">Issues at least</label><select class="form-select" name="min_severity"><option value="">any</option><option value="minor">minor</option><option value="major">major</option><option value="down">down</option></select></div>
          </div>
          <button class="btn btn-primary w-100">Add rule</button>
        </form>
      </div>
    </div>
  </div>
  <script>
  (function(){var k=document.getElementById('rk'),m={email:'rvEmail',user:'rvUser',role:'rvRole'};
  function f(){['rvEmail','rvUser','rvRole'].forEach(function(id){var box=document.getElementById(id),on=m[k.value]===id;
    box.classList.toggle('d-none',!on);var el=box.querySelector('input,select');
    if(el){if(el.tagName==='SELECT'){el.disabled=!on;if(on)el.name='recipient_value';else el.removeAttribute('name');}else{el.disabled=!on;}}});}
  k.addEventListener('change',f);f();})();
  </script>

<?php elseif ($tab === 'templates'): ?>
  <div class="row g-3">
    <div class="col-lg-9">
      <?php foreach (db_all('SELECT * FROM notification_templates ORDER BY id') as $t): ?>
        <details class="card mb-3" <?= (int) req('open') === (int) $t['id'] ? 'open' : '' ?>>
          <summary class="card-header fw-semibold"><?= h($t['label']) ?>
            <span class="badge text-bg-<?= $t['is_active'] ? 'success' : 'secondary' ?>"><?= $t['is_active'] ? 'on' : 'off' ?></span>
            <span class="text-muted small fw-normal ms-1"><?= h($t['description']) ?></span></summary>
          <form method="post" class="card-body">
            <?= csrf_field() ?><input type="hidden" name="action" value="template_save"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
            <label class="form-label">Subject</label>
            <input class="form-control mb-2" name="subject" value="<?= h($t['subject']) ?>">
            <label class="form-label">Body (HTML)</label>
            <textarea class="form-control font-monospace mb-2" name="body_html" rows="6"><?= h($t['body_html']) ?></textarea>
            <div class="d-flex justify-content-between align-items-center">
              <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ta<?= (int) $t['id'] ?>" <?= $t['is_active'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="ta<?= (int) $t['id'] ?>">Send this notification</label></div>
              <button class="btn btn-primary">Save template</button>
            </div>
          </form>
        </details>
      <?php endforeach; ?>
    </div>
    <div class="col-lg-3">
      <div class="card"><div class="card-header">Variables</div><div class="card-body small">
        <p class="text-muted">Use <code>{{name}}</code> in the subject or body.</p>
        <?php foreach (notify_variable_help() as $grp => $vars): ?>
          <div class="fw-semibold mt-2"><?= h($grp) ?></div>
          <?php foreach ($vars as $v): ?><code class="d-block">{{<?= h($v) ?>}}</code><?php endforeach; ?>
        <?php endforeach; ?>
      </div></div>
    </div>
  </div>

<?php else: /* log */ ?>
  <?php
  $status = (string) req('status', '');
  $where = $status !== '' && in_array($status, ['queued', 'sent', 'failed'], true) ? 'WHERE n.status = :s' : '';
  $rows = db_all("SELECT n.* FROM notification_log n $where ORDER BY n.id DESC LIMIT 150", $where ? [':s' => $status] : []);
  $cnt = [];
  foreach (db_all('SELECT status, COUNT(*) c FROM notification_log GROUP BY status') as $c) { $cnt[$c['status']] = (int) $c['c']; }
  ?>
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-pills">
      <?php foreach ([''=>'All','queued'=>'Queued','sent'=>'Sent','failed'=>'Failed'] as $k => $l): ?>
        <li class="nav-item"><a class="nav-link <?= $status === $k ? 'active' : '' ?>" href="<?= h(url($self . '?tab=log&status=' . $k)) ?>">
          <?= h($l) ?><?php if ($k !== ''): ?> <span class="badge text-bg-light border"><?= (int) ($cnt[$k] ?? 0) ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
    <form method="post"><?= csrf_field() ?><button class="btn btn-outline-primary" name="action" value="flush"><i class="bi bi-send"></i> Send queued now</button></form>
  </div>
  <div class="card"><div class="table-responsive">
    <table class="table table-admi mb-0 align-middle">
      <thead><tr><th>When</th><th>Notification</th><th>To</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n): $r = json_decode((string) $n['to_addresses'], true) ?: ['to' => []]; ?>
        <tr>
          <td class="small text-nowrap"><?= h(fmt_datetime($n['queued_at'])) ?></td>
          <td><div class="fw-semibold small"><?= h($n['subject']) ?></div>
              <div class="text-muted small"><?= h($n['type_key']) ?></div>
              <?php if ($n['error_message']): ?><div class="small text-danger"><?= h($n['error_message']) ?></div><?php endif; ?></td>
          <td class="small"><?= h(implode(', ', array_keys($r['to'] ?? []))) ?>
              <?php if (!empty($r['cc'])): ?><br><span class="text-muted">cc <?= h(implode(', ', array_keys($r['cc']))) ?></span><?php endif; ?></td>
          <td><span class="badge text-bg-<?= ['sent' => 'success', 'queued' => 'warning', 'failed' => 'danger'][$n['status']] ?>"><?= h($n['status']) ?></span>
              <?php if ($n['attempts']): ?><div class="small text-muted"><?= (int) $n['attempts'] ?> attempt<?= $n['attempts'] == 1 ? '' : 's' ?></div><?php endif; ?></td>
          <td class="text-end text-nowrap">
            <?php if ($n['status'] === 'failed' && $n['to_addresses']): ?>
              <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                <button class="btn btn-sm btn-outline-primary" name="action" value="retry">Retry</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">Nothing here yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div></div>
<?php endif; ?>
<?php include APP_ROOT . '/templates/footer.php'; ?>
