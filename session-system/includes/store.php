<?php
/**
 * Lightweight JSON flat-file data store.
 * No MySQL setup required — works on any PHP host out of the box.
 * Swap these functions for real PDO/MySQL calls later without touching module code.
 */

define('SSMS_DATA_DIR', __DIR__ . '/../data');

function ssms_path($table) {
    return SSMS_DATA_DIR . '/' . $table . '.json';
}

function ssms_read($table) {
    $file = ssms_path($table);
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ssms_write($table, $data) {
    $file = ssms_path($table);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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
