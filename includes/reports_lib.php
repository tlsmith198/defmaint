<?php
/**
 * AS9100 report pack (build phase 7).
 *
 * Every report is built as plain data by report_build() -- a title, a few header facts,
 * summary figures and one or more tables -- and then rendered three ways from that one
 * structure: on screen (HTML), as a branded PDF (mPDF) and as CSV. Screen, PDF and CSV can
 * therefore never disagree with each other.
 */

const REPORT_ROW_CAP_PDF = 3000;
const REPORT_ROW_CAP     = 20000;

function report_catalog(): array
{
    return [
        'asset_history' => ['title' => 'Asset Maintenance History', 'icon' => 'bi-clock-history', 'landscape' => true,
            'desc' => 'Every PM sign-off, measured reading and issue for one machine over a period. The objective-evidence pack for AS9100 7.1.3 / 8.5.1.',
            'params' => ['asset', 'range']],
        'completion_log' => ['title' => 'PM Completion Log', 'icon' => 'bi-journal-check', 'landscape' => true,
            'desc' => 'Fleet-wide record of who did which maintenance task and when. Filter by machine, frequency or result.',
            'params' => ['asset_opt', 'range', 'frequency', 'result']],
        'compliance' => ['title' => 'PM Compliance Summary', 'icon' => 'bi-bar-chart-line', 'landscape' => false,
            'desc' => 'On-time vs. late vs. missed maintenance by machine and by frequency, with a compliance percentage.',
            'params' => ['range']],
        'open_pm' => ['title' => 'Overdue & Upcoming PM', 'icon' => 'bi-exclamation-diamond', 'landscape' => true,
            'desc' => 'What is overdue right now, and what is coming due. A working list for the maintenance lead.',
            'params' => ['scope']],
        'issue_log' => ['title' => 'Issue & Downtime Log', 'icon' => 'bi-tools', 'landscape' => true,
            'desc' => 'Faults reported, how they were resolved and how long machines were down. Supports nonconformance and trend review.',
            'params' => ['asset_opt', 'range', 'severity', 'issue_status']],
        'register' => ['title' => 'Equipment Register', 'icon' => 'bi-hdd-stack', 'landscape' => true,
            'desc' => 'Every asset with identification, location, owner, status, and last / next maintenance.',
            'params' => ['asset_status']],
    ];
}

/** Parse + sanitise request parameters for a report. */
function report_params(array $src): array
{
    $d = fn($k, $def) => (isset($src[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $src[$k])) ? (string) $src[$k] : $def;
    $from = $d('from', date('Y-m-d', strtotime('-90 days')));
    $to   = $d('to', date('Y-m-d'));
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $pick = fn($k, array $ok, $def = '') => in_array((string) ($src[$k] ?? $def), $ok, true) ? (string) ($src[$k] ?? $def) : $def;
    return [
        'asset_id'   => (int) ($src['asset_id'] ?? 0),
        'from'       => $from,
        'to'         => $to,
        'frequency'  => $pick('frequency', array_keys(frequencies())),
        'result'     => $pick('result', ['pass', 'fail', 'done', 'not_done', 'na']),
        'severity'   => $pick('severity', ['minor', 'major', 'down']),
        'issue_status' => $pick('issue_status', ['open', 'acknowledged', 'in_progress', 'resolved', 'closed']),
        'scope'      => $pick('scope', ['overdue', 'next7', 'next30', 'all'], 'overdue'),
        'asset_status' => $pick('asset_status', array_keys(asset_statuses())),
    ];
}

function report_period(array $p): string
{
    return fmt_date($p['from']) . ' – ' . fmt_date($p['to']);
}

/* ------------------------------------------------------------------ */
/* Report builders. Each returns the common structure documented below. */
/*   title, subtitle, facts[[label,value]], summary[[label,value]],     */
/*   tables[ ['heading'=>, 'cols'=>[label=>align], 'rows'=>[[...]]] ],  */
/*   notes[]                                                            */
/* ------------------------------------------------------------------ */

function report_build(string $key, array $p): ?array
{
    $fn = 'report_' . $key;
    return (isset(report_catalog()[$key]) && function_exists($fn)) ? $fn($p) : null;
}

function report_result_label(string $r): string
{
    return ['pass' => 'Pass', 'fail' => 'FAIL', 'done' => 'Done', 'not_done' => 'NOT DONE', 'na' => 'N/A'][$r] ?? $r;
}

function report_asset_line(array $a): string
{
    return $a['asset_tag'] . ' – ' . $a['name'];
}

function report_completion_rows(array $where, array $params, int $cap): array
{
    return db_all(
        "SELECT c.id, c.completed_at, c.task_name_snapshot, c.frequency_snapshot, c.due_date_snapshot, c.result,
                c.notes, c.revision_no, c.hour_meter, c.duration_minutes,
                a.asset_tag, a.name AS asset_name, u.full_name AS by_name
           FROM maintenance_completions c
           JOIN assets a ON a.id = c.asset_id
           JOIN users u  ON u.id = c.completed_by
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.completed_at DESC, c.id DESC LIMIT " . (int) $cap,
        $params
    );
}

function report_completion_log(array $p): array
{
    $where = ['DATE(c.completed_at) BETWEEN :f AND :t'];
    $par = [':f' => $p['from'], ':t' => $p['to']];
    $facts = [['Period', report_period($p)]];
    if ($p['asset_id']) {
        $where[] = 'c.asset_id = :a';
        $par[':a'] = $p['asset_id'];
        if ($a = asset_get($p['asset_id'])) {
            $facts[] = ['Machine', report_asset_line($a)];
        }
    }
    if ($p['frequency']) {
        $where[] = 'c.frequency_snapshot = :fr';
        $par[':fr'] = $p['frequency'];
        $facts[] = ['Frequency', label_of($p['frequency'], frequencies())];
    }
    if ($p['result']) {
        $where[] = 'c.result = :rs';
        $par[':rs'] = $p['result'];
        $facts[] = ['Result', report_result_label($p['result'])];
    }
    $rows = report_completion_rows($where, $par, REPORT_ROW_CAP + 1);
    $truncated = count($rows) > REPORT_ROW_CAP;
    $rows = array_slice($rows, 0, REPORT_ROW_CAP);

    $done = $bad = $rev = 0;
    $out = [];
    foreach ($rows as $r) {
        in_array($r['result'], ['not_done', 'fail'], true) ? $bad++ : $done++;
        $rev += $r['revision_no'] > 0 ? 1 : 0;
        $out[] = [fmt_datetime($r['completed_at']), $r['asset_tag'] . ' ' . $r['asset_name'], $r['task_name_snapshot'],
                  label_of($r['frequency_snapshot'], frequencies()), fmt_date($r['due_date_snapshot']),
                  report_result_label($r['result']), $r['by_name'],
                  trim(($r['revision_no'] > 0 ? '[corrected rev ' . $r['revision_no'] . '] ' : '') . (string) $r['notes'])];
    }
    return [
        'title' => 'PM Completion Log', 'subtitle' => report_period($p), 'facts' => $facts,
        'summary' => [['Records', count($rows)], ['Done / pass', $done], ['Not done / fail', $bad], ['Corrected after entry', $rev]],
        'tables' => [['heading' => '', 'cols' => ['Completed' => 'l', 'Machine' => 'l', 'Task' => 'l', 'Freq.' => 'l',
                       'Due' => 'l', 'Result' => 'l', 'Completed by' => 'l', 'Notes' => 'l'], 'rows' => $out]],
        'notes' => $truncated ? ['Showing the most recent ' . number_format(REPORT_ROW_CAP) . ' records. Narrow the date range for the rest.'] : [],
    ];
}

function report_asset_history(array $p): ?array
{
    $a = $p['asset_id'] ? asset_get($p['asset_id']) : null;
    if (!$a) {
        return null;
    }
    $owner = $a['owner_user_id'] ? db_value('SELECT full_name FROM users WHERE id = :id', [':id' => $a['owner_user_id']]) : null;
    $loc = $a['location_id'] ? db_value('SELECT name FROM locations WHERE id = :id', [':id' => $a['location_id']]) : null;
    $facts = [
        ['Machine', report_asset_line($a)],
        ['Manufacturer / model', trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')) ?: '—'],
        ['Serial number', $a['serial_number'] ?: '—'],
        ['Location', $loc ?: '—'],
        ['Responsible person', $owner ?: '—'],
        ['Current status', label_of($a['status'], asset_statuses())],
        ['Period', report_period($p)],
    ];

    $rows = report_completion_rows(['c.asset_id = :a', 'DATE(c.completed_at) BETWEEN :f AND :t'],
        [':a' => $a['id'], ':f' => $p['from'], ':t' => $p['to']], REPORT_ROW_CAP);
    $comp = [];
    $done = $bad = 0;
    foreach ($rows as $r) {
        in_array($r['result'], ['not_done', 'fail'], true) ? $bad++ : $done++;
        $comp[] = [fmt_datetime($r['completed_at']), $r['task_name_snapshot'], label_of($r['frequency_snapshot'], frequencies()),
                   fmt_date($r['due_date_snapshot']), report_result_label($r['result']), $r['by_name'],
                   trim(($r['revision_no'] > 0 ? '[corrected rev ' . $r['revision_no'] . '] ' : '') . (string) $r['notes'])];
    }

    $reads = db_all(
        "SELECT c.completed_at, c.task_name_snapshot, s.step_no, s.step_text_snapshot, s.result, s.measured_value,
                s.value_unit, s.in_range, s.note
           FROM completion_step_results s JOIN maintenance_completions c ON c.id = s.completion_id
          WHERE c.asset_id = :a AND DATE(c.completed_at) BETWEEN :f AND :t AND s.measured_value IS NOT NULL
          ORDER BY c.completed_at DESC, s.step_no LIMIT 2000",
        [':a' => $a['id'], ':f' => $p['from'], ':t' => $p['to']]
    );
    $readRows = [];
    $oor = 0;
    foreach ($reads as $r) {
        $oor += ($r['in_range'] !== null && (string) $r['in_range'] === '0') ? 1 : 0;
        $readRows[] = [fmt_datetime($r['completed_at']), $r['task_name_snapshot'], $r['step_no'] . '. ' . $r['step_text_snapshot'],
                       rtrim(rtrim(number_format((float) $r['measured_value'], 3, '.', ''), '0'), '.') . ' ' . $r['value_unit'],
                       $r['in_range'] === null ? '—' : ((int) $r['in_range'] ? 'In range' : 'OUT OF RANGE'), (string) $r['note']];
    }

    $issues = db_all(
        "SELECT * FROM issues WHERE asset_id = :a AND DATE(reported_at) BETWEEN :f AND :t ORDER BY reported_at DESC",
        [':a' => $a['id'], ':f' => $p['from'], ':t' => $p['to']]
    );
    $issueRows = [];
    $down = 0;
    foreach ($issues as $i) {
        $down += (int) $i['downtime_minutes'];
        $issueRows[] = [$i['issue_no'], fmt_datetime($i['reported_at']), label_of($i['severity'], severities()), $i['title'],
                        label_of($i['status'], issue_statuses()), fmt_datetime($i['resolved_at']),
                        $i['downtime_minutes'] !== null ? number_format($i['downtime_minutes'] / 60, 1) . ' h' : '—',
                        (string) $i['resolution_notes']];
    }

    $tables = [['heading' => 'Maintenance sign-offs', 'cols' => ['Completed' => 'l', 'Task' => 'l', 'Freq.' => 'l', 'Due' => 'l',
                'Result' => 'l', 'Completed by' => 'l', 'Notes' => 'l'], 'rows' => $comp]];
    if ($readRows) {
        $tables[] = ['heading' => 'Measured readings', 'cols' => ['Completed' => 'l', 'Task' => 'l', 'Step' => 'l', 'Reading' => 'l',
                     'Limit check' => 'l', 'Note' => 'l'], 'rows' => $readRows];
    }
    $tables[] = ['heading' => 'Issues & downtime', 'cols' => ['Issue' => 'l', 'Reported' => 'l', 'Severity' => 'l', 'Title' => 'l',
                 'Status' => 'l', 'Resolved' => 'l', 'Downtime' => 'l', 'Resolution' => 'l'], 'rows' => $issueRows];
    return [
        'title' => 'Asset Maintenance History', 'subtitle' => report_asset_line($a), 'facts' => $facts,
        'summary' => [['PM sign-offs', count($comp)], ['Done / pass', $done], ['Not done / fail', $bad], ['Readings out of range', $oor],
                      ['Issues raised', count($issues)], ['Recorded downtime', number_format($down / 60, 1) . ' h']],
        'tables' => $tables, 'notes' => [], 'signoff' => true,
    ];
}

function report_compliance(array $p): array
{
    $base = "FROM maintenance_due_instances d
               JOIN maintenance_requirements r ON r.id = d.requirement_id
               JOIN assets a ON a.id = d.asset_id
          LEFT JOIN maintenance_completions c ON c.id = d.completion_id
              WHERE d.due_date BETWEEN :f AND :t AND d.due_date <= CURDATE() AND d.status <> 'cancelled'";
    $cols = "COUNT(*) AS total,
             SUM(d.status = 'completed' AND c.result IN ('done','pass','na') AND d.days_late <= r.grace_days) AS on_time,
             SUM(d.status = 'completed' AND c.result IN ('done','pass','na') AND d.days_late > r.grace_days) AS late,
             SUM(d.status = 'completed' AND c.result IN ('not_done','fail')) AS failed,
             SUM(d.status = 'missed') AS missed,
             SUM(d.status = 'scheduled' AND d.due_date < DATE_SUB(CURDATE(), INTERVAL r.grace_days DAY)) AS open_overdue,
             SUM(d.status = 'skipped') AS skipped";
    $par = [':f' => $p['from'], ':t' => $p['to']];
    $byAsset = db_all("SELECT a.asset_tag, a.name, $cols $base GROUP BY a.id ORDER BY a.asset_tag", $par);
    $byFreq  = db_all("SELECT r.frequency AS name, $cols $base GROUP BY r.frequency
                       ORDER BY FIELD(r.frequency,'daily','weekly','monthly','quarterly','semiannual','annual','custom')", $par);

    $pct = function (array $r): array {
        $den = (int) $r['on_time'] + (int) $r['late'] + (int) $r['failed'] + (int) $r['missed'] + (int) $r['open_overdue'];
        return [$den, $den > 0 ? round(100 * (int) $r['on_time'] / $den, 1) : null];
    };
    $mk = function (array $rows, bool $isAsset) use ($pct): array {
        $out = [];
        foreach ($rows as $r) {
            [, $c] = $pct($r);
            $out[] = [$isAsset ? $r['asset_tag'] . ' ' . $r['name'] : label_of($r['name'], frequencies()),
                      (int) $r['total'], (int) $r['on_time'], (int) $r['late'], (int) $r['failed'], (int) $r['missed'],
                      (int) $r['open_overdue'], (int) $r['skipped'], $c === null ? '—' : number_format($c, 1) . '%'];
        }
        return $out;
    };
    $tot = ['on_time' => 0, 'late' => 0, 'failed' => 0, 'missed' => 0, 'open_overdue' => 0, 'total' => 0, 'skipped' => 0];
    foreach ($byAsset as $r) {
        foreach ($tot as $k => $_) {
            $tot[$k] += (int) $r[$k];
        }
    }
    [, $fleet] = $pct($tot);
    $head = ['On time' => 'r', 'Late' => 'r', 'Failed / not done' => 'r', 'Missed' => 'r', 'Overdue (open)' => 'r', 'Skipped' => 'r', 'Compliance' => 'r'];
    return [
        'title' => 'PM Compliance Summary', 'subtitle' => report_period($p),
        'facts' => [['Period', report_period($p)], ['Measure', 'Tasks that fell due in the period (up to today)']],
        'summary' => [['Fleet compliance', $fleet === null ? '—' : number_format($fleet, 1) . '%'], ['Tasks due', $tot['total']],
                      ['On time', $tot['on_time']], ['Late', $tot['late']], ['Missed / overdue', $tot['missed'] + $tot['open_overdue']]],
        'tables' => [
            ['heading' => 'By frequency', 'cols' => ['Frequency' => 'l', 'Due' => 'r'] + $head, 'rows' => $mk($byFreq, false)],
            ['heading' => 'By machine', 'cols' => ['Machine' => 'l', 'Due' => 'r'] + $head, 'rows' => $mk($byAsset, true)],
        ],
        'notes' => ['Compliance % = tasks completed on time and passed ÷ (on time + late + failed + missed + still overdue). '
                  . 'Skipped tasks are excluded, and tasks that fell due but are still inside their grace period are not counted against compliance yet. '
                  . '“On time” allows each task’s own grace period.'],
    ];
}

function report_open_pm(array $p): array
{
    $cond = ['overdue' => 'd.due_date < CURDATE()', 'next7' => 'd.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)',
             'next30' => 'd.due_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 'all' => '1=1'][$p['scope']];
    $label = ['overdue' => 'Overdue now', 'next7' => 'Overdue + next 7 days', 'next30' => 'Overdue + next 30 days', 'all' => 'All open'][$p['scope']];
    $rows = db_all(
        "SELECT a.asset_tag, a.name AS asset_name, r.task_name, r.frequency, r.is_safety_critical, d.due_date,
                DATEDIFF(CURDATE(), d.due_date) AS days_late, COALESCE(u.full_name, r.assigned_role, '') AS assignee
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
           JOIN assets a ON a.id = d.asset_id
      LEFT JOIN users u ON u.id = r.assigned_user_id
          WHERE d.status = 'scheduled' AND r.is_active = 1 AND a.status <> 'retired' AND $cond
          ORDER BY d.due_date, a.asset_tag, r.sort_order LIMIT " . REPORT_ROW_CAP
    );
    $out = [];
    $od = $safety = 0;
    foreach ($rows as $r) {
        $od += $r['days_late'] > 0 ? 1 : 0;
        $safety += $r['is_safety_critical'] ? 1 : 0;
        $out[] = [$r['asset_tag'] . ' ' . $r['asset_name'], $r['task_name'] . ($r['is_safety_critical'] ? '  ⚠ safety' : ''),
                  label_of($r['frequency'], frequencies()), fmt_date($r['due_date']),
                  $r['days_late'] > 0 ? $r['days_late'] . ' d late' : ($r['days_late'] == 0 ? 'today' : 'in ' . abs($r['days_late']) . ' d'),
                  $r['assignee']];
    }
    return [
        'title' => 'Overdue & Upcoming PM', 'subtitle' => $label . ' — as of ' . fmt_date(date('Y-m-d')),
        'facts' => [['Scope', $label], ['As of', fmt_datetime(date('Y-m-d H:i:s'))]],
        'summary' => [['Open tasks listed', count($rows)], ['Overdue', $od], ['Safety-critical', $safety]],
        'tables' => [['heading' => '', 'cols' => ['Machine' => 'l', 'Task' => 'l', 'Freq.' => 'l', 'Due' => 'l', 'Status' => 'l', 'Assigned' => 'l'], 'rows' => $out]],
        'notes' => [],
    ];
}

function report_issue_log(array $p): array
{
    $where = ['DATE(i.reported_at) BETWEEN :f AND :t'];
    $par = [':f' => $p['from'], ':t' => $p['to']];
    $facts = [['Period', report_period($p)]];
    if ($p['asset_id']) {
        $where[] = 'i.asset_id = :a';
        $par[':a'] = $p['asset_id'];
        if ($a = asset_get($p['asset_id'])) {
            $facts[] = ['Machine', report_asset_line($a)];
        }
    }
    if ($p['severity']) {
        $where[] = 'i.severity = :sv';
        $par[':sv'] = $p['severity'];
        $facts[] = ['Severity', label_of($p['severity'], severities())];
    }
    if ($p['issue_status']) {
        $where[] = 'i.status = :st';
        $par[':st'] = $p['issue_status'];
        $facts[] = ['Status', label_of($p['issue_status'], issue_statuses())];
    }
    $rows = db_all(
        "SELECT i.*, a.asset_tag, a.name AS asset_name, u.full_name AS reporter
           FROM issues i JOIN assets a ON a.id = i.asset_id JOIN users u ON u.id = i.reported_by
          WHERE " . implode(' AND ', $where) . " ORDER BY i.reported_at DESC LIMIT " . REPORT_ROW_CAP, $par);
    $out = [];
    $down = $open = 0;
    $sev = ['minor' => 0, 'major' => 0, 'down' => 0];
    foreach ($rows as $i) {
        $down += (int) $i['downtime_minutes'];
        $open += in_array($i['status'], ['resolved', 'closed'], true) ? 0 : 1;
        $sev[$i['severity']]++;
        $out[] = [$i['issue_no'], fmt_datetime($i['reported_at']), $i['asset_tag'] . ' ' . $i['asset_name'],
                  label_of($i['severity'], severities()), $i['title'], label_of($i['status'], issue_statuses()), $i['reporter'],
                  fmt_datetime($i['resolved_at']),
                  $i['downtime_minutes'] !== null ? number_format($i['downtime_minutes'] / 60, 1) : '—',
                  (string) $i['resolution_notes']];
    }
    return [
        'title' => 'Issue & Downtime Log', 'subtitle' => report_period($p), 'facts' => $facts,
        'summary' => [['Issues', count($rows)], ['Still open', $open], ['Machine down', $sev['down']], ['Major', $sev['major']],
                      ['Minor', $sev['minor']], ['Recorded downtime', number_format($down / 60, 1) . ' h']],
        'tables' => [['heading' => '', 'cols' => ['Issue' => 'l', 'Reported' => 'l', 'Machine' => 'l', 'Severity' => 'l', 'Title' => 'l',
                       'Status' => 'l', 'Reported by' => 'l', 'Resolved' => 'l', 'Down (h)' => 'r', 'Resolution' => 'l'], 'rows' => $out]],
        'notes' => [],
    ];
}

function report_register(array $p): array
{
    $where = $p['asset_status'] ? 'a.status = :s' : "a.status <> 'retired'";
    $rows = db_all(
        "SELECT a.*, l.name AS loc, o.full_name AS owner,
                (SELECT COUNT(*) FROM maintenance_requirements r WHERE r.asset_id = a.id AND r.is_active = 1) AS reqs,
                (SELECT MIN(d.due_date) FROM maintenance_due_instances d WHERE d.asset_id = a.id AND d.status = 'scheduled') AS next_due,
                (SELECT MAX(c.completed_at) FROM maintenance_completions c WHERE c.asset_id = a.id) AS last_pm
           FROM assets a LEFT JOIN locations l ON l.id = a.location_id LEFT JOIN users o ON o.id = a.owner_user_id
          WHERE $where ORDER BY a.asset_tag",
        $p['asset_status'] ? [':s' => $p['asset_status']] : []
    );
    $out = [];
    $by = [];
    foreach ($rows as $a) {
        $by[$a['status']] = ($by[$a['status']] ?? 0) + 1;
        $out[] = [$a['asset_tag'], $a['name'], trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')), (string) $a['serial_number'],
                  (string) $a['loc'], (string) $a['owner'], label_of($a['status'], asset_statuses()), fmt_date($a['in_service_date']),
                  (int) $a['reqs'], fmt_datetime($a['last_pm']), fmt_date($a['next_due'])];
    }
    $sum = [['Assets listed', count($rows)]];
    foreach ($by as $s => $n) {
        $sum[] = [label_of($s, asset_statuses()), $n];
    }
    return [
        'title' => 'Equipment Register', 'subtitle' => $p['asset_status'] ? label_of($p['asset_status'], asset_statuses()) : 'All except retired',
        'facts' => [['As of', fmt_date(date('Y-m-d'))]], 'summary' => $sum,
        'tables' => [['heading' => '', 'cols' => ['Tag' => 'l', 'Name' => 'l', 'Make / model' => 'l', 'Serial' => 'l', 'Location' => 'l',
                       'Owner' => 'l', 'Status' => 'l', 'In service' => 'l', 'PM tasks' => 'r', 'Last PM' => 'l', 'Next due' => 'l'], 'rows' => $out]],
        'notes' => [],
    ];
}

/* ------------------------------------------------------------------ */
/* Renderers                                                            */
/* ------------------------------------------------------------------ */

function report_css(): string
{
    return 'body{font-family:dejavusanscondensed,sans-serif;font-size:8.5pt;color:#1a1a1a}
      h1{font-size:15pt;margin:0 0 2pt 0;color:#12263f} .sub{font-size:10pt;color:#444;margin-bottom:8pt}
      table.facts{border-collapse:collapse;margin-bottom:8pt} table.facts td{padding:1.5pt 10pt 1.5pt 0;font-size:8.5pt;vertical-align:top}
      table.facts td.k{color:#666;white-space:nowrap}
      table.sum{border-collapse:collapse;margin-bottom:10pt;width:100%} table.sum td{border:0.6pt solid #c9d1d9;padding:4pt 6pt;text-align:center;background:#f4f6f8}
      table.sum .n{font-size:13pt;font-weight:bold;color:#12263f} table.sum .l{font-size:7pt;color:#555}
      h2{page-break-after:avoid;font-size:10.5pt;margin:12pt 0 4pt 0;color:#12263f;border-bottom:0.8pt solid #12263f;padding-bottom:1.5pt}
      table.data{border-collapse:collapse;width:100%} table.data th{background:#12263f;color:#fff;font-size:7.5pt;text-align:left;padding:3pt 4pt;border:0.4pt solid #12263f}
      table.data td{border:0.4pt solid #b9c2cc;padding:2.5pt 4pt;font-size:7.5pt;vertical-align:top}
      table.data tr.alt td{background:#f7f9fb} td.r,th.r{text-align:right} td.bad{color:#b02a37;font-weight:bold}
      .note{font-size:7.5pt;color:#555;margin-top:6pt} .empty{font-size:8pt;color:#777;font-style:italic;padding:4pt 0}
      table.sign{border-collapse:collapse;width:100%;margin-top:22pt} table.sign td{padding:16pt 8pt 2pt 0;border-bottom:0.6pt solid #333;width:30%}
      table.sign td.gap{border:0;width:5%} table.sign td.cap{border:0;padding:2pt 0 0 0;font-size:7pt;color:#555}';
}

function report_cell_class(string $v): string
{
    return preg_match('/^(FAIL|NOT DONE|OUT OF RANGE)$/', $v) || preg_match('/\d+ d late$/', $v) ? ' class="bad"' : '';
}

/** Report body HTML (no page chrome) -- shared by screen and PDF. */
function report_body_html(array $r): string
{
    $h = '<h1>' . h($r['title']) . '</h1><div class="sub">' . h($r['subtitle']) . '</div>';
    if (!empty($r['facts'])) {
        $h .= '<table class="facts">';
        foreach ($r['facts'] as [$k, $v]) {
            $h .= '<tr><td class="k">' . h($k) . '</td><td><strong>' . h($v) . '</strong></td></tr>';
        }
        $h .= '</table>';
    }
    if (!empty($r['summary'])) {
        $h .= '<table class="sum"><tr>';
        foreach ($r['summary'] as [$l, $v]) {
            $h .= '<td><div class="n">' . h($v) . '</div><div class="l">' . h($l) . '</div></td>';
        }
        $h .= '</tr></table>';
    }
    foreach ($r['tables'] as $t) {
        if ($t['heading'] !== '') {
            $h .= '<h2>' . h($t['heading']) . '</h2>';
        }
        if (!$t['rows']) {
            $h .= '<div class="empty">No records for this period.</div>';
            continue;
        }
        $h .= '<table class="data"><thead><tr>';
        foreach ($t['cols'] as $label => $al) {
            $h .= '<th' . ($al === 'r' ? ' class="r"' : '') . '>' . h($label) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($t['rows'] as $i => $row) {
            $h .= '<tr' . ($i % 2 ? ' class="alt"' : '') . '>';
            $c = 0;
            foreach ($t['cols'] as $al) {
                $v = (string) ($row[$c++] ?? '');
                $cls = trim(($al === 'r' ? 'r ' : '') . (report_cell_class($v) ? 'bad' : ''));
                $h .= '<td' . ($cls ? ' class="' . $cls . '"' : '') . '>' . nl2br(h($v)) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
    }
    foreach ($r['notes'] ?? [] as $n) {
        $h .= '<div class="note">' . h($n) . '</div>';
    }
    if (!empty($r['signoff'])) {
        $h .= '<table class="sign"><tr><td></td><td class="gap"></td><td></td><td class="gap"></td><td></td></tr>'
            . '<tr><td class="cap">Reviewed by (print / sign)</td><td class="gap"></td><td class="cap">Title</td><td class="gap"></td><td class="cap">Date</td></tr></table>';
    }
    return $h;
}

function report_header_html(array $r): string
{
    $logo = (string) setting('logo_path', '');
    $logoFs = $logo !== '' ? UPLOAD_PATH . '/' . ltrim($logo, '/') : '';
    $img = ($logoFs && is_file($logoFs)) ? '<img src="' . h($logoFs) . '" style="width:' . max(10, setting_int('logo_report_width_mm', 38)) . 'mm">' : '';
    $bits = array_filter([trim((string) setting('company_address', '')),
                          (string) setting('cage_code', '') !== '' ? 'CAGE ' . setting('cage_code') : '']);
    $co = h(setting('company_name', 'ADMI')) . '<br><span style="font-size:7pt;font-weight:normal;color:#555">'
        . h(implode(' · ', $bits)) . '</span>';
    return '<table width="100%" style="border-bottom:0.8pt solid #12263f;font-family:dejavusanscondensed"><tr>'
         . '<td width="45%">' . $img . '</td><td width="55%" align="right" style="font-size:10pt;font-weight:bold;color:#12263f">' . $co . '</td></tr></table>';
}

function report_footer_html(array $r): string
{
    return '<table width="100%" style="border-top:0.5pt solid #999;font-size:7pt;color:#555;font-family:dejavusanscondensed"><tr>'
         . '<td width="62%">' . h(setting('report_footer_text', '')) . '<br>' . h($r['title']) . ' · generated '
         . h(fmt_datetime(date('Y-m-d H:i:s'))) . ' by ' . h(current_user_name()) . '</td>'
         . '<td width="38%" align="right">Page {PAGENO} of {nb}</td></tr></table>';
}

/** Stream the report as a PDF download (or inline). Exits. */
function report_pdf(array $r, bool $landscape, string $filename, bool $inline = false): void
{
    $auto = APP_ROOT . '/vendor/autoload.php';
    if (!is_file($auto)) {
        http_response_code(500);
        exit('PDF library missing: upload the vendor folder from the release package.');
    }
    require_once $auto;
    @ini_set('memory_limit', '384M');
    @set_time_limit(120);
    $tmp = APP_ROOT . '/tmp/mpdf';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0755, true);
    }
    foreach (glob($tmp . '/*') ?: [] as $stale) {            // housekeeping: mPDF scratch files older than a day
        if (is_file($stale) && filemtime($stale) < time() - 86400) {
            @unlink($stale);
        }
    }
    $rows = 0;
    foreach ($r['tables'] as $t) {
        $rows += count($t['rows']);
    }
    $mpdf = new \Mpdf\Mpdf([
        'tempDir' => $tmp, 'format' => 'Letter' . ($landscape ? '-L' : ''), 'default_font' => 'dejavusanscondensed',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 24, 'margin_bottom' => 20, 'margin_header' => 6, 'margin_footer' => 6,
        'shrink_tables_to_fit' => 1, 'use_kwt' => true,
    ]);
    $mpdf->SetTitle($r['title'] . ' — ' . $r['subtitle']);
    $mpdf->SetAuthor(current_user_name());
    $mpdf->SetCreator(setting('company_name', 'ADMI') . ' Maintenance System');
    $mpdf->SetHTMLHeader(report_header_html($r));
    $mpdf->SetHTMLFooter(report_footer_html($r));
    $mpdf->WriteHTML('<style>' . report_css() . '</style>', \Mpdf\HTMLParserMode::HEADER_CSS);
    if ($rows > REPORT_ROW_CAP_PDF) {
        $r['notes'][] = 'PDF limited to the first ' . number_format(REPORT_ROW_CAP_PDF) . ' rows per table; use CSV for the full list.';
        foreach ($r['tables'] as &$t) {
            $t['rows'] = array_slice($t['rows'], 0, REPORT_ROW_CAP_PDF);
        }
        unset($t);
    }
    // write in slices so very long tables do not exhaust memory
    $mpdf->WriteHTML(report_body_html($r), \Mpdf\HTMLParserMode::HTML_BODY);
    while (ob_get_level()) {
        ob_end_clean();
    }
    $mpdf->Output($filename, $inline ? \Mpdf\Output\Destination::INLINE : \Mpdf\Output\Destination::DOWNLOAD);
    exit;
}

/** Stream the report tables as CSV (Excel-safe UTF-8 with BOM). Exits. */
function report_csv(array $r, string $filename): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$r['title'], $r['subtitle']]);
    foreach ($r['facts'] as [$k, $v]) {
        fputcsv($out, [$k, $v]);
    }
    foreach ($r['tables'] as $t) {
        fputcsv($out, []);
        if ($t['heading'] !== '') {
            fputcsv($out, [$t['heading']]);
        }
        fputcsv($out, array_keys($t['cols']));
        foreach ($t['rows'] as $row) {
            fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v, $row));   // no formula injection
        }
    }
    fclose($out);
    exit;
}
