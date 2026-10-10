<?php
// Builds a full, sanitized snapshot of every table in the system for
// disaster-recovery purposes. Password hashes and live QR tokens are
// stripped — this file is meant to help restore *data*, not to double as
// a second copy of everyone's login credentials, even if it were ever
// exposed by accident.
require_once __DIR__ . '/store.php';

function ssms_full_backup_array() {
    $tables = ['sessions', 'agenda', 'members', 'attendance', 'proceedings', 'minutes', 'qr_users', 'staff_users'];
    $data = [];
    foreach ($tables as $t) {
        $rows = ssms_read($t);
        if ($t === 'staff_users') {
            $rows = array_map(function ($r) { unset($r['password_hash']); return $r; }, $rows);
        }
        if ($t === 'qr_users') {
            $rows = array_map(function ($r) { unset($r['token']); return $r; }, $rows);
        }
        $data[$t] = $rows;
    }
    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'tables' => $data,
    ];
}

// Writes a timestamped JSON file into /backups (created on first use).
// Returns the file path on success, or false if the directory can't be
// created/written to (e.g. hosting doesn't allow filesystem writes there).
function ssms_write_backup_file() {
    $dir = __DIR__ . '/../backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $path = $dir . '/backup-' . date('Y-m-d_His') . '.json';
    $ok = @file_put_contents($path, json_encode(ssms_full_backup_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $ok !== false ? $path : false;
}
