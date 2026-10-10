<?php
/**
 * Scheduled job entry point.
 *
 * cPanel cron (nightly):
 *   /usr/local/bin/php /home/<cpuser>/public_html/maintenance/cron/run.php <cron_token>
 *
 * Or URL cron:
 *   curl -s "https://example.com/maintenance/cron/run.php?token=<cron_token>"
 *
 * Jobs are added here as each phase lands:
 *   due_engine      - generate upcoming maintenance_due_instances   (phase 4)
 *   reminder_sweep  - PM due/overdue and stale-issue reminders      (phase 6)
 *   mail_queue      - flush queued notification_log rows            (phase 6)
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$cli   = PHP_SAPI === 'cli';
$token = $cli ? ($argv[1] ?? '') : (string) ($_GET['token'] ?? '');
$expected = (string) setting('cron_token', defined('CRON_TOKEN') ? CRON_TOKEN : '');

if ($expected === '' || !hash_equals($expected, $token)) {
    if (!$cli) {
        http_response_code(403);
    }
    fwrite($cli ? STDERR : fopen('php://output', 'w'), "Invalid cron token.\n");
    exit(1);
}

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

$runId = db_insert('job_runs', [
    'job_key'      => 'scheduler',
    'trigger_type' => $cli ? 'cron' : 'web',
    'status'       => 'running',
]);

$processed = 0;
$messages  = [];

try {
    // ---- phase 4: due engine ----------------------------------------
    if (is_file(APP_ROOT . '/includes/due_engine.php')) {
        require_once APP_ROOT . '/includes/due_engine.php';
        $processed += due_engine_generate();
        $messages[] = 'due engine ok';
    } else {
        $messages[] = 'due engine not installed yet (phase 4)';
    }

    // ---- phase 6: notifications -------------------------------------
    if (is_file(APP_ROOT . '/includes/notify.php')) {
        require_once APP_ROOT . '/includes/notify.php';
        $processed += notify_sweep();
        $messages[] = 'notification sweep ok';
    } else {
        $messages[] = 'notification engine not installed yet (phase 6)';
    }

    db_query('UPDATE job_runs SET finished_at = NOW(), status = "ok", items_processed = :n, message = :m
               WHERE id = :id',
        [':n' => $processed, ':m' => implode('; ', $messages), ':id' => $runId]);
    echo "OK - " . implode('; ', $messages) . " ({$processed} items)\n";
} catch (Throwable $e) {
    db_query('UPDATE job_runs SET finished_at = NOW(), status = "error", message = :m WHERE id = :id',
        [':m' => substr($e->getMessage(), 0, 900), ':id' => $runId]);
    error_log('cron failed: ' . $e->getMessage());
    echo "ERROR - " . $e->getMessage() . "\n";
    exit(1);
}
