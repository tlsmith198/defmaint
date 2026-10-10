<?php
/** Issue detail — lifecycle, correspondence thread, linked PM record. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$id    = req_int('id');
$issue = $id ? issue_get($id) : null;
if (!$issue) {
    flash('Issue not found.', 'danger');
    redirect('modules/issues/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = (string) req('action');

    if ($action === 'note') {
        $note = trim((string) req('note', ''));
        if ($note === '') {
            flash('Write something first.', 'warning');
        } else {
            issue_add_note($id, $note);
            flash('Note added.');
        }

    } elseif ($action === 'status') {
        $to = (string) req('to_status');
        if ($to === 'closed' && !can('close_issues')) {
            flash('Only an administrator can close an issue.', 'danger');
        } else {
            [$ok, $msg] = issue_set_status($id, $to, (string) req('note', '') ?: null);
            flash(h($msg), $ok ? 'success' : 'danger');
        }

    } elseif ($action === 'assign' && can('close_issues')) {
        $to = req_int('assigned_to');
        db_update('issues', ['assigned_to' => $to ?: null], $id);
        audit('issue.assign', 'issue', $id, ['assigned_to' => $to]);
        flash('Assignment updated.');

    } elseif ($action === 'photo') {
        [$rel, $err] = store_upload($_FILES['photo'] ?? [], 'issues', UPLOAD_IMAGE_TYPES);
        if ($err) {
            flash(h($err), 'danger');
        } elseif ($rel) {
            attach('issue', $id, $rel, $_FILES['photo'], 'photo');
            flash('Photo added.');
        }
    }
    redirect('modules/issues/view.php?id=' . $id);
}

$thread  = issue_thread($id);
$photos  = attachments_for('issue', $id);
$history = db_all(
    'SELECT h.*, u.full_name FROM issue_status_history h
       JOIN users u ON u.id = h.changed_by
      WHERE h.issue_id = :id ORDER BY h.changed_at, h.id',
    [':id' => $id]
);
$completion = $issue['completion_id']
    ? db_one('SELECT * FROM maintenance_completions WHERE id = :id', [':id' => $issue['completion_id']])
    : null;

$next = ISSUE_FLOW[$issue['status']] ?? [];

$pageTitle   = $issue['issue_no'] . ' — ' . $issue['title'];
$activeNav   = 'issues';
$breadcrumbs = [['label' => 'Issues', 'url' => 'modules/issues/index.php'], ['label' => $issue['issue_no']]];
include APP_ROOT . '/templates/header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><?= h($issue['issue_no']) ?></span>
        <span><?= badge($issue['severity'], severities()) ?> <?= badge($issue['status'], issue_statuses()) ?></span>
      </div>
      <div class="card-body">
        <h2 class="h5"><?= h($issue['title']) ?></h2>
        <p class="text-muted small mb-3">
          <a href="<?= h(url('modules/assets/view.php?id=' . $issue['asset_id'])) ?>"><?= h($issue['asset_name']) ?></a>
          <span class="font-monospace"><?= h($issue['asset_tag']) ?></span>
          · reported by <?= h($issue['reporter_name']) ?> <?= h(fmt_age($issue['reported_at'])) ?>
          (<?= h(fmt_datetime($issue['reported_at'])) ?>)
        </p>
        <?php if ($issue['description']): ?>
          <div style="white-space:pre-wrap"><?= h($issue['description']) ?></div>
        <?php endif; ?>

        <?php if ($completion): ?>
          <div class="alert alert-warning mt-3 py-2 small mb-0">
            Raised from the PM check <strong><?= h($completion['task_name_snapshot']) ?></strong> —
            <a class="alert-link" href="<?= h(url('modules/maintenance/completion.php?id=' . $completion['id'])) ?>">
              view the completion record</a>.
          </div>
        <?php endif; ?>

        <?php if ($photos): ?>
          <div class="form-section-title">Photos</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($photos as $p): ?>
              <a href="<?= h(upload_url($p['file_path'])) ?>" target="_blank" rel="noopener">
                <img src="<?= h(upload_url($p['file_path'])) ?>" alt="" style="height:120px;border-radius:.3rem">
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">Thread</div>
      <div class="card-body">
        <div class="timeline">
          <?php foreach ($thread as $n): ?>
            <div class="timeline-item">
              <div class="d-flex justify-content-between flex-wrap gap-2">
                <span class="fw-semibold small">
                  <?= h($n['full_name'] ?: 'System') ?>
                  <?php if ($n['note_type'] !== 'comment'): ?>
                    <span class="badge text-bg-light border"><?= h(str_replace('_', ' ', $n['note_type'])) ?></span>
                  <?php endif; ?>
                </span>
                <span class="timeline-meta"><?= h(fmt_datetime($n['created_at'])) ?></span>
              </div>
              <div class="small" style="white-space:pre-wrap"><?= h($n['note']) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if (!$thread): ?>
            <p class="text-muted small mb-0">Nothing on the thread yet.</p>
          <?php endif; ?>
        </div>

        <form method="post" class="mt-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="note">
          <label class="form-label" for="note">Add a note</label>
          <textarea class="form-control mb-2" id="note" name="note" rows="2"
                    placeholder="What you found, what you did, what is on order"></textarea>
          <button class="btn btn-outline-primary btn-sm" type="submit">Post note</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header">Move it along</div>
      <div class="card-body">
        <?php if (!$next): ?>
          <p class="text-muted small mb-0">This issue is closed. Nothing further to do.</p>
        <?php else: ?>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <label class="form-label" for="to_status">New status</label>
            <select class="form-select mb-2" id="to_status" name="to_status">
              <?php foreach ($next as $s): ?>
                <?php if ($s === 'closed' && !can('close_issues')) continue; ?>
                <option value="<?= h($s) ?>"><?= h(label_of($s, issue_statuses())) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="form-label" for="snote">Note</label>
            <textarea class="form-control mb-2" id="snote" name="note" rows="2"
                      placeholder="Optional — required in spirit when resolving"></textarea>
            <button class="btn btn-primary w-100" type="submit">Update status</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Detail</div>
      <table class="table table-admi mb-0"><tbody>
        <tr><td>Reported</td><td><?= h(fmt_datetime($issue['reported_at'])) ?></td></tr>
        <tr><td>Acknowledged</td><td><?= h(fmt_datetime($issue['acknowledged_at'])) ?></td></tr>
        <tr><td>Started</td><td><?= h(fmt_datetime($issue['started_at'])) ?></td></tr>
        <tr><td>Resolved</td><td><?= h(fmt_datetime($issue['resolved_at'])) ?>
          <?= $issue['resolver_name'] ? '<br><span class="small text-muted">' . h($issue['resolver_name']) . '</span>' : '' ?></td></tr>
        <tr><td>Closed</td><td><?= h(fmt_datetime($issue['closed_at'])) ?></td></tr>
        <tr><td>Assigned</td><td><?= h($issue['assignee_name'] ?: '—') ?></td></tr>
      </tbody></table>
      <?php if (can('close_issues')): ?>
      <div class="card-body border-top">
        <form method="post" class="d-flex gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="assign">
          <select class="form-select form-select-sm" name="assigned_to">
            <option value="">— unassigned —</option>
            <?php foreach (active_users() as $u2): ?>
              <option value="<?= (int) $u2['id'] ?>"
                <?= (string) $issue['assigned_to'] === (string) $u2['id'] ? 'selected' : '' ?>>
                <?= h($u2['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-outline-secondary" type="submit">Set</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <div class="card mb-3">
      <div class="card-header">Add a photo</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="photo">
          <input class="form-control mb-2" type="file" name="photo" accept="image/*" capture="environment">
          <button class="btn btn-outline-primary btn-sm w-100" type="submit">Upload</button>
        </form>
      </div>
    </div>

    <?php if ($history): ?>
    <div class="card">
      <div class="card-header">Status history</div>
      <table class="table table-admi mb-0"><tbody>
        <?php foreach ($history as $hh): ?>
          <tr>
            <td class="small">
              <?= $hh['from_status'] ? h(label_of($hh['from_status'], issue_statuses())) . ' → ' : '' ?>
              <strong><?= h(label_of($hh['to_status'], issue_statuses())) ?></strong>
            </td>
            <td class="small text-muted"><?= h($hh['full_name']) ?><br><?= h(fmt_datetime($hh['changed_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include APP_ROOT . '/templates/footer.php'; ?>
