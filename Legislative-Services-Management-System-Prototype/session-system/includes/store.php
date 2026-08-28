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
    foreach (ssms_read($table) as $r) {
        if ((int)$r['id'] === (int)$id) return $r;
    }
    return null;
}

function ssms_insert($table, $row) {
    $rows = ssms_read($table);
    $row['id'] = ssms_next_id($rows);
    $rows[] = $row;
    ssms_write($table, $rows);
    return $row['id'];
}

function ssms_update($table, $id, $changes) {
    $rows = ssms_read($table);
    foreach ($rows as &$r) {
        if ((int)$r['id'] === (int)$id) { $r = array_merge($r, $changes); }
    }
    ssms_write($table, $rows);
}

function ssms_delete($table, $id) {
    $rows = ssms_read($table);
    $rows = array_values(array_filter($rows, function ($r) use ($id) {
        return (int)$r['id'] !== (int)$id;
    }));
    ssms_write($table, $rows);
}

function ssms_where($table, $field, $value) {
    return array_values(array_filter(ssms_read($table), function ($r) use ($field, $value) {
        return isset($r[$field]) && $r[$field] == $value;
    }));
}

function ssms_e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
