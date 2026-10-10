<?php
/** Asset register -- filterable / sortable list view. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$filters = [
    'q'               => (string) req('q', ''),
    'status'          => (string) req('status', ''),
    'category_id'     => req_int('category_id'),
    'location_id'     => req_int('location_id'),
    'owner_id'        => req_int('owner_id'),
    'due'             => (string) req('due', ''),
    'sort'            => (string) req('sort', 'tag'),
    'dir'             => (string) req('dir', 'asc'),
    'include_retired' => req('retired') === '1',
];
$rows = asset_list($filters);

$today = date('Y-m-d');
$counts = ['total' => count($rows), 'overdue' => 0, 'issues' => 0];
foreach ($rows as $r) {
    if ($r['next_due'] && $r['next_due'] < $today) $counts['overdue']++;
    if ($r['open_issues'] > 0) $counts['issues']++;
}

$pageTitle   = 'Asset Register';
$activeNav   = 'assets';
$pageActions = '';
if (can('manage_assets')) {
    $pageActions .= '<a class="btn btn-primary" href="' . h(url('modules/assets/form.php'))
                  . '"><i class="bi bi-plus-lg"></i> New asset</a>';
}
$pageActions .= ' <a class="btn btn-outline-secondary" href="' . h(url('modules/assets/export.php') . qs())
              . '"><i class="bi bi-download"></i> CSV</a>';

include APP_ROOT . '/templates/header.php';
$cats = category_options();
$locs = location_options();
$users = active_users();
?>

<form class="card mb-3" method="get">
  <div class="card-body py-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" id="q" name="q" value="<?= h($filters['q']) ?>"
               placeholder="Name, tag, model, serial…">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="status">Status</label>
        <select class="form-select" id="status" name="status" data-autosubmit>
          <option value="">All in service</option>
          <?php foreach (asset_statuses() as $k => $m): ?>
            <option value="<?= h($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= h($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="category_id">Category</label>
        <select class="form-select" id="category_id" name="category_id" data-autosubmit>
          <option value="">All</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= $filters['category_id'] === (int) $c['id'] ? 'selected' : '' ?>>
              <?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="location_id">Location</label>
        <select class="form-select" id="location_id" name="location_id" data-autosubmit>
          <option value="">All</option>
          <?php foreach ($locs as $l): ?>
            <option value="<?= (int) $l['id'] ?>" <?= $filters['location_id'] === (int) $l['id'] ? 'selected' : '' ?>>
              <?= h($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="due">Show</label>
        <select class="form-select" id="due" name="due" data-autosubmit>
          <option value="">Everything</option>
          <option value="overdue" <?= $filters['due'] === 'overdue' ? 'selected' : '' ?>>PM overdue</option>
          <option value="week"    <?= $filters['due'] === 'week'    ? 'selected' : '' ?>>PM due within 7 days</option>
          <option value="issues"  <?= $filters['due'] === 'issues'  ? 'selected' : '' ?>>Has open issues</option>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-outline-primary" type="submit">Filter</button>
      </div>
    </div>
    <div class="d-flex flex-wrap gap-3 mt-2 small">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="retired" name="retired" value="1"
               <?= $filters['include_retired'] ? 'checked' : '' ?> data-autosubmit>
        <label class="form-check-label" for="retired">Include retired assets</label>
      </div>
      <span class="text-muted"><?= (int) $counts['total'] ?> asset<?= $counts['total'] === 1 ? '' : 's' ?>
        &middot; <?= (int) $counts['overdue'] ?> with overdue PM
        &middot; <?= (int) $counts['issues'] ?> with open issues</span>
      <?php if (qs()): ?>
        <a href="<?= h(url('modules/assets/index.php')) ?>">Clear filters</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table table-admi table-hover mb-0">
      <thead>
        <tr>
          <th><?= sort_link('tag', 'Tag', $filters['sort'], $filters['dir']) ?></th>
          <th><?= sort_link('name', 'Asset', $filters['sort'], $filters['dir']) ?></th>
          <th><?= sort_link('category', 'Category', $filters['sort'], $filters['dir']) ?></th>
          <th><?= sort_link('location', 'Location', $filters['sort'], $filters['dir']) ?></th>
          <th><?= sort_link('year', 'Year', $filters['sort'], $filters['dir']) ?></th>
          <th><?= sort_link('status', 'Status', $filters['sort'], $filters['dir']) ?></th>
          <th class="text-center">PM tasks</th>
          <th><?= sort_link('due', 'Next PM due', $filters['sort'], $filters['dir']) ?></th>
          <th class="text-center"><?= sort_link('issues', 'Open issues', $filters['sort'], $filters['dir']) ?></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">
          No assets match these filters.
          <?php if (can('manage_assets')): ?>
            <a href="<?= h(url('modules/assets/form.php')) ?>">Add the first one</a>.
          <?php endif; ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $a):
        $rowCls = [];
        if ($a['down_issues'] > 0) $rowCls[] = 'has-down';
        if ($a['status'] === 'retired') $rowCls[] = 'is-retired';
      ?>
        <tr class="<?= implode(' ', $rowCls) ?>" data-href="<?= h(url('modules/assets/view.php?id=' . $a['id'])) ?>">
          <td class="font-monospace small"><?= h($a['asset_tag']) ?></td>
          <td>
            <a class="fw-semibold text-decoration-none"
               href="<?= h(url('modules/assets/view.php?id=' . $a['id'])) ?>"><?= h($a['name']) ?></a>
            <?php if ($a['machine_type']): ?>
              <div class="small text-muted"><?= h($a['machine_type']) ?><?= $a['control_system'] ? ' &middot; ' . h($a['control_system']) : '' ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= h($a['category_name'] ?: '—') ?></td>
          <td class="small"><?= h($a['location_name'] ?: '—') ?></td>
          <td class="small"><?= h($a['year_built'] ?: '—') ?></td>
          <td><?= badge($a['status'], asset_statuses()) ?></td>
          <td class="text-center small"><?= (int) $a['req_count'] ?: '<span class="text-muted">—</span>' ?></td>
          <td class="small">
            <?php if (!$a['next_due']): ?>
              <span class="text-muted">—</span>
            <?php else:
              $dueCls = $a['next_due'] < $today ? 'due-overdue' : ($a['next_due'] === $today ? 'due-today' : 'due-soon'); ?>
              <span class="<?= $dueCls ?>"><?= h(fmt_date($a['next_due'])) ?></span>
              <div class="text-muted"><?= h(fmt_age($a['next_due'])) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($a['open_issues'] > 0): ?>
              <span class="badge text-bg-<?= $a['down_issues'] > 0 ? 'danger' : 'warning' ?>"><?= (int) $a['open_issues'] ?></span>
            <?php else: ?>
              <span class="text-muted small">0</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
