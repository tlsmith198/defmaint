<?php
/**
 * Issue log — creation, lifecycle transitions and queries.
 *
 * Phase 5 uses this for the "failed check becomes an issue" path and a read-only
 * view of the result. Phase 6 layers the notification engine, reminder sweep and
 * the full workflow UI on top; the data model and transitions live here.
 */

const ISSUE_FLOW = [
    'open'         => ['acknowledged', 'in_progress', 'resolved'],
    'acknowledged' => ['in_progress', 'resolved'],
    'in_progress'  => ['resolved'],
    'resolved'     => ['closed', 'in_progress'],
    'closed'       => [],
];

function next_issue_no(): string
{
    $prefix = setting('issue_no_prefix', 'ISS-') . date('Y') . '-';
    $max = db_value(
        'SELECT MAX(CAST(SUBSTRING(issue_no, ' . (int) (strlen($prefix) + 1) . ') AS UNSIGNED))
           FROM issues WHERE issue_no LIKE :p',
        [':p' => $prefix . '%'],
        0
    );
    return $prefix . str_pad((string) ((int) $max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * Create an issue.
 *
 * @param array $d asset_id, title, description, severity, and optionally
 *                 requirement_id, due_instance_id, completion_id, assigned_to
 */
function issue_create(array $d): int
{
    $severity = in_array($d['severity'] ?? '', ['minor', 'major', 'down'], true) ? $d['severity'] : 'minor';

    $id = db_insert('issues', [
        'issue_no'        => next_issue_no(),
        'asset_id'        => (int) $d['asset_id'],
        'requirement_id'  => $d['requirement_id']  ?? null,
        'due_instance_id' => $d['due_instance_id'] ?? null,
        'completion_id'   => $d['completion_id']   ?? null,
        'title'           => mb_substr((string) $d['title'], 0, 190),
        'description'     => nn($d['description'] ?? null),
        'severity'        => $severity,
        'status'          => 'open',
        'reported_by'     => current_user_id(),
        'reported_at'     => $d['reported_at'] ?? date('Y-m-d H:i:s'),
        'assigned_to'     => $d['assigned_to'] ?? null,
    ]);

    db_insert('issue_status_history', [
        'issue_id'   => $id,
        'from_status' => null,
        'to_status'  => 'open',
        'changed_by' => current_user_id(),
        'note'       => $d['source_note'] ?? null,
    ]);

    if (!empty($d['source_note'])) {
        db_insert('issue_notes', [
            'issue_id'  => $id,
            'user_id'   => current_user_id(),
            'note_type' => 'system',
            'note'      => $d['source_note'],
        ]);
    }

    // A machine-down report takes the asset out of production immediately.
    if ($severity === 'down') {
        asset_set_status((int) $d['asset_id'], 'down_repair',
            'Reported down: ' . mb_substr((string) $d['title'], 0, 180));
    }

    audit('issue.create', 'issue', $id, ['asset_id' => $d['asset_id'], 'severity' => $severity]);
    notify_queue('issue_new', 'issue', $id);
    if ($severity !== 'minor') {
        notify_queue('issue_critical', 'issue', $id);
    }

    return $id;
}

/** Move an issue through the lifecycle. Returns [ok, message]. */
function issue_set_status(int $issueId, string $to, ?string $note = null): array
{
    $issue = issue_get($issueId);
    if (!$issue) {
        return [false, 'Issue not found.'];
    }
    $from = $issue['status'];
    if ($from === $to) {
        return [false, 'The issue is already ' . label_of($to, issue_statuses()) . '.'];
    }
    if (!in_array($to, ISSUE_FLOW[$from] ?? [], true)) {
        return [false, 'An issue cannot go from ' . label_of($from, issue_statuses())
                     . ' straight to ' . label_of($to, issue_statuses()) . '.'];
    }

    $now  = date('Y-m-d H:i:s');
    $me   = current_user_id();
    $data = ['status' => $to];

    switch ($to) {
        case 'acknowledged':
            $data += ['acknowledged_by' => $me, 'acknowledged_at' => $now];
            break;
        case 'in_progress':
            $data += ['started_by' => $me, 'started_at' => $now];
            break;
        case 'resolved':
            $data += ['resolved_by' => $me, 'resolved_at' => $now, 'resolution_notes' => nn($note)];
            if ($issue['severity'] === 'down') {        // machine-down time: report to resolution
                $data['downtime_minutes'] = max(0, (int) round((strtotime($now) - strtotime($issue['reported_at'])) / 60));
            }
            break;
        case 'closed':
            $data += ['closed_by' => $me, 'closed_at' => $now];
            break;
    }
    db_update('issues', $data, $issueId);

    db_insert('issue_status_history', [
        'issue_id'    => $issueId,
        'from_status' => $from,
        'to_status'   => $to,
        'changed_by'  => $me,
        'note'        => nn($note),
    ]);
    db_insert('issue_notes', [
        'issue_id'  => $issueId,
        'user_id'   => $me,
        'note_type' => 'status_change',
        'note'      => label_of($from, issue_statuses()) . ' → ' . label_of($to, issue_statuses())
                     . ($note ? ' — ' . $note : ''),
    ]);

    audit('issue.status_change', 'issue', $issueId, ['from' => $from, 'to' => $to]);
    notify_queue('issue_status_changed', 'issue', $issueId);
    if ($to === 'resolved') {
        notify_queue('issue_resolved', 'issue', $issueId);
    }

    return [true, 'Issue moved to ' . label_of($to, issue_statuses()) . '.'];
}

function issue_add_note(int $issueId, string $note): void
{
    db_insert('issue_notes', [
        'issue_id'  => $issueId,
        'user_id'   => current_user_id(),
        'note_type' => 'comment',
        'note'      => $note,
    ]);
    audit('issue.note', 'issue', $issueId);
}

function issue_get(int $id): ?array
{
    return db_one(
        'SELECT i.*, a.asset_tag, a.name AS asset_name, a.status AS asset_status,
                u.full_name AS reporter_name, u.email AS reporter_email,
                ar.full_name AS assignee_name, rs.full_name AS resolver_name,
                r.task_name AS requirement_name
           FROM issues i
           JOIN assets a  ON a.id = i.asset_id
           JOIN users u   ON u.id = i.reported_by
           LEFT JOIN users ar ON ar.id = i.assigned_to
           LEFT JOIN users rs ON rs.id = i.resolved_by
           LEFT JOIN maintenance_requirements r ON r.id = i.requirement_id
          WHERE i.id = :id',
        [':id' => $id]
    );
}

/** @param array $f status, severity, asset_id, q, mine */
function issue_list(array $f = []): array
{
    $where  = [];
    $params = [];

    if (($f['status'] ?? '') === 'open_only' || empty($f['status'])) {
        $where[] = "i.status IN ('open','acknowledged','in_progress')";
    } elseif ($f['status'] !== 'all') {
        $where[] = 'i.status = :status';
        $params[':status'] = $f['status'];
    }
    if (!empty($f['severity'])) {
        $where[] = 'i.severity = :sev';
        $params[':sev'] = $f['severity'];
    }
    if (!empty($f['asset_id'])) {
        $where[] = 'i.asset_id = :asset';
        $params[':asset'] = (int) $f['asset_id'];
    }
    if (!empty($f['q'])) {
        $where[] = '(i.title LIKE :q OR i.description LIKE :q2 OR i.issue_no LIKE :q3 OR a.name LIKE :q4)';
        $like = '%' . $f['q'] . '%';
        $params += [':q' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
    }

    return db_all(
        'SELECT i.*, a.asset_tag, a.name AS asset_name, u.full_name AS reporter_name,
                ar.full_name AS assignee_name,
                (SELECT COUNT(*) FROM issue_notes n WHERE n.issue_id = i.id) AS note_count
           FROM issues i
           JOIN assets a ON a.id = i.asset_id
           JOIN users u  ON u.id = i.reported_by
           LEFT JOIN users ar ON ar.id = i.assigned_to
         ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
          ORDER BY FIELD(i.status,"open","acknowledged","in_progress","resolved","closed"),
                   FIELD(i.severity,"down","major","minor"), i.reported_at DESC',
        $params
    );
}

function issue_thread(int $issueId): array
{
    return db_all(
        'SELECT n.*, u.full_name FROM issue_notes n
           LEFT JOIN users u ON u.id = n.user_id
          WHERE n.issue_id = :id ORDER BY n.created_at ASC, n.id ASC',
        [':id' => $issueId]
    );
}

/**
 * Record that a notification is due.
 *
 * The PHPMailer transport arrives in phase 6. Until then the row is logged as
 * 'queued' so nothing is lost and the audit trail is complete from day one.
 */
function notify_queue(string $typeKey, string $entityType, int $entityId, array $context = []): void
{
    if (function_exists('notify_send')) {
        notify_send($typeKey, $entityType, $entityId, $context);      // phase 6
        return;
    }
    try {
        db_insert('notification_log', [
            'type_key'    => $typeKey,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'status'      => 'queued',
            'subject'     => '(queued — mail transport arrives in phase 6)',
        ]);
    } catch (Throwable $e) {
        error_log('notify_queue failed: ' . $e->getMessage());
    }
}
