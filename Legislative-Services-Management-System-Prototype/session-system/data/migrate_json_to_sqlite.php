<?php
/**
 * One-time migration: reads the original data/*.json files and loads them
 * into data/ssms.sqlite using the exact same ssms_insert() code path the
 * app uses everywhere else, so the migrated data is guaranteed to match
 * what the app would have written itself.
 *
 * Run once from the command line:  php migrate_json_to_sqlite.php
 * Safe to re-run: it wipes each SQLite table before reloading it.
 * The original .json files are left untouched (kept as a backup / for
 * reference) — the app itself no longer reads them after this point.
 */

require_once __DIR__ . '/../includes/store.php';

$tables = ['sessions', 'agenda', 'members', 'attendance', 'proceedings', 'minutes', 'qr_users'];

foreach ($tables as $table) {
    $jsonFile = __DIR__ . '/' . $table . '.json';
    if (!file_exists($jsonFile)) {
        echo "skip $table (no json file)\n";
        continue;
    }
    $rows = json_decode(file_get_contents($jsonFile), true);
    if (!is_array($rows)) $rows = [];

    // Write straight into the DB with ids preserved exactly as they were,
    // via the same ssms_write() the rest of the app uses.
    ssms_write($table, $rows);

    echo "migrated $table: " . count($rows) . " row(s)\n";
}

echo "Done. Database file: " . SSMS_DB_FILE . "\n";
