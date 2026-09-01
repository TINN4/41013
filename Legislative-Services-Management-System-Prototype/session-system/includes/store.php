<?php
/**
 * Real database backend: MySQL via PDO.
 *
 * Connection settings live in includes/db_config.php — edit that file
 * with your actual host/database/username/password before first use.
 *
 * Each logical "table" (sessions, agenda, members, ...) is a real MySQL
 * table (InnoDB, id INT AUTO_INCREMENT PRIMARY KEY, data JSON). The full
 * row is kept as a JSON value in `data` so every existing call site —
 * which reads and writes plain associative arrays with whatever fields
 * it wants (e.g. `minutes` has nested present/absent/excused/agenda/log
 * arrays that don't fit flat columns) — keeps working unchanged. This is
 * why nothing below ssms_read()/ssms_write() (find/insert/update/delete/
 * where) had to change at all. See data/schema.sql for the same schema
 * as plain SQL, if you'd rather create the tables yourself.
 */

require_once __DIR__ . '/db_config.php';

function ssms_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . SSMS_DB_HOST . ';port=' . SSMS_DB_PORT . ';dbname=' . SSMS_DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, SSMS_DB_USER, SSMS_DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function ssms_table_name($table) {
    // Table names in this codebase are always hardcoded string literals
    // (e.g. 'sessions', 'agenda'), never user input — this check is just
    // defense in depth against a typo/mistake ever reaching raw SQL.
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
        throw new InvalidArgumentException('Invalid table name: ' . $table);
    }
    return $table;
}

function ssms_ensure_table($pdo, $table) {
    $t = ssms_table_name($table);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$t` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        data JSON NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ssms_read($table) {
    $pdo = ssms_db();
    ssms_ensure_table($pdo, $table);
    $t = ssms_table_name($table);
    $stmt = $pdo->query("SELECT id, data FROM `$t` ORDER BY id");
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $row = json_decode($r['data'], true);
        if (!is_array($row)) $row = [];
        $row['id'] = (int)$r['id']; // id column is the source of truth
        $rows[] = $row;
    }
    return $rows;
}

function ssms_write($table, $data) {
    $pdo = ssms_db();
    ssms_ensure_table($pdo, $table);
    $t = ssms_table_name($table);

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM `$t`");
        $insert = $pdo->prepare("INSERT INTO `$t` (id, data) VALUES (:id, :data)");
        foreach ($data as $row) {
            if (!isset($row['id'])) continue; // matches original file-store behaviour (id required)
            $id = (int)$row['id'];
            $payload = $row;
            unset($payload['id']); // id lives in its own column, not duplicated in the JSON blob
            $insert->execute([':id' => $id, ':data' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ssms_next_id($rows) {
    $max = 0;
    foreach ($rows as $r) { if (isset($r['id']) && $r['id'] > $max) $max = $r['id']; }
    return $max + 1;
}

function ssms_find($table, $id) {
    $pdo = ssms_db();
    ssms_ensure_table($pdo, $table);
    $t = ssms_table_name($table);
    $stmt = $pdo->prepare("SELECT data FROM `$t` WHERE id = :id");
    $stmt->execute([':id' => (int)$id]);
    $data = $stmt->fetchColumn();
    if ($data === false) return null;
    $row = json_decode($data, true);
    if (!is_array($row)) $row = [];
    $row['id'] = (int)$id;
    return $row;
}

// --- Targeted single-row operations ----------------------------------
// IMPORTANT: these do NOT go through ssms_read()+ssms_write() (read the
// whole table, delete every row, reinsert every row). That pattern had a
// serious bug — under any concurrent use (e.g. two council members'
// devices marking attendance within the same second, which is completely
// normal usage for this app) the request that commits last would wipe out
// whatever the other one just wrote, because both work from a full-table
// snapshot taken before either write lands. These versions touch only the
// one row involved, so concurrent inserts/updates/deletes on different
// rows can never step on each other, and updates to the SAME row are
// protected by a row lock (SELECT ... FOR UPDATE inside a transaction)
// so a concurrent update to that specific row queues instead of racing.

function ssms_insert($table, $row) {
    $pdo = ssms_db();
    ssms_ensure_table($pdo, $table);
    $t = ssms_table_name($table);
    $payload = $row;
    unset($payload['id']); // id lives in its own auto-increment column, not duplicated in the JSON blob
    $stmt = $pdo->prepare("INSERT INTO `$t` (data) VALUES (:data)");
    $stmt->execute([':data' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    return (int)$pdo->lastInsertId();
}

function ssms_update($table, $id, $changes) {
    $pdo = ssms_db();
    ssms_ensure_table($pdo, $table);
    $t = ssms_table_name($table);

    $pdo->beginTransaction();
    try {
        // FOR UPDATE takes a row lock for the rest of this transaction, so
        // a second concurrent ssms_update() on this exact row waits its
        // turn instead of both reading the same stale snapshot and one
        // silently overwriting the other's change.
        $stmt = $pdo->prepare("SELECT data FROM `$t` WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => (int)$id]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            $pdo->rollBack();
            return; // row doesn't exist (already deleted, or bad id) — nothing to update, same as the old silent-no-op behavior
        }
        $row = json_decode($current, true);
        if (!is_array($row)) $row = [];
        $row = array_merge($row, $changes);
        unset($row['id']);
        $upd = $pdo->prepare("UPDATE `$t` SET data = :data WHERE id = :id");
        $upd->execute([':data' => json_encode($row, JSON_UNESCAPED_SLASHES), ':id' => (int)$id]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ssms_delete($table, $id) {
    $pdo = ssms_db();
    ssms_ensure_table($pdo, $table);
    $t = ssms_table_name($table);
    $stmt = $pdo->prepare("DELETE FROM `$t` WHERE id = :id");
    $stmt->execute([':id' => (int)$id]);
}

function ssms_where($table, $field, $value) {
    return array_values(array_filter(ssms_read($table), function ($r) use ($field, $value) {
        return isset($r[$field]) && $r[$field] == $value;
    }));
}

function ssms_e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
