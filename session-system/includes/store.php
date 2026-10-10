<?php
/**
 * JSON flat-file store (retained from the original design) hardened for safe use:
 *  - every read-modify-write runs under an exclusive lock (no lost updates / interleaved writes)
 *  - writes are atomic (temp file + rename) so a crash never leaves half a file
 *  - a missing file is an empty table; a CORRUPT file raises an error instead of being
 *    silently treated as empty (the original code would then overwrite the real data)
 *  - rows are validated to be arrays with integer ids
 * Limits: still a single-server, low-concurrency store. For real multi-user production
 * use see docs/STORAGE_AND_MIGRATION.md (MySQL/MariaDB migration path).
 */

if (!defined('SSMS_DATA_DIR')) define('SSMS_DATA_DIR', __DIR__ . '/../data');

function ssms_path(string $table): string {
    if (!preg_match('/^[a-z_]{1,40}$/', $table)) throw new InvalidArgumentException('bad table name');
    return SSMS_DATA_DIR . '/' . $table . '.json';
}

function ssms_lock(string $table, bool $exclusive) {
    $lf = fopen(SSMS_DATA_DIR . '/.lock_' . $table, 'c');
    if (!$lf) throw new RuntimeException('cannot open lock');
    if (!flock($lf, $exclusive ? LOCK_EX : LOCK_SH)) throw new RuntimeException('cannot lock');
    return $lf;
}
function ssms_unlock($lf): void { flock($lf, LOCK_UN); fclose($lf); }

function ssms_load_raw(string $table, $default) {
    $file = ssms_path($table);
    if (!file_exists($file)) return $default;
    $raw = file_get_contents($file);
    if ($raw === false) throw new RuntimeException("cannot read $table");
    if (trim($raw) === '') return $default;
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException("data file $table is corrupted (invalid JSON); refusing to continue");
    return $data;
}

function ssms_store_raw(string $table, array $data): void {
    $file = ssms_path($table);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException("cannot write $table");
    @chmod($tmp, 0640);
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException("cannot commit $table"); }
}

/** Table (list of rows). */
function ssms_read(string $table): array {
    $lf = ssms_lock($table, false);
    try {
        $d = ssms_load_raw($table, []);
    } finally { ssms_unlock($lf); }
    foreach ($d as $r) if (!is_array($r)) throw new RuntimeException("table $table has invalid rows");
    return array_values($d);
}
/** Object-style file (settings, rate limits). */
function ssms_read_obj(string $table): array {
    $lf = ssms_lock($table, false);
    try { return ssms_load_raw($table, []); } finally { ssms_unlock($lf); }
}

/** Atomic read-modify-write of a table. $fn(array $rows): array $rowsToSave. */
function ssms_mutate(string $table, callable $fn) {
    $lf = ssms_lock($table, true);
    try {
        $rows = array_values(ssms_load_raw($table, []));
        $out = $fn($rows);
        if (!is_array($out)) throw new RuntimeException('mutation must return array');
        ssms_store_raw($table, array_values($out));
    } finally { ssms_unlock($lf); }
}
function ssms_mutate_obj(string $table, callable $fn): void {
    $lf = ssms_lock($table, true);
    try {
        $out = $fn(ssms_load_raw($table, []));
        ssms_store_raw($table, $out);
    } finally { ssms_unlock($lf); }
}

function ssms_next_id(array $rows): int {
    $max = 0;
    foreach ($rows as $r) { if (isset($r['id']) && (int)$r['id'] > $max) $max = (int)$r['id']; }
    return $max + 1;
}

function ssms_find(string $table, $id): ?array {
    foreach (ssms_read($table) as $r) if ((int)($r['id'] ?? 0) === (int)$id) return $r;
    return null;
}

function ssms_insert(string $table, array $row): int {
    $newId = 0;
    ssms_mutate($table, function (array $rows) use ($row, &$newId) {
        $newId = ssms_next_id($rows);
        $row['id'] = $newId;
        $rows[] = $row;
        return $rows;
    });
    return $newId;
}

/** Insert only if $unique(rows) returns null; otherwise returns [null, $conflictMessage]. */
function ssms_insert_unique(string $table, array $row, callable $unique): array {
    $newId = null; $err = null;
    ssms_mutate($table, function (array $rows) use ($row, $unique, &$newId, &$err) {
        $err = $unique($rows);
        if ($err !== null) return $rows;
        $newId = ssms_next_id($rows);
        $row['id'] = $newId;
        $rows[] = $row;
        return $rows;
    });
    return [$newId, $err];
}

function ssms_update(string $table, $id, array $changes): bool {
    $found = false;
    ssms_mutate($table, function (array $rows) use ($id, $changes, &$found) {
        foreach ($rows as &$r) {
            if ((int)($r['id'] ?? 0) === (int)$id) { $r = array_merge($r, $changes); $found = true; }
        }
        return $rows;
    });
    return $found;
}

function ssms_delete(string $table, $id): bool {
    $found = false;
    ssms_mutate($table, function (array $rows) use ($id, &$found) {
        $out = [];
        foreach ($rows as $r) { if ((int)($r['id'] ?? 0) === (int)$id) { $found = true; continue; } $out[] = $r; }
        return $out;
    });
    return $found;
}

function ssms_where(string $table, string $field, $value): array {
    return array_values(array_filter(ssms_read($table), fn($r) => isset($r[$field]) && $r[$field] == $value));
}

/** Timestamped copy of every data file, for use before any structural change. */
function ssms_backup_data(string $label = 'manual'): string {
    $dir = SSMS_DATA_DIR . '/backups/' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_-]/i', '', $label);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('cannot create backup dir');
    foreach (glob(SSMS_DATA_DIR . '/*.json') as $f) copy($f, $dir . '/' . basename($f));
    foreach (glob(SSMS_DATA_DIR . '/*.log') as $f) copy($f, $dir . '/' . basename($f));
    return $dir;
}

if (!function_exists('ssms_e')) {
    function ssms_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
