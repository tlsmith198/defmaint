<?php
/**
 * Preventive-maintenance due engine.
 *
 * Scheduling model
 * ----------------
 * Exactly ONE open ("scheduled") occurrence exists per active requirement at a time.
 * Future dates are projected on demand for the calendar rather than persisted, because in
 * rolling mode the next due date depends on when the work is actually signed off.
 *
 *   rolling         next due = last completion + interval_days   (default)
 *   fixed_calendar  next due = the next calendar slot (day-of-month / weekday)
 *
 * When a cycle is skipped entirely, the catch-up loop closes the stale occurrence as
 * 'missed' and opens the following one, so the compliance report counts honestly instead
 * of showing a single task that is 40 days late.
 */

const DUE_MAX_CATCHUP = 40;      // safety cap on missed occurrences created per run
const DUE_MONTHS_PER_FREQ = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12];

/**
 * The date the schedule is allowed to start — the day the shop actually begins
 * using the system. Nothing falls due before it, so a system that sat installed
 * for six weeks before go-live does not greet the floor with 600 overdue checks.
 *
 * Set in Admin → Settings → Scheduling. Empty means "started already".
 *
 * @return string|null Y-m-d
 */
function due_schedule_start(): ?string
{
    $raw = (string) setting('schedule_start_date', '');
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

/** True while go-live is still ahead of us. */
function due_before_go_live(): bool
{
    $start = due_schedule_start();
    return $start !== null && $start > date('Y-m-d');
}

/** Resolved interval in days for a requirement row. */
function due_interval_days(array $req): int
{
    $days = (int) ($req['interval_days'] ?? 0);
    if ($days > 0) {
        return $days;
    }
    $table = frequencies();
    return max(1, (int) ($table[$req['frequency']]['days'] ?? 30));
}

/**
 * The due date that follows $fromDate for this requirement.
 *
 * @param string $fromDate Y-m-d — the previous due date, or the completion date.
 */
function due_next_date(array $req, string $fromDate): string
{
    $ts   = strtotime($fromDate) ?: time();
    $freq = (string) $req['frequency'];

    if (($req['schedule_mode'] ?? 'rolling') !== 'fixed_calendar') {
        return date('Y-m-d', strtotime('+' . due_interval_days($req) . ' days', $ts));
    }

    // ---- fixed calendar ----------------------------------------------
    if ($freq === 'daily') {
        return date('Y-m-d', strtotime('+1 day', $ts));
    }

    if ($freq === 'weekly') {
        $target = (int) ($req['fixed_weekday'] ?? 0);          // 1 = Mon … 7 = Sun
        $next   = strtotime('+1 day', $ts);
        if ($target < 1 || $target > 7) {
            return date('Y-m-d', strtotime('+7 days', $ts));
        }
        for ($i = 0; $i < 7; $i++) {
            if ((int) date('N', $next) === $target) {
                break;
            }
            $next = strtotime('+1 day', $next);
        }
        return date('Y-m-d', $next);
    }

    $months = DUE_MONTHS_PER_FREQ[$freq] ?? null;
    if ($months === null) {                                    // custom cadence
        return date('Y-m-d', strtotime('+' . due_interval_days($req) . ' days', $ts));
    }

    $day = (int) ($req['fixed_day_of_month'] ?? 0) ?: (int) date('j', $ts);
    $day = max(1, min(28, $day));                              // 28 keeps February safe
    $base = strtotime(date('Y-m-01', $ts) . ' +' . $months . ' months');

    return date('Y-m-', $base) . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
}

/**
 * First due date for a requirement that has never been completed.
 *
 * Everything would otherwise fall due on the day the fleet is imported — 403 tasks at once.
 * Longer-cycle work is spread deterministically across the planning horizon instead, which
 * is also how a shop would phase it in.
 */
function due_initial_date(array $req): string
{
    // Start counting from the latest of: today, the go-live date, the machine's
    // in-service date. Never before the shop is actually using the system.
    $anchor = date('Y-m-d');
    foreach ([due_schedule_start(), $req['in_service_date'] ?? null] as $candidate) {
        if ($candidate && ($d = date('Y-m-d', (int) strtotime((string) $candidate))) > $anchor) {
            $anchor = $d;
        }
    }

    $interval = due_interval_days($req);
    if ($interval <= 1) {
        return $anchor;                                         // daily checks all start day one
    }
    $spread = max(1, min($interval, setting_int('due_horizon_days', 45)));
    $offset = (int) $req['id'] % $spread;

    return date('Y-m-d', strtotime($anchor . ' +' . $offset . ' days'));
}

/**
 * Pull a computed due date forward to the go-live date when it lands before it,
 * keeping the same deterministic spread so everything does not stack on day one.
 */
function due_apply_start_floor(array $req, string $dueDate): string
{
    $start = due_schedule_start();
    if ($start === null || $dueDate >= $start) {
        return $dueDate;
    }
    return due_initial_date($req);
}

/** The open occurrence for a requirement, or null. */
function due_open_instance(int $requirementId): ?array
{
    return db_one(
        "SELECT * FROM maintenance_due_instances
          WHERE requirement_id = :r AND status = 'scheduled'
          ORDER BY due_date LIMIT 1",
        [':r' => $requirementId]
    );
}

/** Create an occurrence, tolerating the unique (requirement, due_date) key. */
function due_create_instance(array $req, string $dueDate, string $by = 'cron'): ?int
{
    try {
        return db_insert('maintenance_due_instances', [
            'requirement_id' => (int) $req['id'],
            'asset_id'       => (int) $req['asset_id'],
            'due_date'       => $dueDate,
            'window_start'   => date('Y-m-d', strtotime($dueDate . ' -' . min(7, due_interval_days($req)) . ' days')),
            'status'         => 'scheduled',
            'generated_by'   => $by,
        ]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), '1062') || stripos($e->getMessage(), 'Duplicate') !== false) {
            return null;                                        // already generated
        }
        throw $e;
    }
}

/** Keep the denormalised columns on the requirement in step. */
function due_refresh_requirement(int $requirementId): void
{
    db_query(
        "UPDATE maintenance_requirements r
            SET r.next_due_date = (SELECT MIN(d.due_date) FROM maintenance_due_instances d
                                    WHERE d.requirement_id = r.id AND d.status = 'scheduled'),
                r.last_completed_at = (SELECT MAX(c.completed_at) FROM maintenance_completions c
                                        WHERE c.requirement_id = r.id)
          WHERE r.id = :id",
        [':id' => $requirementId]
    );
}

/**
 * Generate and catch up occurrences.
 *
 * @param int|null $requirementId Limit to one requirement (used after edits).
 * @return int Number of occurrences created or closed as missed.
 */
function due_engine_generate(?int $requirementId = null): int
{
    $params = [];
    $sql = "SELECT r.*, a.status AS asset_status, a.in_service_date
              FROM maintenance_requirements r
              JOIN assets a ON a.id = r.asset_id
             WHERE r.is_active = 1 AND a.status <> 'retired'";
    if ($requirementId) {
        $sql .= ' AND r.id = :id';
        $params[':id'] = $requirementId;
    }

    $today   = date('Y-m-d');
    $touched = 0;

    foreach (db_all($sql, $params) as $req) {
        $open = due_open_instance((int) $req['id']);

        // ---- nothing open: start the cycle ---------------------------
        if (!$open) {
            $last = db_value(
                'SELECT MAX(completed_at) FROM maintenance_completions WHERE requirement_id = :r',
                [':r' => $req['id']]
            );
            $next = $last
                ? due_next_date($req, date('Y-m-d', strtotime($last)))
                : ($req['next_due_date'] ?: due_initial_date($req));
            $next = due_apply_start_floor($req, $next);

            if (due_create_instance($req, $next, 'cron') !== null) {
                $touched++;
            }
            due_refresh_requirement((int) $req['id']);
            continue;
        }

        // ---- an occurrence is open: has a whole cycle been skipped? ---
        $grace    = (int) $req['grace_days'];
        $interval = due_interval_days($req);
        $guard    = 0;

        // Dated before go-live: nobody was expected to do it, so move it forward
        // rather than recording a miss against the floor.
        $startFloor = due_schedule_start();
        if ($startFloor !== null && $open['due_date'] < $startFloor) {
            $moved = due_initial_date($req);
            try {
                db_query('UPDATE maintenance_due_instances SET due_date = :d, window_start = NULL
                           WHERE id = :id', [':d' => $moved, ':id' => $open['id']]);
                $touched++;
            } catch (PDOException $e) {
                // A later occurrence already owns that date; drop this one instead.
                db_query("UPDATE maintenance_due_instances SET status = 'cancelled',
                                 skip_reason = 'Superseded by the go-live date'
                           WHERE id = :id", [':id' => $open['id']]);
            }
            due_refresh_requirement((int) $req['id']);
            continue;
        }

        while ($guard++ < DUE_MAX_CATCHUP) {
            $staleAfter = date('Y-m-d', strtotime($open['due_date'] . ' +' . ($interval + $grace) . ' days'));
            if ($staleAfter > $today) {
                break;                                          // still inside its window
            }
            db_query(
                "UPDATE maintenance_due_instances
                    SET status = 'missed', days_late = DATEDIFF(CURDATE(), due_date)
                  WHERE id = :id",
                [':id' => $open['id']]
            );
            $touched++;

            $next = due_apply_start_floor($req, due_next_date($req, $open['due_date']));
            $id   = due_create_instance($req, $next, 'cron');
            if ($id === null) {
                break;
            }
            $touched++;
            $open = db_one('SELECT * FROM maintenance_due_instances WHERE id = :id', [':id' => $id]);
            if (!$open) {
                break;
            }
        }
        due_refresh_requirement((int) $req['id']);
    }

    return $touched;
}

/** Close an occurrence and open the next one. Called from the completion form. */
function due_engine_after_completion(int $requirementId, string $completedAt, ?int $dueInstanceId, int $completionId): void
{
    $req = db_one('SELECT * FROM maintenance_requirements WHERE id = :id', [':id' => $requirementId]);
    if (!$req) {
        return;
    }

    $anchorDate = date('Y-m-d', strtotime($completedAt));

    if ($dueInstanceId) {
        db_query(
            "UPDATE maintenance_due_instances
                SET status = 'completed', completion_id = :c, completed_at = :at,
                    days_late = DATEDIFF(DATE(:at2), due_date)
              WHERE id = :id",
            [':c' => $completionId, ':at' => $completedAt, ':at2' => $completedAt, ':id' => $dueInstanceId]
        );
        // In fixed-calendar mode the schedule runs off the due date, not the sign-off date.
        if (($req['schedule_mode'] ?? 'rolling') === 'fixed_calendar') {
            $inst = db_one('SELECT due_date FROM maintenance_due_instances WHERE id = :id', [':id' => $dueInstanceId]);
            if ($inst) {
                $anchorDate = $inst['due_date'];
            }
        }
    }

    if ((int) $req['is_active'] === 1) {
        $existing = due_open_instance($requirementId);
        if (!$existing) {
            due_create_instance($req,
                due_apply_start_floor($req, due_next_date($req, $anchorDate)), 'completion');
        }
    }
    due_refresh_requirement($requirementId);
}

/**
 * Project occurrences into a date range without persisting them.
 * Used by the calendar so a month view is not empty beyond the single open instance.
 *
 * @return array List of ['due_date' => Y-m-d, 'projected' => true]
 */
function due_engine_project(array $req, string $fromDate, string $toDate, int $max = 40): array
{
    $out  = [];
    $open = due_open_instance((int) $req['id']);
    $cursor = $open ? $open['due_date'] : ($req['next_due_date'] ?: due_initial_date($req));

    $guard = 0;
    while ($guard++ < $max) {
        $cursor = due_next_date($req, $cursor);
        if ($cursor > $toDate) {
            break;
        }
        if ($cursor >= $fromDate) {
            $out[] = ['due_date' => $cursor, 'projected' => true];
        }
    }
    return $out;
}

/* ==================================================================== */
/* Queries for the due list, dashboard and calendar                     */
/* ==================================================================== */

/**
 * @param array $f view (overdue|today|week|month|all), asset_id, location_id,
 *                 frequency, assignee_id, q, include_completed
 */
function due_list(array $f = []): array
{
    $today  = date('Y-m-d');
    $where  = ["d.status = 'scheduled'", "a.status <> 'retired'", 'r.is_active = 1'];
    $params = [];

    switch ($f['view'] ?? 'week') {
        case 'overdue': $where[] = 'd.due_date < CURDATE()'; break;
        case 'today':   $where[] = 'd.due_date <= CURDATE()'; break;
        case 'week':    $where[] = 'd.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)'; break;
        case 'month':   $where[] = 'd.due_date <= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)'; break;
        case 'all':     break;
    }
    if (!empty($f['asset_id'])) {
        $where[] = 'd.asset_id = :asset';
        $params[':asset'] = (int) $f['asset_id'];
    }
    if (!empty($f['location_id'])) {
        $where[] = 'a.location_id = :loc';
        $params[':loc'] = (int) $f['location_id'];
    }
    if (!empty($f['frequency'])) {
        $where[] = 'r.frequency = :freq';
        $params[':freq'] = $f['frequency'];
    }
    if (!empty($f['assignee_id'])) {
        $where[] = '(r.assigned_user_id = :who OR a.owner_user_id = :who2)';
        $params[':who']  = (int) $f['assignee_id'];
        $params[':who2'] = (int) $f['assignee_id'];
    }
    if (!empty($f['q'])) {
        $where[] = '(r.task_name LIKE :q OR a.name LIKE :q2 OR a.asset_tag LIKE :q3)';
        $like = '%' . $f['q'] . '%';
        $params += [':q' => $like, ':q2' => $like, ':q3' => $like];
    }

    return db_all(
        "SELECT d.id AS due_id, d.due_date, d.status AS due_status,
                r.id AS requirement_id, r.task_name, r.spec_note, r.frequency, r.interval_days,
                r.est_minutes, r.assigned_role, r.is_safety_critical, r.tools_consumables,
                a.id AS asset_id, a.asset_tag, a.name AS asset_name, a.short_name, a.status AS asset_status,
                l.name AS location_name, l.department,
                u.full_name AS assignee_name, o.full_name AS owner_name,
                DATEDIFF(CURDATE(), d.due_date) AS days_late,
                (SELECT COUNT(*) FROM requirement_steps s
                  WHERE s.requirement_id = r.id AND s.is_active = 1) AS step_count
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
           JOIN assets a                   ON a.id = d.asset_id
           LEFT JOIN locations l           ON l.id = a.location_id
           LEFT JOIN users u               ON u.id = r.assigned_user_id
           LEFT JOIN users o               ON o.id = a.owner_user_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY d.due_date ASC,
                   FIELD(r.frequency,'daily','weekly','monthly','quarterly','semiannual','annual','custom'),
                   a.asset_tag, r.sort_order",
        $params
    );
}

/** Counters for the due-list tabs and the dashboard. */
function due_stats(): array
{
    $row = db_one(
        "SELECT
            SUM(d.due_date <  CURDATE()) AS overdue,
            SUM(d.due_date =  CURDATE()) AS today,
            SUM(d.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))  AS week,
            SUM(d.due_date <= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)) AS month,
            COUNT(*) AS total
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id AND r.is_active = 1
           JOIN assets a ON a.id = d.asset_id AND a.status <> 'retired'
          WHERE d.status = 'scheduled'"
    ) ?: [];

    return [
        'overdue' => (int) ($row['overdue'] ?? 0),
        'today'   => (int) ($row['today'] ?? 0),
        'week'    => (int) ($row['week'] ?? 0),
        'month'   => (int) ($row['month'] ?? 0),
        'total'   => (int) ($row['total'] ?? 0),
    ];
}

/** Group a due list by asset then frequency, for the batch daily cards. */
function due_group_by_asset(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[$r['asset_id']]['asset'] = [
            'id'    => $r['asset_id'],
            'tag'   => $r['asset_tag'],
            'name'  => $r['asset_name'],
            'short' => $r['short_name'],
            'location' => $r['location_name'],
            'status'   => $r['asset_status'],
        ];
        $out[$r['asset_id']]['frequencies'][$r['frequency']][] = $r;
    }
    return $out;
}

/**
 * Page-load fallback for hosting where the cron did not fire.
 * Runs at most once per day and never blocks the request for long.
 */
function due_engine_maybe_run_web(): void
{
    if (!setting_bool('web_cron_fallback', true)) {
        return;
    }
    $lastOk = db_value(
        "SELECT MAX(started_at) FROM job_runs WHERE job_key IN ('scheduler','due_engine') AND status <> 'error'"
    );
    if ($lastOk && strtotime($lastOk) > strtotime('today 00:00:00')) {
        return;                                                 // already ran today
    }

    $runId = db_insert('job_runs', [
        'job_key' => 'due_engine', 'trigger_type' => 'web', 'status' => 'running',
    ]);
    try {
        $n = due_engine_generate();
        db_query("UPDATE job_runs SET finished_at = NOW(), status = 'ok', items_processed = :n,
                         message = 'page-load fallback' WHERE id = :id",
            [':n' => $n, ':id' => $runId]);
    } catch (Throwable $e) {
        db_query("UPDATE job_runs SET finished_at = NOW(), status = 'error', message = :m WHERE id = :id",
            [':m' => substr($e->getMessage(), 0, 900), ':id' => $runId]);
        error_log('web due engine failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// "Run Checks" support -- one fast checklist per frequency across all machines
// ---------------------------------------------------------------------

/** Tabs on the Run Checks screen and the frequencies each one covers. */
function run_tabs(): array
{
    return [
        'daily'     => ['label' => 'Daily',     'freqs' => ['daily'],                        'icon' => 'bi-sun'],
        'weekly'    => ['label' => 'Weekly',    'freqs' => ['weekly'],                       'icon' => 'bi-calendar-week'],
        'monthly'   => ['label' => 'Monthly',   'freqs' => ['monthly'],                      'icon' => 'bi-calendar-month'],
        'quarterly' => ['label' => 'Quarterly', 'freqs' => ['quarterly'],                    'icon' => 'bi-calendar3'],
        'other'     => ['label' => 'Semi-annual / Annual / Custom',
                        'freqs' => ['semiannual', 'annual', 'custom'],                       'icon' => 'bi-calendar-range'],
    ];
}

/** Open tasks per tab: ['daily' => ['due' => n, 'total' => n], ...] ("due" = due today or overdue). */
function run_counts(): array
{
    $out = [];
    foreach (run_tabs() as $k => $_) {
        $out[$k] = ['due' => 0, 'total' => 0];
    }
    $rows = db_all(
        "SELECT r.frequency, COUNT(*) AS total, SUM(d.due_date <= CURDATE()) AS due_now
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
           JOIN assets a ON a.id = d.asset_id
          WHERE d.status = 'scheduled' AND r.is_active = 1 AND a.status <> 'retired'
          GROUP BY r.frequency"
    );
    foreach ($rows as $row) {
        foreach (run_tabs() as $k => $t) {
            if (in_array($row['frequency'], $t['freqs'], true)) {
                $out[$k]['due']   += (int) $row['due_now'];
                $out[$k]['total'] += (int) $row['total'];
            }
        }
    }
    return $out;
}

/** Tasks for one tab, ordered by location then machine so the walk-round is in sequence. */
function run_rows(string $tab, bool $includeUpcoming = false): array
{
    $tabs = run_tabs();
    if (!isset($tabs[$tab])) {
        return [];
    }
    $ph = [];
    $params = [];
    foreach ($tabs[$tab]['freqs'] as $i => $f) {
        $ph[] = ':f' . $i;
        $params[':f' . $i] = $f;
    }
    return db_all(
        "SELECT d.id AS due_id, d.due_date, r.id AS requirement_id, r.task_name, r.spec_note,
                r.tools_consumables, r.est_minutes, r.is_safety_critical, r.frequency,
                a.id AS asset_id, a.asset_tag, a.name AS asset_name, a.status AS asset_status,
                l.name AS location_name,
                DATEDIFF(CURDATE(), d.due_date) AS days_late,
                (SELECT COUNT(*) FROM requirement_steps s
                  WHERE s.requirement_id = r.id AND s.is_active = 1) AS step_count
           FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
           JOIN assets a                   ON a.id = d.asset_id
           LEFT JOIN locations l           ON l.id = a.location_id
          WHERE d.status = 'scheduled' AND r.is_active = 1 AND a.status <> 'retired'
            AND r.frequency IN (" . implode(',', $ph) . ")"
            . ($includeUpcoming ? '' : ' AND d.due_date <= CURDATE()') . "
          ORDER BY l.name, a.asset_tag, r.sort_order, r.id",
        $params
    );
}
