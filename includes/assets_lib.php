<?php
/** Asset register queries shared by the list, detail and dashboard views. */

/**
 * Asset list with derived "next PM due" and "open issues" columns.
 *
 * @param array $f  status, category_id, location_id, owner_id, q, sort, dir, due (overdue|week), page, per_page
 */
function asset_list(array $f = []): array
{
    $where  = [];
    $params = [];

    if (!empty($f['status'])) {
        $where[] = 'a.status = :status';
        $params[':status'] = $f['status'];
    } elseif (empty($f['include_retired'])) {
        $where[] = "a.status <> 'retired'";
    }
    if (!empty($f['category_id'])) {
        $where[] = 'a.category_id = :cat';
        $params[':cat'] = (int) $f['category_id'];
    }
    if (!empty($f['location_id'])) {
        $where[] = 'a.location_id = :loc';
        $params[':loc'] = (int) $f['location_id'];
    }
    if (!empty($f['owner_id'])) {
        $where[] = 'a.owner_user_id = :own';
        $params[':own'] = (int) $f['owner_id'];
    }
    if (!empty($f['q'])) {
        $where[] = '(a.name LIKE :q OR a.asset_tag LIKE :q2 OR a.manufacturer LIKE :q3
                     OR a.model LIKE :q4 OR a.serial_number LIKE :q5 OR a.short_name LIKE :q6)';
        $like = '%' . $f['q'] . '%';
        $params += [':q' => $like, ':q2' => $like, ':q3' => $like,
                    ':q4' => $like, ':q5' => $like, ':q6' => $like];
    }

    // Derived columns are filtered in an outer query -- HAVING without GROUP BY
    // behaves inconsistently across MySQL sql_mode settings.
    $outerWhere = '';
    if (($f['due'] ?? '') === 'overdue') {
        $outerWhere = 'WHERE x.next_due IS NOT NULL AND x.next_due < CURDATE()';
    } elseif (($f['due'] ?? '') === 'week') {
        $outerWhere = 'WHERE x.next_due IS NOT NULL AND x.next_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
    } elseif (($f['due'] ?? '') === 'issues') {
        $outerWhere = 'WHERE x.open_issues > 0';
    }

    $sortable = [
        'tag'      => 'x.asset_tag',
        'name'     => 'x.name',
        'category' => 'x.category_name',
        'location' => 'x.location_name',
        'status'   => 'x.status',
        'due'      => 'x.next_due',
        'issues'   => 'x.open_issues',
        'year'     => 'x.year_built',
    ];
    $sort = $sortable[$f['sort'] ?? 'tag'] ?? 'x.asset_tag';
    $dir  = (strtolower($f['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

    $inner = "
        SELECT a.*, c.name AS category_name, l.name AS location_name,
               l.department, u.full_name AS owner_name,
               (SELECT MIN(d.due_date) FROM maintenance_due_instances d
                  JOIN maintenance_requirements r ON r.id = d.requirement_id
                 WHERE d.asset_id = a.id AND d.status = 'scheduled' AND r.is_active = 1) AS next_due,
               (SELECT COUNT(*) FROM issues i
                 WHERE i.asset_id = a.id AND i.status IN ('open','acknowledged','in_progress')) AS open_issues,
               (SELECT COUNT(*) FROM issues i2
                 WHERE i2.asset_id = a.id AND i2.status IN ('open','acknowledged','in_progress')
                   AND i2.severity = 'down') AS down_issues,
               (SELECT COUNT(*) FROM maintenance_requirements r2
                 WHERE r2.asset_id = a.id AND r2.is_active = 1) AS req_count
          FROM assets a
          LEFT JOIN asset_categories c ON c.id = a.category_id
          LEFT JOIN locations l        ON l.id = a.location_id
          LEFT JOIN users u            ON u.id = a.owner_user_id
        " . ($where ? 'WHERE ' . implode(' AND ', $where) : '');

    $sql = "SELECT x.* FROM ($inner) x $outerWhere ORDER BY $sort $dir, x.asset_tag ASC";

    return db_all($sql, $params);
}

function asset_get(int $id): ?array
{
    return db_one(
        'SELECT a.*, c.name AS category_name, l.name AS location_name, l.department,
                u.full_name AS owner_name, u.email AS owner_email,
                cu.full_name AS created_by_name, uu.full_name AS updated_by_name
           FROM assets a
           LEFT JOIN asset_categories c ON c.id = a.category_id
           LEFT JOIN locations l        ON l.id = a.location_id
           LEFT JOIN users u            ON u.id = a.owner_user_id
           LEFT JOIN users cu           ON cu.id = a.created_by
           LEFT JOIN users uu           ON uu.id = a.updated_by
          WHERE a.id = :id',
        [':id' => $id]
    );
}

function asset_get_by_tag(string $tag): ?array
{
    return db_one('SELECT * FROM assets WHERE asset_tag = :t', [':t' => $tag]);
}

function asset_specs(int $assetId): array
{
    $rows = db_all(
        'SELECT * FROM asset_specs WHERE asset_id = :id ORDER BY sort_order, id',
        [':id' => $assetId]
    );
    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['spec_group']][] = $r;
    }
    return $grouped;
}

/** Requirement counts by frequency for one asset. */
function asset_requirement_summary(int $assetId): array
{
    $rows = db_all(
        'SELECT frequency, COUNT(*) AS n FROM maintenance_requirements
          WHERE asset_id = :id AND is_active = 1 GROUP BY frequency',
        [':id' => $assetId]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[$r['frequency']] = (int) $r['n'];
    }
    return $out;
}

/** Combined PM + issue history, newest first, for the asset detail History tab. */
function asset_history(int $assetId, int $limit = 200): array
{
    $sql = "
        (SELECT 'completion' AS kind, mc.id AS ref_id, mc.completed_at AS happened_at,
                mc.task_name_snapshot AS title, mc.result AS state,
                mc.frequency_snapshot AS meta, mc.notes AS body, u.full_name AS actor
           FROM maintenance_completions mc
           JOIN users u ON u.id = mc.completed_by
          WHERE mc.asset_id = :a1)
        UNION ALL
        (SELECT 'issue' AS kind, i.id AS ref_id, i.reported_at AS happened_at,
                CONCAT(i.issue_no, ' — ', i.title) AS title, i.status AS state,
                i.severity AS meta, i.description AS body, u2.full_name AS actor
           FROM issues i
           JOIN users u2 ON u2.id = i.reported_by
          WHERE i.asset_id = :a2)
        ORDER BY happened_at DESC
        LIMIT " . (int) $limit;

    return db_all($sql, [':a1' => $assetId, ':a2' => $assetId]);
}

function asset_options(bool $includeRetired = false): array
{
    return db_all(
        'SELECT id, asset_tag, name FROM assets '
        . ($includeRetired ? '' : "WHERE status <> 'retired' ")
        . 'ORDER BY asset_tag'
    );
}

function category_options(): array
{
    return db_all('SELECT id, name FROM asset_categories WHERE is_active = 1 ORDER BY sort_order, name');
}

function location_options(): array
{
    return db_all('SELECT id, name, department FROM locations WHERE is_active = 1 ORDER BY sort_order, name');
}

/** Change asset status with an audit trail entry. */
function asset_set_status(int $assetId, string $status, ?string $note = null): void
{
    $before = db_one('SELECT status, status_note FROM assets WHERE id = :id', [':id' => $assetId]);
    if (!$before || !array_key_exists($status, asset_statuses())) {
        return;
    }
    db_query(
        'UPDATE assets SET status = :s, status_note = :n, status_changed_at = NOW(), updated_by = :u
          WHERE id = :id',
        [':s' => $status, ':n' => nn($note), ':u' => current_user_id(), ':id' => $assetId]
    );
    audit('asset.status_change', 'asset', $assetId, [
        'from' => $before['status'], 'to' => $status, 'note' => $note,
    ]);
}
