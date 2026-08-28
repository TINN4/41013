<?php
/**
 * String-keyed CRUD backend for app.html's client-side collections
 * (Council Members, Ordinances, Committees, Votes, Records, Hearings,
 * Archives, Research, Agenda, Sessions — the "app_" tables below).
 *
 * Kept separate from includes/store.php on purpose: that file's tables
 * use auto-incrementing integer ids for the session-system/ PHP
 * modules and must not change. This app's records use string ids
 * generated in the browser (e.g. "M-001", "ORD-2024-003"), so these
 * tables use a VARCHAR primary key instead. Reuses the same MySQL
 * connection (ssms_db()) and the same JSON-blob-per-row pattern for
 * full compatibility with whatever fields each collection's records
 * happen to carry.
 */

require_once __DIR__ . '/store.php'; // for ssms_db() and ssms_table_name()

function ssms_app_ensure_table($pdo, $table) {
    $t = ssms_table_name($table);
    $pdo->exec("CREATE TABLE IF NOT EXISTS `$t` (
        id VARCHAR(64) PRIMARY KEY,
        data JSON NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ssms_app_read($table) {
    $pdo = ssms_db();
    ssms_app_ensure_table($pdo, $table);
    $t = ssms_table_name($table);
    $stmt = $pdo->query("SELECT id, data FROM `$t`");
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $row = json_decode($r['data'], true);
        if (!is_array($row)) $row = [];
        $row['id'] = $r['id']; // id column is the source of truth (string id)
        $rows[] = $row;
    }
    return $rows;
}

function ssms_app_write($table, $rows) {
    $pdo = ssms_db();
    ssms_app_ensure_table($pdo, $table);
    $t = ssms_table_name($table);

    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM `$t`");
        $insert = $pdo->prepare("INSERT INTO `$t` (id, data) VALUES (:id, :data)");
        foreach ($rows as $row) {
            if (!isset($row['id']) || $row['id'] === '') continue;
            $id = (string)$row['id'];
            $payload = $row;
            unset($payload['id']);
            $insert->execute([':id' => $id, ':data' => json_encode($payload, JSON_UNESCAPED_SLASHES)]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
