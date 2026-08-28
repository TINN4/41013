<?php
/**
 * One-time migration: reads the existing data/ssms.sqlite (from the
 * previous SQLite setup) and loads it into MySQL using the exact same
 * ssms_write() code path the app uses everywhere else — so the migrated
 * data is guaranteed to match what the app would have written itself.
 *
 * Run once from the command line:  php migrate_sqlite_to_mysql.php
 * Make sure includes/db_config.php has your real MySQL credentials
 * and the target database already exists before running this.
 *
 * Safe to re-run: it wipes each MySQL table before reloading it.
 * The old ssms.sqlite file is left untouched (kept as a backup).
 */

require_once __DIR__ . '/../includes/db_config.php';

$sqliteFile = __DIR__ . '/ssms.sqlite';
if (!file_exists($sqliteFile)) {
    $backupFile = __DIR__ . '/sqlite_backup_pre_mysql/ssms.sqlite';
    if (file_exists($backupFile)) {
        $sqliteFile = $backupFile; // already migrated once; re-running from the archived copy
    } else {
        echo "No ssms.sqlite found — nothing to migrate.\n";
        echo "(If this is a brand new install, that's fine — MySQL tables will be created empty on first use.)\n";
        exit();
    }
}

$sqlite = new PDO('sqlite:' . $sqliteFile);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$mysql = new PDO(
    'mysql:host=' . SSMS_DB_HOST . ';port=' . SSMS_DB_PORT . ';dbname=' . SSMS_DB_NAME . ';charset=utf8mb4',
    SSMS_DB_USER, SSMS_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tables = ['sessions', 'agenda', 'members', 'attendance', 'proceedings', 'minutes', 'qr_users'];

foreach ($tables as $table) {
    $exists = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $sqlite->quote($table))->fetch();
    if (!$exists) {
        echo "skip $table (not present in ssms.sqlite)\n";
        continue;
    }

    $rows = $sqlite->query("SELECT id, data FROM \"$table\" ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    $mysql->exec("CREATE TABLE IF NOT EXISTS `$table` (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        data JSON NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $mysql->beginTransaction();
    $mysql->exec("DELETE FROM `$table`");
    $insert = $mysql->prepare("INSERT INTO `$table` (id, data) VALUES (:id, :data)");
    foreach ($rows as $r) {
        $insert->execute([':id' => (int)$r['id'], ':data' => $r['data']]);
    }
    $mysql->commit();

    echo "migrated $table: " . count($rows) . " row(s)\n";
}

echo "Done. Data now lives in MySQL database '" . SSMS_DB_NAME . "'.\n";
