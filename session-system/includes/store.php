<?php
/**
 * Storage layer. Two interchangeable backends behind the same ssms_* functions:
 *
 *  - MySQL / MariaDB (used automatically when DB_HOST and DB_DATABASE are configured, or SSMS_STORAGE=mysql).
 *    Every logical table is one JSON document row in `ssms_store`; read-modify-write runs in a transaction on a
 *    row locked with SELECT ... FOR UPDATE, so concurrent requests cannot lose updates. Data survives redeploys
 *    because it lives in the database, not in the container.
 *  - JSON flat files in data/ (local development, or SSMS_STORAGE=file): exclusive locks + atomic rename.
 *
 * Common guarantees: a missing table is empty; a CORRUPT table raises an error instead of being silently treated
 * as empty (which would overwrite the real data); rows must be arrays with integer ids.
 */

if (!defined('SSMS_DATA_DIR')) define('SSMS_DATA_DIR', __DIR__ . '/../data');

/* ------------------------------------------------------------ backend selection */
function ssms_scfg(string $key): ?string {
    if (function_exists('ssms_cfg')) return ssms_cfg($key);
    $v = getenv($key);
    return ($v === false || $v === '') ? null : $v;
}

function ssms_db_enabled(): bool {
    static $on = null;
    if ($on !== null) return $on;
    $mode = strtolower((string)(ssms_scfg('SSMS_STORAGE') ?? 'auto'));
    if ($mode === 'file') return $on = false;
    if ($mode === 'mysql') return $on = true;
    return $on = (ssms_scfg('DB_HOST') !== null && ssms_scfg('DB_DATABASE') !== null);
}

/** Human-readable description of the active backend (never includes the password). */
function ssms_storage_label(): string {
    if (!ssms_db_enabled()) return 'file (' . SSMS_DATA_DIR . ')';
    return 'mysql (' . ssms_scfg('DB_USERNAME') . '@' . ssms_scfg('DB_HOST') . ':' . (ssms_scfg('DB_PORT') ?? '3306') . '/' . ssms_scfg('DB_DATABASE') . ')';
}

function ssms_check_table(string $table): string {
    if (!preg_match('/^[a-z_]{1,40}$/', $table)) throw new InvalidArgumentException('bad table name');
    return $table;
}

/* ------------------------------------------------------------ MySQL / MariaDB backend */
/** Named connections: 'main' (data + transactions), 'audit' and 'session' (separate, so their locks and commits
 *  are independent of whatever transaction the request is running). */
function ssms_pdo(string $slot = 'main'): PDO {
    static $conns = [];
    if (isset($conns[$slot])) return $conns[$slot];
    if (!extension_loaded('pdo_mysql')) throw new RuntimeException('database storage is enabled but the pdo_mysql PHP extension is not installed');
    $host = ssms_scfg('DB_HOST'); $db = ssms_scfg('DB_DATABASE'); $user = ssms_scfg('DB_USERNAME');
    if (!$host || !$db || !$user) throw new RuntimeException('database settings are incomplete (need DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD)');
    $pdo = new PDO('mysql:host=' . $host . ';port=' . (ssms_scfg('DB_PORT') ?? '3306') . ';dbname=' . $db . ';charset=utf8mb4',
        $user, ssms_scfg('DB_PASSWORD') ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5,
        ]);
    static $schema = false;
    if (!$schema) { ssms_db_schema($pdo); $schema = true; }
    return $conns[$slot] = $pdo;
}

function ssms_db_schema(PDO $pdo): void {
    $opt = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssms_store (name VARCHAR(40) NOT NULL PRIMARY KEY, data LONGTEXT NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssms_audit (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, line MEDIUMTEXT NOT NULL) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssms_backup (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, label VARCHAR(80) NOT NULL,
        name VARCHAR(40) NOT NULL, data LONGTEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY (label)) $opt");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ssms_sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, data MEDIUMBLOB NOT NULL,
        updated_at INT UNSIGNED NOT NULL, KEY (updated_at)) $opt");
}

/** Runs $fn(PDO) in one transaction on the main connection. Nested calls join the outer transaction.
 *  A deadlock or lock-wait timeout is retried a couple of times (the callback is re-run on fresh data). */
function ssms_db_tx(callable $fn) {
    static $depth = 0;
    $pdo = ssms_pdo();
    if ($depth > 0) { $depth++; try { return $fn($pdo); } finally { $depth--; } }
    for ($try = 0; ; $try++) {
        $depth = 1;
        try {
            $pdo->beginTransaction();
            $r = $fn($pdo);
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $code = ($e instanceof PDOException) ? (int)($e->errorInfo[1] ?? 0) : 0;
            if ($try < 6 && in_array($code, [1213, 1205], true)) { usleep(random_int(20000, 120000) * ($try + 1)); continue; }
            throw $e;
        } finally { $depth = 0; }
    }
}

/** Seed file for a table that has never been written to the database: data/<table>.json when it ships with the project
 *  (demo sessions, members, settings), otherwise an empty table. Legacy plaintext qr_users is never imported. */
function ssms_db_seed_file(string $table): ?string {
    $f = SSMS_DATA_DIR . '/' . $table . '.json';
    return ($table !== 'qr_users' && is_file($f)) ? $f : null;
}
function ssms_db_seed(PDO $pdo, string $table): void {
    $init = '[]';
    if (($f = ssms_db_seed_file($table)) !== null) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d)) $init = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    // INSERT IGNORE only runs when the row is missing (see below), and the first writer wins if two requests race.
    $pdo->prepare('INSERT IGNORE INTO ssms_store (name, data) VALUES (?, ?)')->execute([$table, $init]);
}

function ssms_db_load(PDO $pdo, string $table, $default, bool $forUpdate = false) {
    $st = $pdo->prepare('SELECT data FROM ssms_store WHERE name = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$table]);
    $raw = $st->fetchColumn();
    if ($raw === false) {
        // First use of this table. The row is created only when it is missing: running INSERT IGNORE on an existing row
        // would take a shared lock first and make concurrent writers deadlock while upgrading it.
        ssms_db_seed($pdo, $table);
        $st->execute([$table]);
        $raw = $st->fetchColumn();
    }
    if ($raw === false || trim((string)$raw) === '') return $default;
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException("data table $table is corrupted (invalid JSON); refusing to continue");
    return $data;
}

function ssms_db_save(PDO $pdo, string $table, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO ssms_store (name, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)')->execute([$table, $json]);
}

/* ------------------------------------------------------------ flat-file backend */
function ssms_path(string $table): string {
    return SSMS_DATA_DIR . '/' . ssms_check_table($table) . '.json';
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
    if (ssms_db_enabled()) { ssms_db_tx(fn($pdo) => ssms_db_save($pdo, ssms_check_table($table), $data)); return; }
    $file = ssms_path($table);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException("cannot write $table");
    @chmod($tmp, 0640);
    if (!rename($tmp, $file)) { @unlink($tmp); throw new RuntimeException("cannot commit $table"); }
}

/** Table (list of rows). */
function ssms_read(string $table): array {
    if (ssms_db_enabled()) $d = ssms_db_load(ssms_pdo(), ssms_check_table($table), []);
    else {
        $lf = ssms_lock($table, false);
        try {
            $d = ssms_load_raw($table, []);
        } finally { ssms_unlock($lf); }
    }
    foreach ($d as $r) if (!is_array($r)) throw new RuntimeException("table $table has invalid rows");
    return array_values($d);
}
/** Object-style file (settings, rate limits). */
function ssms_read_obj(string $table): array {
    if (ssms_db_enabled()) return ssms_db_load(ssms_pdo(), ssms_check_table($table), []);
    $lf = ssms_lock($table, false);
    try { return ssms_load_raw($table, []); } finally { ssms_unlock($lf); }
}

/** Atomic read-modify-write of a table. $fn(array $rows): array $rowsToSave. */
function ssms_mutate(string $table, callable $fn) {
    if (ssms_db_enabled()) {
        ssms_check_table($table);
        ssms_db_tx(function (PDO $pdo) use ($table, $fn) {
            $rows = array_values(ssms_db_load($pdo, $table, [], true));
            $out = $fn($rows);
            if (!is_array($out)) throw new RuntimeException('mutation must return array');
            ssms_db_save($pdo, $table, array_values($out));
        });
        return;
    }
    $lf = ssms_lock($table, true);
    try {
        $rows = array_values(ssms_load_raw($table, []));
        $out = $fn($rows);
        if (!is_array($out)) throw new RuntimeException('mutation must return array');
        ssms_store_raw($table, array_values($out));
    } finally { ssms_unlock($lf); }
}
function ssms_mutate_obj(string $table, callable $fn): void {
    if (ssms_db_enabled()) {
        ssms_check_table($table);
        ssms_db_tx(function (PDO $pdo) use ($table, $fn) {
            ssms_db_save($pdo, $table, $fn(ssms_db_load($pdo, $table, [], true)));
        });
        return;
    }
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

/** Whether a table has ever been written (used by the setup tools). */
function ssms_table_exists(string $table): bool {
    if (ssms_db_enabled()) {
        $pdo = ssms_pdo();
        $st = $pdo->prepare('SELECT 1 FROM ssms_store WHERE name = ?');
        $st->execute([ssms_check_table($table)]);
        if ($st->fetchColumn()) return true;
        if (ssms_db_seed_file($table) === null) return false;
        ssms_db_seed($pdo, $table);                 // a shipped seed counts as existing, exactly like the file backend
        return true;
    }
    return is_file(ssms_path($table));
}

/** Timestamped copy of every table, for use before any structural change. Returns where it was written. */
function ssms_backup_data(string $label = 'manual'): string {
    if (ssms_db_enabled()) {
        $lab = date('Ymd_His') . '_' . substr(preg_replace('/[^a-z0-9_-]/i', '', $label), 0, 40);
        ssms_pdo()->prepare('INSERT INTO ssms_backup (label, name, data) SELECT ?, name, data FROM ssms_store')->execute([$lab]);
        return 'database:ssms_backup/' . $lab;
    }
    $dir = SSMS_DATA_DIR . '/backups/' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9_-]/i', '', $label);
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('cannot create backup dir');
    foreach (glob(SSMS_DATA_DIR . '/*.json') as $f) copy($f, $dir . '/' . basename($f));
    foreach (glob(SSMS_DATA_DIR . '/*.log') as $f) copy($f, $dir . '/' . basename($f));
    return $dir;
}

if (!function_exists('ssms_e')) {
    function ssms_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
