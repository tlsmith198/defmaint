<?php
/**
 * Notification engine (build phase 6).
 *
 *   notify_send()   queue + (after the page is delivered) send one message for an event
 *   notify_sweep()  nightly cron: PM due/overdue digests, stale-issue reminders, retry queue
 *   notify_deliver() one SMTP delivery through PHPMailer
 *
 * Messages are always written to notification_log first, so the log is a complete record of
 * what the system tried to say and to whom, even when SMTP is down or email is switched off.
 * A notification failure can never break the page or the cron that triggered it.
 */

const NOTIFY_MAX_ATTEMPTS = 3;

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Public base URL for links in email. CLI cron has no HTTP host, so it is remembered from web use. */
function notify_base_url(): string
{
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    $saved = trim((string) setting('app_base_url', ''));
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        if ($saved === '' || $saved !== BASE_URL) {
            try {
                setting_set('app_base_url', BASE_URL, 'email');
            } catch (Throwable $e) {
                // harmless
            }
        }
        return rtrim(BASE_URL, '/');
    }
    return rtrim($saved !== '' ? $saved : BASE_URL, '/');
}

function notify_url(string $path): string
{
    return notify_base_url() . '/' . ltrim($path, '/');
}

/** "3 days 4 hr" without the trailing "ago". */
function notify_age(?string $since): string
{
    $secs = max(0, time() - (int) strtotime((string) $since));
    if ($secs >= 86400) {
        $d = (int) floor($secs / 86400);
        $h = (int) floor(($secs % 86400) / 3600);
        return $d . ' day' . ($d === 1 ? '' : 's') . ($h ? ' ' . $h . ' hr' : '');
    }
    if ($secs >= 3600) {
        $h = (int) floor($secs / 3600);
        return $h . ' hr' . ($h === 1 ? '' : 's');
    }
    return max(1, (int) floor($secs / 60)) . ' min';
}

function notify_render(string $text, array $vars, array $rawKeys = []): string
{
    return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars, $rawKeys) {
        $k = $m[1];
        if (!array_key_exists($k, $vars)) {
            return '';
        }
        return in_array($k, $rawKeys, true) ? (string) $vars[$k] : h($vars[$k]);
    }, $text);
}

function notify_variable_help(): array
{
    return [
        'Issue'  => ['issue_no', 'severity_label', 'status_label', 'from_status', 'changed_by', 'note', 'description',
                     'reported_by', 'reported_at', 'resolution_notes', 'resolved_by', 'resolved_at', 'age', 'issue_url'],
        'Asset'  => ['asset_name', 'asset_tag'],
        'PM'     => ['task_table', 'task_count'],
        'Common' => ['app_url', 'company_name'],
    ];
}

/* ------------------------------------------------------------------ */
/* Recipients                                                          */
/* ------------------------------------------------------------------ */

function notify_add_user(array &$bucket, ?int $userId): void
{
    if (!$userId) {
        return;
    }
    $u = db_one('SELECT full_name, email FROM users WHERE id = :id AND is_active = 1', [':id' => $userId]);
    if ($u && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
        $bucket[strtolower($u['email'])] = $u['full_name'];
    }
}

/**
 * Resolve notification_recipients rules into ['to'=>[email=>name], 'cc'=>[...], 'bcc'=>[...]].
 * @param array|null $issue  issue row (for reporter/assignee/severity rules)
 */
function notify_recipients(string $typeKey, ?int $assetId, ?array $issue = null): array
{
    $out = ['to' => [], 'cc' => [], 'bcc' => []];
    $rank = ['minor' => 1, 'major' => 2, 'down' => 3];
    $rules = db_all('SELECT * FROM notification_recipients WHERE type_key = :t AND is_active = 1', [':t' => $typeKey]);

    foreach ($rules as $rule) {
        if ($issue && $rule['min_severity']
            && ($rank[$issue['severity']] ?? 1) < ($rank[$rule['min_severity']] ?? 1)) {
            continue;
        }
        $bucket = [];
        switch ($rule['recipient_kind']) {
            case 'email':
                foreach (preg_split('/[,;\s]+/', (string) $rule['recipient_value'], -1, PREG_SPLIT_NO_EMPTY) as $addr) {
                    if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                        $bucket[strtolower($addr)] = '';
                    }
                }
                break;
            case 'user':
                notify_add_user($bucket, (int) $rule['recipient_value']);
                break;
            case 'role':
                foreach (db_all('SELECT id FROM users WHERE role = :r AND is_active = 1', [':r' => $rule['recipient_value']]) as $u) {
                    notify_add_user($bucket, (int) $u['id']);
                }
                break;
            case 'asset_owner':
                if ($assetId) {
                    notify_add_user($bucket, (int) db_value('SELECT owner_user_id FROM assets WHERE id = :id', [':id' => $assetId]));
                }
                break;
            case 'issue_reporter':
                notify_add_user($bucket, $issue ? (int) $issue['reported_by'] : null);
                break;
            case 'assignee':
                notify_add_user($bucket, $issue ? (int) $issue['assigned_to'] : null);
                break;
        }
        $slot = in_array($rule['send_as'], ['to', 'cc', 'bcc'], true) ? $rule['send_as'] : 'to';
        $out[$slot] += $bucket;
    }
    // a person listed twice only gets one copy, at the most visible level
    foreach (['bcc', 'cc'] as $lower) {
        foreach (array_keys($out[$lower]) as $addr) {
            if (isset($out['to'][$addr]) || ($lower === 'bcc' && isset($out['cc'][$addr]))) {
                unset($out[$lower][$addr]);
            }
        }
    }
    if (!$out['to'] && $out['cc']) {                    // nobody to address -> promote the cc list
        $out['to'] = $out['cc'];
        $out['cc'] = [];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Build + queue                                                       */
/* ------------------------------------------------------------------ */

/** Variables for an issue event. */
function notify_issue_vars(array $issue, array $ctx = []): array
{
    $hist = db_all(
        'SELECT h.from_status, h.to_status, h.note, u.full_name FROM issue_status_history h
           JOIN users u ON u.id = h.changed_by WHERE h.issue_id = :i ORDER BY h.id DESC LIMIT 1',
        [':i' => $issue['id']]
    );
    $last = $hist[0] ?? ['from_status' => '', 'to_status' => $issue['status'], 'note' => '', 'full_name' => ''];
    $sevs = severities();
    $stat = issue_statuses();
    return [
        'issue_no'         => $issue['issue_no'],
        'severity_label'   => $sevs[$issue['severity']]['label'] ?? $issue['severity'],
        'status_label'     => $stat[$issue['status']]['label'] ?? $issue['status'],
        'from_status'      => $stat[$last['from_status']]['label'] ?? (string) $last['from_status'],
        'changed_by'       => $last['full_name'],
        'note'             => (string) ($ctx['note'] ?? $last['note'] ?? ''),
        'description'      => nl2br(h((string) $issue['description'])),
        'reported_by'      => $issue['reporter_name'],
        'reported_at'      => fmt_datetime($issue['reported_at']),
        'resolution_notes' => (string) $issue['resolution_notes'],
        'resolved_by'      => (string) ($issue['resolver_name'] ?? ''),
        'resolved_at'      => fmt_datetime($issue['resolved_at']),
        'age'              => notify_age($issue['reported_at']),
        'issue_url'        => notify_url('modules/issues/view.php?id=' . (int) $issue['id']),
        'asset_name'       => $issue['asset_name'],
        'asset_tag'        => $issue['asset_tag'],
    ];
}

/** HTML table of PM tasks for the digest emails. */
function notify_task_table(array $rows): string
{
    $html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:13px;width:100%">'
          . '<tr style="background:#f1f3f5;text-align:left"><th>Task</th><th>Frequency</th><th>Due</th><th>Late</th></tr>';
    foreach ($rows as $r) {
        $late = (int) $r['days_late'];
        $html .= '<tr style="border-top:1px solid #dee2e6"><td>' . h($r['task_name'])
               . ($r['is_safety_critical'] ? ' <strong style="color:#b02a37">(safety)</strong>' : '') . '</td>'
               . '<td>' . h(label_of($r['frequency'], frequencies())) . '</td>'
               . '<td>' . h(fmt_date($r['due_date'])) . '</td>'
               . '<td>' . ($late > 0 ? '<strong style="color:#b02a37">' . $late . 'd</strong>' : '—') . '</td></tr>';
    }
    return $html . '</table>';
}

/**
 * Queue one notification and schedule its delivery for after the response is sent.
 * Never throws.
 *
 * @param array $context optional: note, tasks (rows for PM digests), asset_id
 */
function notify_send(string $typeKey, string $entityType, int $entityId, array $context = []): void
{
    try {
        $tpl = db_one('SELECT * FROM notification_templates WHERE type_key = :k', [':k' => $typeKey]);
        if (!$tpl || !(int) $tpl['is_active']) {
            return;
        }

        $issue = null;
        $assetId = (int) ($context['asset_id'] ?? 0);
        $vars = [];
        if ($entityType === 'issue') {
            $issue = issue_get($entityId);
            if (!$issue) {
                return;
            }
            $assetId = (int) $issue['asset_id'];
            $vars = notify_issue_vars($issue, $context);
        } elseif ($entityType === 'asset') {
            $assetId = $entityId;
            $a = db_one('SELECT name, asset_tag FROM assets WHERE id = :id', [':id' => $entityId]);
            if (!$a) {
                return;
            }
            $vars = ['asset_name' => $a['name'], 'asset_tag' => $a['asset_tag']];
        }
        $tasks = $context['tasks'] ?? [];
        $vars += [
            'task_table'   => $tasks ? notify_task_table($tasks) : '',
            'task_count'   => (string) count($tasks),
            'app_url'      => notify_url('dashboard.php'),
            'company_name' => (string) setting('company_name', 'ADMI'),
        ];
        $raw = ['task_table', 'description'];

        $subject = notify_render($tpl['subject'], $vars, $raw);
        $body    = notify_render($tpl['body_html'], $vars, $raw);
        $rcpt    = notify_recipients($typeKey, $assetId ?: null, $issue);

        if (!$rcpt['to']) {
            db_insert('notification_log', [
                'type_key' => $typeKey, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'subject' => mb_substr($subject, 0, 255), 'body_snapshot' => $body,
                'status' => 'failed', 'error_message' => 'No recipients matched the rules for this notification.',
            ]);
            return;
        }

        db_insert('notification_log', [
            'type_key'      => $typeKey,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'to_addresses'  => json_encode($rcpt),
            'cc_addresses'  => implode(', ', array_keys($rcpt['cc'])) ?: null,
            'subject'       => mb_substr($subject, 0, 255),
            'body_snapshot' => $body,
            'status'        => 'queued',
        ]);

        if (setting_bool('email_enabled') && PHP_SAPI !== 'cli') {
            notify_defer_flush();
        }
    } catch (Throwable $e) {
        error_log('notify_send failed: ' . $e->getMessage());
    }
}

/** Deliver queued mail once the browser already has its response. */
function notify_defer_flush(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;
    register_shutdown_function(function () {
        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            notify_flush(10);
        } catch (Throwable $e) {
            error_log('notify deferred flush failed: ' . $e->getMessage());
        }
    });
}

/* ------------------------------------------------------------------ */
/* Delivery                                                            */
/* ------------------------------------------------------------------ */

function notify_load_mailer(): ?string
{
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return null;
    }
    $auto = APP_ROOT . '/vendor/autoload.php';
    if (!is_file($auto)) {
        return 'The mail library is missing (the vendor folder was not uploaded).';
    }
    require_once $auto;
    return class_exists('PHPMailer\\PHPMailer\\PHPMailer') ? null : 'The mail library could not be loaded.';
}

/** Wrap a message body in the branded email shell. */
function notify_wrap(string $subject, string $bodyHtml): string
{
    $company = (string) setting('company_name', 'ADMI');
    return '<!DOCTYPE html><html><body style="margin:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#212529">'
         . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:20px">'
         . '<table width="620" cellpadding="0" cellspacing="0" style="max-width:620px;background:#fff;border:1px solid #dee2e6;border-radius:6px">'
         . '<tr><td style="background:#1f2a37;color:#fff;padding:14px 20px;font-size:16px;font-weight:bold">'
         . h($company) . ' — Maintenance</td></tr>'
         . '<tr><td style="padding:20px;font-size:14px;line-height:1.5">' . $bodyHtml . '</td></tr>'
         . '<tr><td style="padding:12px 20px;border-top:1px solid #dee2e6;font-size:11px;color:#6c757d">'
         . 'Automated message from the ' . h($company) . ' Maintenance System. Do not reply.</td></tr>'
         . '</table></td></tr></table></body></html>';
}

/**
 * Send one raw message. @return string|null error text, null on success
 */
function notify_transport(array $rcpt, string $subject, string $bodyHtml): ?string
{
    if ($err = notify_load_mailer()) {
        return $err;
    }
    $from = trim((string) setting('smtp_from_email', ''));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return 'Settings → Email: enter a valid From address.';
    }
    try {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->CharSet = 'UTF-8';
        $m->Timeout = 12;
        $host = trim((string) setting('smtp_host', ''));
        if ($host !== '') {
            $m->isSMTP();
            $m->Host = $host;
            $m->Port = setting_int('smtp_port', 587);
            $secure = strtolower((string) setting_raw('smtp_secure', 'tls'));
            if ($secure === 'ssl') {
                $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $m->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $m->SMTPSecure = '';
                $m->SMTPAutoTLS = false;
            }
            $user = (string) setting('smtp_username', '');
            if ($user !== '') {
                $m->SMTPAuth = true;
                $m->Username = $user;
                $m->Password = (string) setting('smtp_password', '');
            }
        } else {
            $m->isMail();                        // hosts that still allow mail()
        }
        $m->setFrom($from, (string) setting('smtp_from_name', 'ADMI Maintenance System'));
        foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $slot => $fn) {
            foreach (($rcpt[$slot] ?? []) as $addr => $name) {
                $m->$fn($addr, (string) $name);
            }
        }
        $m->isHTML(true);
        $m->Subject = $subject;
        $m->Body    = notify_wrap($subject, $bodyHtml);
        $m->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $bodyHtml)), ENT_QUOTES, 'UTF-8'));
        $m->send();
        return null;
    } catch (Throwable $e) {
        return mb_substr($e->getMessage(), 0, 480);
    }
}

/** Deliver one notification_log row. @return bool sent */
function notify_deliver(int $logId): bool
{
    $row = db_one("SELECT * FROM notification_log WHERE id = :id AND status = 'queued'", [':id' => $logId]);
    if (!$row) {
        return false;
    }
    $rcpt = json_decode((string) $row['to_addresses'], true);
    if (!is_array($rcpt) || empty($rcpt['to'])) {
        db_query("UPDATE notification_log SET status='failed', error_message='No recipients' WHERE id=:id", [':id' => $logId]);
        return false;
    }
    db_query('UPDATE notification_log SET attempts = attempts + 1 WHERE id = :id', [':id' => $logId]);
    $err = notify_transport($rcpt, (string) $row['subject'], (string) $row['body_snapshot']);
    if ($err === null) {
        db_query("UPDATE notification_log SET status='sent', sent_at=NOW(), error_message=NULL WHERE id=:id", [':id' => $logId]);
        if ($row['entity_type'] === 'issue') {
            db_insert('issue_notes', [
                'issue_id' => (int) $row['entity_id'], 'user_id' => null, 'note_type' => 'email',
                'note' => 'Email sent: ' . $row['subject'] . ' → ' . implode(', ', array_keys($rcpt['to'])),
            ]);
        }
        return true;
    }
    $final = ((int) $row['attempts'] + 1) >= NOTIFY_MAX_ATTEMPTS;
    db_query('UPDATE notification_log SET status = :s, error_message = :e WHERE id = :id',
        [':s' => $final ? 'failed' : 'queued', ':e' => $err, ':id' => $logId]);
    return false;
}

/** Send queued messages (newest-first would starve old ones, so oldest first). @return int sent */
function notify_flush(int $limit = 25): int
{
    // messages that waited more than 2 days are stale news -- close them out instead of blasting them later
    db_query("UPDATE notification_log SET status='failed', error_message='Expired before it could be sent'
               WHERE status='queued' AND queued_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    if (!setting_bool('email_enabled')) {
        return 0;
    }
    $sent = 0;
    $ids = db_all("SELECT id FROM notification_log WHERE status='queued' AND attempts < :m ORDER BY id LIMIT " . (int) $limit,
        [':m' => NOTIFY_MAX_ATTEMPTS]);
    foreach ($ids as $r) {
        if (notify_deliver((int) $r['id'])) {
            $sent++;
        }
    }
    return $sent;
}

/** Admin "send a test" button. @return string|null error */
function notify_send_test(string $to): ?string
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid test recipient first (and save).';
    }
    return notify_transport(['to' => [$to => '']], 'ADMI Maintenance — test email',
        '<p>This is a test message from the <strong>' . h(setting('company_name', 'ADMI')) . ' Maintenance System</strong>.</p>'
        . '<p>If you can read this, outbound email is working.</p>');
}

/* ------------------------------------------------------------------ */
/* Nightly sweep                                                       */
/* ------------------------------------------------------------------ */

function notify_already_today(string $typeKey, string $entityType, int $entityId): bool
{
    return (bool) db_value(
        'SELECT id FROM notification_log WHERE type_key = :t AND entity_type = :e AND entity_id = :i
            AND DATE(queued_at) = CURDATE() LIMIT 1',
        [':t' => $typeKey, ':e' => $entityType, ':i' => $entityId]
    );
}

/** One digest per machine per day for due-today / overdue PM. @return int digests queued */
function notify_pm_digests(): int
{
    $n = 0;
    $incDaily = setting_bool('notify_include_daily', false);
    foreach (['pm_due_today', 'pm_overdue'] as $type) {
        $overdue = $type === 'pm_overdue';
        $where = $overdue
            ? 'd.due_date < DATE_SUB(CURDATE(), INTERVAL r.grace_days DAY)'
            : 'd.due_date = CURDATE()';
        if (!$overdue && !$incDaily) {
            $where .= " AND r.frequency <> 'daily'";       // dailies are due every single day -- not news
        }
        $rows = db_all(
            "SELECT d.asset_id, d.due_date, r.task_name, r.frequency, r.is_safety_critical,
                    DATEDIFF(CURDATE(), d.due_date) AS days_late
               FROM maintenance_due_instances d
               JOIN maintenance_requirements r ON r.id = d.requirement_id
               JOIN assets a ON a.id = d.asset_id
              WHERE d.status = 'scheduled' AND r.is_active = 1 AND a.status <> 'retired' AND $where
              ORDER BY d.asset_id, d.due_date, r.sort_order"
        );
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['asset_id']][] = $r;
        }
        foreach ($by as $assetId => $tasks) {
            if (notify_already_today($type, 'asset', $assetId)) {
                continue;
            }
            notify_send($type, 'asset', $assetId, ['tasks' => $tasks, 'asset_id' => $assetId]);
            $n++;
        }
    }
    return $n;
}

/** Remind on issues that have sat without activity past their severity threshold. @return int */
function notify_issue_reminders(): int
{
    $hours = [
        'down'  => max(1, setting_int('issue_reminder_hours_down', 4)),
        'major' => max(1, setting_int('issue_reminder_hours_major', 24)),
        'minor' => max(1, setting_int('issue_reminder_hours_minor', 120)),
    ];
    $n = 0;
    $open = db_all(
        "SELECT id, severity,
                TIMESTAMPDIFF(HOUR, GREATEST(updated_at, COALESCE(last_reminder_at, reported_at)), NOW()) AS idle_h
           FROM issues WHERE status IN ('open','acknowledged','in_progress') AND reminder_count < 10"
    );
    foreach ($open as $i) {
        if ((int) $i['idle_h'] >= ($hours[$i['severity']] ?? 120)) {
            notify_send('issue_overdue', 'issue', (int) $i['id']);
            db_query('UPDATE issues SET last_reminder_at = NOW(), reminder_count = reminder_count + 1 WHERE id = :id',
                [':id' => $i['id']]);
            $n++;
        }
    }
    return $n;
}

/** Cron entry point. @return int items handled */
function notify_sweep(): int
{
    $n = 0;
    if (setting_bool('email_enabled')) {
        $n += notify_pm_digests();
        $n += notify_issue_reminders();
    }
    $n += notify_flush(50);
    return $n;
}
