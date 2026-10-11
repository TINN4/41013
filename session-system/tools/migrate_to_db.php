<?php
/**
 * Database storage tool (CLI only).
 *   php tools/migrate_to_db.php --check      show which storage backend is active and test the connection
 *   php tools/migrate_to_db.php --import     copy existing data/*.json and data/audit.log INTO the database
 *                                            (tables that already exist in the database are skipped)
 *   php tools/migrate_to_db.php --import --overwrite   replace database tables with the file copies
 * The database is used automatically when DB_HOST and DB_DATABASE are set (HostForge injects them).
 * Nothing is ever deleted from data/.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
define('SSMS_DATA_DIR', getenv('SSMS_DATA_DIR') ?: $root . '/data');
require $root . '/includes/config.php';
require $root . '/includes/store.php';

echo 'storage: ' . ssms_storage_label() . "\n";
if (!ssms_db_enabled()) { fwrite(STDERR, "Database storage is NOT active (DB_HOST / DB_DATABASE not set, or SSMS_STORAGE=file).\n"); exit(1); }
try {
    $pdo = ssms_pdo();
    $v = $pdo->query('SELECT VERSION()')->fetchColumn();
    $n = (int)$pdo->query('SELECT COUNT(*) FROM ssms_store')->fetchColumn();
    $a = (int)$pdo->query('SELECT COUNT(*) FROM ssms_audit')->fetchColumn();
    echo "connected: server $v; ssms_store has $n table(s); ssms_audit has $a entr" . ($a === 1 ? 'y' : 'ies') . "\n";
} catch (Throwable $e) { fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n"); exit(1); }

if (!in_array('--import', $argv, true)) exit(0);
$over = in_array('--overwrite', $argv, true);
$done = 0; $skipped = 0;
foreach (glob(SSMS_DATA_DIR . '/*.json') ?: [] as $f) {
    $name = basename($f, '.json');
    if (!preg_match('/^[a-z_]{1,40}$/', $name)) continue;
    $data = json_decode((string)file_get_contents($f), true);
    if (!is_array($data)) { echo "skip $name (not valid JSON)\n"; continue; }
    if (!$over && ssms_table_exists($name)) { echo "skip $name (already in the database)\n"; $skipped++; continue; }
    ssms_store_raw($name, $data); echo "imported $name (" . count($data) . " item(s))\n"; $done++;
}
$log = SSMS_DATA_DIR . '/audit.log';
if (is_file($log) && ($over || $a === 0)) {
    if ($over) $pdo->exec('DELETE FROM ssms_audit');
    $st = $pdo->prepare('INSERT INTO ssms_audit (line) VALUES (?)'); $c = 0;
    foreach (file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) { $st->execute([$line]); $c++; }
    echo "imported audit.log ($c entries, hash chain preserved)\n";
}
echo "done: $done imported, $skipped skipped\n";
