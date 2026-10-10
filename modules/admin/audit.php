<?php
/** Read-only audit trail. Admin only. Rows are never edited or deleted. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_admin();

$action = (string) req('action', '');
$userId = req_int('user_id');
$from   = (string) req('from', date('Y-m-d', strtotime('-30 days')));
$to     = (string) req('to', date('Y-m-d'));
$limit  = min(1000, max(50, req_int('limit', 250)));

$where  = ['al.created_at BETWEEN :from AND :to'];
$params = [':from' => $from . ' 00:00:00', ':to' => $to . ' 23:59:59'];
if ($action !== '') {
    $where[] = 'al.action = :action';
    $params[':action'] = $action;
}
if ($userId) {
    $where[] = 'al.user_id = :uid';
    $params[':uid'] = $userId;
}

$rows = db_all(
    'SELECT al.*, u.full_name FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
      WHERE ' . implode(' AND ', $where) . ' ORDER BY al.created_at DESC LIMIT ' . (int) $limit,
    $params
);
$actions = db_all('SELECT DISTINCT action FROM audit_log ORDER BY action');

$pageTitle   = 'Audit Trail';
$activeNav   = 'admin';
$breadcrumbs = [['label' => 'Admin'], ['label' => 'Audit trail']];
include APP_ROOT . '/templates/header.php';
?>
<form class="card mb-3" method="get">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-6 col-md-2">
        <label class="form-label" for="from">From</label>
        <input class="form-control" type="date" id="from" name="from" value="<?= h($from) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="to">To</label>
        <input class="form-control" type="date" id="to" name="to" value="<?= h($to) ?>">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="action">Action</label>
        <select class="form-select" id="action" name="action" data-autosubmit>
          <option value="">All actions</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= h($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>>
              <?= h($a['action']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label" for="user_id">User</label>
        <select class="form-select" id="user_id" name="user_id" data-autosubmit>
          <option value="">Everyone</option>
          <?php foreach (db_all('SELECT id, full_name FROM users ORDER BY full_name') as $u2): ?>
            <option value="<?= (int) $u2['id'] ?>" <?= $userId === (int) $u2['id'] ? 'selected' : '' ?>>
              <?= h($u2['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-outline-primary" type="submit">Filter</button>
      </div>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-admi mb-0">
      <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>Detail</th><th>IP</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No audit entries in this range.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="small text-nowrap"><?= h(fmt_datetime($r['created_at'])) ?></td>
          <td class="small"><?= h($r['full_name'] ?: 'system') ?></td>
          <td><span class="badge text-bg-light border font-monospace"><?= h($r['action']) ?></span></td>
          <td class="small">
            <?php if ($r['entity_type'] === 'asset' && $r['entity_id']): ?>
              <a href="<?= h(url('modules/assets/view.php?id=' . $r['entity_id'])) ?>">asset #<?= (int) $r['entity_id'] ?></a>
            <?php else: ?>
              <?= h($r['entity_type'] ?: '—') ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?>
            <?php endif; ?>
          </td>
          <td class="small text-muted" style="max-width:34rem;word-break:break-word"><?= h($r['detail'] ?: '') ?></td>
          <td class="small text-muted"><?= h($r['ip_address'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
