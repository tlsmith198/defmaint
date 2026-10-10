<?php
/**
 * Deletion with guardrails.
 *
 * The AS9100 rule is that a *live* system never destroys objective evidence. While the
 * system is still being dialled in, that rule gets in the way — test tasks and trial
 * sign-offs are not evidence of anything.
 *
 * So deletion follows two tiers:
 *
 *   Always allowed   anything with no completion history behind it. A requirement nobody
 *                    has ever signed off carries no evidence, so removing it destroys none.
 *
 *   Setup mode only  anything that *does* carry history. Admin → Settings → General has a
 *                    "Setup mode" switch. While it is on, an administrator can force-delete
 *                    tasks, machines and sign-off records. Turning it off locks the record,
 *                    and from then on history can only be retired, never deleted.
 *
 * Every deletion — both tiers — writes a full snapshot of what was removed into audit_log
 * first, so the audit trail shows the deletion itself even though the rows are gone.
 */

/** Is the system still in pre-go-live setup mode? */
function setup_mode(): bool
{
    return setting_bool('setup_mode', true);
}

/** Human explanation used in several places. */
function setup_mode_note(): string
{
    return setup_mode()
        ? 'Setup mode is on, so records with history can still be deleted.'
        : 'The record is locked. Tasks with sign-off history can only be retired, not deleted.';
}

/* ==================================================================== */
/* What stands in the way of a delete                                   */
/* ==================================================================== */

/** @return array ['completions' => int, 'issues' => int, 'blocked' => bool, 'reason' => string] */
function purge_requirement_blockers(int $requirementId): array
{
    $completions = (int) db_value(
        'SELECT COUNT(*) FROM maintenance_completions WHERE requirement_id = :r',
        [':r' => $requirementId], 0
    );
    $issues = (int) db_value(
        'SELECT COUNT(*) FROM issues WHERE requirement_id = :r',
        [':r' => $requirementId], 0
    );

    $blocked = $completions > 0 && !setup_mode();

    return [
        'completions' => $completions,
        'issues'      => $issues,
        'blocked'     => $blocked,
        'reason'      => $blocked
            ? 'This task has ' . $completions . ' sign-off record'
              . ($completions === 1 ? '' : 's') . '. Retire it instead, or switch Setup mode '
              . 'back on in Settings if you are still configuring the system.'
            : '',
    ];
}

function purge_asset_blockers(int $assetId): array
{
    $completions = (int) db_value(
        'SELECT COUNT(*) FROM maintenance_completions WHERE asset_id = :a', [':a' => $assetId], 0
    );
    $issues = (int) db_value(
        'SELECT COUNT(*) FROM issues WHERE asset_id = :a', [':a' => $assetId], 0
    );
    $blocked = ($completions > 0 || $issues > 0) && !setup_mode();

    return [
        'completions' => $completions,
        'issues'      => $issues,
        'blocked'     => $blocked,
        'reason'      => $blocked
            ? 'This machine carries ' . $completions . ' sign-off record'
              . ($completions === 1 ? '' : 's') . ' and ' . $issues . ' issue'
              . ($issues === 1 ? '' : 's') . '. Set it to Retired instead — that keeps the '
              . 'history and takes it off the schedule.'
            : '',
    ];
}

/* ==================================================================== */
/* Helpers                                                              */
/* ==================================================================== */

/** Remove attachment rows for an entity and best-effort unlink the files. */
function purge_attachments(string $entityType, array $entityIds): int
{
    if (!$entityIds) {
        return 0;
    }
    $in   = implode(',', array_map('intval', $entityIds));
    $rows = db_all(
        "SELECT id, file_path FROM attachments WHERE entity_type = :t AND entity_id IN ($in)",
        [':t' => $entityType]
    );
    foreach ($rows as $r) {
        $path = rtrim(UPLOAD_PATH, '/\\') . '/' . ltrim((string) $r['file_path'], '/\\');
        if ($r['file_path'] && is_file($path)) {
            @unlink($path);
        }
    }
    db_query("DELETE FROM attachments WHERE entity_type = :t AND entity_id IN ($in)", [':t' => $entityType]);

    return count($rows);
}

/** Delete completion records and everything hanging off them. */
function purge_completions(array $completionIds): int
{
    if (!$completionIds) {
        return 0;
    }
    $in = implode(',', array_map('intval', $completionIds));
    purge_attachments('completion', $completionIds);
    db_query("UPDATE issues SET completion_id = NULL WHERE completion_id IN ($in)");
    // completion_step_results and completion_revisions cascade on delete.
    db_query("DELETE FROM maintenance_completions WHERE id IN ($in)");

    return count($completionIds);
}

/** Delete issues and their threads. */
function purge_issues(array $issueIds): int
{
    if (!$issueIds) {
        return 0;
    }
    $in = implode(',', array_map('intval', $issueIds));
    purge_attachments('issue', $issueIds);
    db_query("UPDATE maintenance_completions SET issue_id = NULL WHERE issue_id IN ($in)");
    // issue_notes and issue_status_history cascade on delete.
    db_query("DELETE FROM issues WHERE id IN ($in)");

    return count($issueIds);
}

/* ==================================================================== */
/* Requirements                                                         */
/* ==================================================================== */

/**
 * Permanently delete one maintenance requirement.
 *
 * @return array [bool $ok, string $message]
 */
function purge_requirement(int $requirementId): array
{
    $req = db_one(
        'SELECT r.*, a.asset_tag, a.name AS asset_name
           FROM maintenance_requirements r JOIN assets a ON a.id = r.asset_id
          WHERE r.id = :id',
        [':id' => $requirementId]
    );
    if (!$req) {
        return [false, 'That task no longer exists.'];
    }

    $blockers = purge_requirement_blockers($requirementId);
    if ($blockers['blocked']) {
        return [false, $blockers['reason']];
    }

    // Snapshot first: the audit trail keeps a record of what was removed.
    $steps = db_all('SELECT step_no, step_text, response_type, value_unit, value_min, value_max
                       FROM requirement_steps WHERE requirement_id = :r ORDER BY step_no',
        [':r' => $requirementId]);
    audit('requirement.delete', 'requirement', $requirementId, [
        'asset'        => $req['asset_tag'] . ' ' . $req['asset_name'],
        'task_name'    => $req['task_name'],
        'frequency'    => $req['frequency'],
        'interval_days' => $req['interval_days'],
        'spec_note'    => $req['spec_note'],
        'steps'        => $steps,
        'completions_deleted' => (int) $blockers['completions'],
        'setup_mode'   => setup_mode(),
    ]);

    db()->beginTransaction();
    try {
        if ($blockers['completions'] > 0) {
            $ids = array_column(
                db_all('SELECT id FROM maintenance_completions WHERE requirement_id = :r',
                    [':r' => $requirementId]),
                'id'
            );
            purge_completions($ids);
        }
        // Issues raised from this task survive; they just lose the back-reference.
        db_query('UPDATE issues SET requirement_id = NULL, due_instance_id = NULL
                   WHERE requirement_id = :r', [':r' => $requirementId]);

        // requirement_steps and maintenance_due_instances cascade on delete.
        db_query('DELETE FROM maintenance_requirements WHERE id = :id', [':id' => $requirementId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('purge_requirement failed: ' . $e->getMessage());
        return [false, 'The task could not be deleted: ' . $e->getMessage()];
    }

    $extra = $blockers['completions'] > 0
        ? ' (' . $blockers['completions'] . ' sign-off record'
          . ($blockers['completions'] === 1 ? '' : 's') . ' removed with it)'
        : '';

    return [true, 'Deleted "' . $req['task_name'] . '"' . $extra . '.'];
}

/**
 * Delete several requirements, skipping any that are protected.
 *
 * @return array ['deleted' => int, 'skipped' => int, 'messages' => string[]]
 */
function purge_requirements(array $requirementIds): array
{
    $deleted = $skipped = 0;
    $messages = [];

    foreach (array_unique(array_map('intval', $requirementIds)) as $id) {
        if ($id <= 0) {
            continue;
        }
        [$ok, $msg] = purge_requirement($id);
        if ($ok) {
            $deleted++;
        } else {
            $skipped++;
            if (count($messages) < 4) {
                $messages[] = $msg;
            }
        }
    }

    return ['deleted' => $deleted, 'skipped' => $skipped, 'messages' => $messages];
}

/* ==================================================================== */
/* Assets                                                               */
/* ==================================================================== */

function purge_asset(int $assetId): array
{
    $asset = asset_get($assetId);
    if (!$asset) {
        return [false, 'That machine no longer exists.'];
    }

    $blockers = purge_asset_blockers($assetId);
    if ($blockers['blocked']) {
        return [false, $blockers['reason']];
    }

    $reqCount = (int) db_value('SELECT COUNT(*) FROM maintenance_requirements WHERE asset_id = :a',
        [':a' => $assetId], 0);

    audit('asset.delete', 'asset', $assetId, [
        'asset_tag'    => $asset['asset_tag'],
        'name'         => $asset['name'],
        'manufacturer' => $asset['manufacturer'],
        'model'        => $asset['model'],
        'serial_number' => $asset['serial_number'],
        'requirements_deleted' => $reqCount,
        'completions_deleted'  => (int) $blockers['completions'],
        'issues_deleted'       => (int) $blockers['issues'],
        'setup_mode'   => setup_mode(),
    ]);

    db()->beginTransaction();
    try {
        $completionIds = array_column(
            db_all('SELECT id FROM maintenance_completions WHERE asset_id = :a', [':a' => $assetId]), 'id'
        );
        purge_completions($completionIds);

        $issueIds = array_column(
            db_all('SELECT id FROM issues WHERE asset_id = :a', [':a' => $assetId]), 'id'
        );
        purge_issues($issueIds);

        purge_attachments('asset', [$assetId]);
        if (!empty($asset['photo_path'])) {
            $photo = rtrim(UPLOAD_PATH, '/\\') . '/' . ltrim((string) $asset['photo_path'], '/\\');
            if (is_file($photo)) {
                @unlink($photo);
            }
        }

        // asset_specs, maintenance_requirements (and their steps and due
        // instances) all cascade on delete.
        db_query('DELETE FROM assets WHERE id = :id', [':id' => $assetId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('purge_asset failed: ' . $e->getMessage());
        return [false, 'The machine could not be deleted: ' . $e->getMessage()];
    }

    return [true, 'Deleted ' . $asset['asset_tag'] . ' — ' . $asset['name']
        . ' along with ' . $reqCount . ' maintenance task' . ($reqCount === 1 ? '' : 's') . '.'];
}

/* ==================================================================== */
/* Completion records                                                   */
/* ==================================================================== */

/**
 * Delete one sign-off record. Setup mode only — this is objective evidence
 * once the system is live.
 */
function purge_completion(int $completionId): array
{
    if (!setup_mode()) {
        return [false, 'Sign-off records cannot be deleted once the record is locked. '
                     . 'Use "Correct this record" instead — it keeps the original values in the '
                     . 'revision history.'];
    }

    $c = db_one(
        'SELECT mc.*, a.asset_tag, u.full_name AS completed_by_name
           FROM maintenance_completions mc
           JOIN assets a ON a.id = mc.asset_id
           JOIN users u  ON u.id = mc.completed_by
          WHERE mc.id = :id',
        [':id' => $completionId]
    );
    if (!$c) {
        return [false, 'That record no longer exists.'];
    }

    audit('completion.delete', 'completion', $completionId, [
        'asset'        => $c['asset_tag'],
        'task'         => $c['task_name_snapshot'],
        'result'       => $c['result'],
        'completed_at' => $c['completed_at'],
        'completed_by' => $c['completed_by_name'],
        'notes'        => $c['notes'],
        'setup_mode'   => true,
    ]);

    db()->beginTransaction();
    try {
        // Put the occurrence back on the schedule so the task reappears as due.
        if ($c['due_instance_id']) {
            db_query("UPDATE maintenance_due_instances
                         SET status = 'scheduled', completion_id = NULL,
                             completed_at = NULL, days_late = NULL
                       WHERE id = :id", [':id' => $c['due_instance_id']]);
        }
        purge_completions([$completionId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('purge_completion failed: ' . $e->getMessage());
        return [false, 'The record could not be deleted: ' . $e->getMessage()];
    }

    due_refresh_requirement((int) $c['requirement_id']);

    return [true, 'Deleted the sign-off record for "' . $c['task_name_snapshot']
        . '". The task is back on the schedule.'];
}

/* ==================================================================== */
/* Schedule housekeeping (destroys no history)                          */
/* ==================================================================== */

/**
 * Throw away unsigned occurrences and rebuild them from the current
 * requirement definitions. Nothing that has been signed off is touched.
 *
 * Handy after changing a pile of frequencies: the schedule re-spreads instead
 * of keeping dates that were worked out from the old intervals.
 *
 * @param int|null $assetId Limit to one machine, or null for the whole fleet.
 */
function purge_reset_schedule(?int $assetId = null): array
{
    $params = [];
    $where  = ["status IN ('scheduled','missed')", 'completion_id IS NULL'];
    if ($assetId) {
        $where[] = 'asset_id = :a';
        $params[':a'] = $assetId;
    }

    $removed = db_query('DELETE FROM maintenance_due_instances WHERE ' . implode(' AND ', $where), $params)
        ->rowCount();

    db_query('UPDATE maintenance_requirements SET next_due_date = NULL'
        . ($assetId ? ' WHERE asset_id = :a' : ''), $assetId ? [':a' => $assetId] : []);

    $created = due_engine_generate();

    audit('schedule.reset', $assetId ? 'asset' : 'fleet', $assetId, [
        'instances_removed' => $removed, 'instances_created' => $created,
    ]);

    return [true, 'Cleared ' . $removed . ' unsigned occurrence' . ($removed === 1 ? '' : 's')
        . ' and rebuilt the schedule (' . $created . ' created). Sign-off history was not touched.'];
}

/**
 * Set the go-live date and rebuild the schedule around it.
 *
 * This is the fix for "the system has been installed for six weeks and the floor
 * is now looking at 600 overdue checks". Unsigned occurrences — including the
 * missed ones racked up before anyone was using it — are cleared, and every task
 * is re-seeded from the chosen date with the usual stagger so the first week is
 * not a wall of work.
 *
 * Sign-off records are never touched.
 *
 * @param string $date Y-m-d, or '' to clear the go-live date entirely.
 */
function purge_set_go_live(string $date): array
{
    $date = trim($date);

    if ($date !== '') {
        $ts = strtotime($date);
        if (!$ts) {
            return [false, 'That is not a valid date.'];
        }
        $date = date('Y-m-d', $ts);
        if ($date < date('Y-m-d', strtotime('-5 years'))
            || $date > date('Y-m-d', strtotime('+5 years'))) {
            return [false, 'Pick a date within the next few years.'];
        }
    }

    $before = (int) db_value(
        "SELECT COUNT(*) FROM maintenance_due_instances d
           JOIN maintenance_requirements r ON r.id = d.requirement_id
          WHERE d.status = 'scheduled' AND d.due_date < CURDATE()", [], 0
    );
    $missedBefore = (int) db_value(
        "SELECT COUNT(*) FROM maintenance_due_instances WHERE status = 'missed'", [], 0
    );

    setting_set('schedule_start_date', $date, 'schedule');

    // Clear everything nobody signed off, then rebuild from the new anchor.
    $removed = db_query(
        "DELETE FROM maintenance_due_instances
          WHERE status IN ('scheduled','missed','cancelled') AND completion_id IS NULL"
    )->rowCount();
    db_query('UPDATE maintenance_requirements SET next_due_date = NULL');

    $created = due_engine_generate();

    $overdueNow = (int) db_value(
        "SELECT COUNT(*) FROM maintenance_due_instances
          WHERE status = 'scheduled' AND due_date < CURDATE()", [], 0
    );

    audit('schedule.go_live', 'fleet', null, [
        'go_live_date'      => $date ?: '(cleared)',
        'overdue_before'    => $before,
        'missed_cleared'    => $missedBefore,
        'instances_removed' => $removed,
        'instances_created' => $created,
        'overdue_after'     => $overdueNow,
    ]);

    if ($date === '') {
        return [true, 'Go-live date cleared. The schedule now runs from today — '
            . $created . ' occurrence' . ($created === 1 ? '' : 's') . ' scheduled.'];
    }

    $when = $date > date('Y-m-d')
        ? 'Nothing comes due until then.'
        : 'Dates run from that day forward.';

    return [true, 'Go-live set to ' . fmt_date($date) . '. Cleared ' . $before
        . ' overdue item' . ($before === 1 ? '' : 's') . ' and ' . $missedBefore
        . ' missed record' . ($missedBefore === 1 ? '' : 's') . ', then rebuilt the schedule ('
        . $created . ' occurrence' . ($created === 1 ? '' : 's') . '). ' . $when
        . ' No sign-off history was affected.'];
}

/**
 * Delete every trial sign-off record. Setup mode only.
 * For clearing out the records made while testing before going live.
 */
function purge_all_completions(?int $assetId = null): array
{
    if (!setup_mode()) {
        return [false, 'Sign-off records cannot be bulk-deleted once the record is locked.'];
    }

    $ids = array_column(
        db_all('SELECT id FROM maintenance_completions'
            . ($assetId ? ' WHERE asset_id = :a' : ''), $assetId ? [':a' => $assetId] : []),
        'id'
    );
    if (!$ids) {
        return [false, 'There are no sign-off records to clear.'];
    }

    audit('completion.delete_all', $assetId ? 'asset' : 'fleet', $assetId, [
        'records_deleted' => count($ids), 'setup_mode' => true,
    ]);

    db()->beginTransaction();
    try {
        $in = implode(',', array_map('intval', $ids));
        db_query("UPDATE maintenance_due_instances
                     SET status = 'scheduled', completion_id = NULL, completed_at = NULL, days_late = NULL
                   WHERE completion_id IN ($in)");
        purge_completions($ids);
        db_query('UPDATE maintenance_requirements SET last_completed_at = NULL'
            . ($assetId ? ' WHERE asset_id = :a' : ''), $assetId ? [':a' => $assetId] : []);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        error_log('purge_all_completions failed: ' . $e->getMessage());
        return [false, 'Could not clear the records: ' . $e->getMessage()];
    }

    return [true, 'Deleted ' . count($ids) . ' trial sign-off record'
        . (count($ids) === 1 ? '' : 's') . '.'];
}

/** Counts for the Settings data-tools panel. */
function purge_data_summary(): array
{
    return [
        'assets'       => (int) db_value('SELECT COUNT(*) FROM assets', [], 0),
        'requirements' => (int) db_value('SELECT COUNT(*) FROM maintenance_requirements', [], 0),
        'retired'      => (int) db_value('SELECT COUNT(*) FROM maintenance_requirements WHERE is_active = 0', [], 0),
        'instances'    => (int) db_value("SELECT COUNT(*) FROM maintenance_due_instances WHERE status = 'scheduled'", [], 0),
        'completions'  => (int) db_value('SELECT COUNT(*) FROM maintenance_completions', [], 0),
        'issues'       => (int) db_value('SELECT COUNT(*) FROM issues', [], 0),
    ];
}
