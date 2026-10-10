<?php
/** PDO connection + thin query helpers. */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
        ]);
    } catch (PDOException $e) {
        error_log('DB connect failed: ' . $e->getMessage());

        // The installer and the System Check page need to report the failure,
        // not be killed by it.
        if (defined('INSTALLER') || defined('DIAGNOSTICS')) {
            throw $e;
        }
        if (APP_ENV === 'development') {
            die('Database connection failed: ' . $e->getMessage());
        }
        http_response_code(503);
        die('<h1>Database unavailable</h1><p>The maintenance database could not be reached. '
          . 'If this persists, contact the system administrator.</p>');
    }
    return $pdo;
}

/** Run a prepared statement and return the PDOStatement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/** First row, or null. */
function db_one(string $sql, array $params = [])
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** Single scalar from the first column of the first row. */
function db_value(string $sql, array $params = [], $default = null)
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}

/** INSERT from an associative array; returns the new id. */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', array_map(fn($c) => "`$c`", $cols)),
        implode(', ', array_map(fn($c) => ":$c", $cols))
    );
    db_query($sql, $data);
    return (int) db()->lastInsertId();
}

/** UPDATE from an associative array with a simple `id = ?` where clause. */
function db_update(string $table, array $data, int $id): int
{
    if (!$data) {
        return 0;
    }
    $sets = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
    $data['__id'] = $id;
    $st = db_query("UPDATE `$table` SET $sets WHERE id = :__id", $data);
    return $st->rowCount();
}
