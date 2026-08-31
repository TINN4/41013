<?php
// cron_backup.php — writes a full, timestamped system backup into
// /backups. This file does NOT run on a schedule by itself; a schedule
// still has to be set up on your hosting/server side. Two ways to do that:
//
//   1. CLI cron (recommended — never touches the internet at all).
//      In your hosting control panel's "Cron Jobs" section, add a job
//      that runs nightly:
//        php /full/server/path/to/session-system/cron_backup.php
//
//   2. URL-based cron, only if your host doesn't offer CLI cron and
//      instead offers a "visit this URL on a schedule" option. This is
//      protected by the secret key below so a random visitor can't
//      trigger it or discover it — CHANGE THE KEY before using this
//      method:
//        https://yourdomain.com/session-system/cron_backup.php?key=YOUR-LONG-RANDOM-KEY
//
// Old backup files are never auto-deleted; clear out /backups by hand
// (or your own script) if you want to cap how many are kept.

define('BACKUP_SECRET_KEY', 'change-this-to-a-long-random-string-before-using-the-url-method');

require_once __DIR__ . '/includes/backup.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $key = $_GET['key'] ?? '';
    if (!hash_equals(BACKUP_SECRET_KEY, $key)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die('Forbidden.');
    }
    header('Content-Type: text/plain');
}

$path = ssms_write_backup_file();
if ($path) {
    echo 'Backup written: ' . basename($path) . PHP_EOL;
} else {
    http_response_code(500);
    echo 'Backup FAILED — check that the /backups folder exists and is writable by the web server.' . PHP_EOL;
}
