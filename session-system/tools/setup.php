<?php
/**
 * SSMS setup / migration tool (CLI only).
 *   php tools/setup.php            create config/.env (fresh APP_KEY), harden data dir, migrate legacy data
 *   php tools/setup.php --dev      additionally seed LOCAL-ONLY development accounts (refused in production)
 * Idempotent: safe to re-run. Always backs up data/ to data/backups/ before changing anything.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$dev = in_array('--dev', $argv, true);

// 1. config/.env with a fresh random key (never overwrites an existing one)
$envFile = $root . '/config/.env';
if (!is_file($envFile)) {
    $tpl = file_get_contents($root . '/config/.env.example');
    $tpl = preg_replace('/^SSMS_APP_KEY=.*$/m', 'SSMS_APP_KEY=' . base64_encode(random_bytes(32)), $tpl);
    file_put_contents($envFile, $tpl); chmod($envFile, 0600);
    echo "created config/.env with a new SSMS_APP_KEY (back this file up securely)\n";
} else echo "config/.env already exists - kept\n";

define('SSMS_DATA_DIR', $root . '/data');
require $root . '/includes/config.php';
require $root . '/includes/store.php';
if (ssms_cfg('SSMS_ENV') === 'production' && $dev) { fwrite(STDERR, "REFUSED: --dev seeding is not allowed when SSMS_ENV=production\n"); exit(1); }

@mkdir(SSMS_DATA_DIR, 0750, true);
@chmod(SSMS_DATA_DIR, 0750);
file_put_contents(SSMS_DATA_DIR . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
file_put_contents(SSMS_DATA_DIR . '/index.html', '');

echo 'storage: ' . ssms_storage_label() . "\n";

// 2. backup before any structural change
$bk = ssms_backup_data('pre-migration');
echo "backup written: " . str_replace($root . '/', '', $bk) . "\n";

// 3. additive migration (no record is deleted or overwritten)
$sessions = ssms_read('sessions');
$changed = 0;
ssms_mutate('sessions', function ($rows) use (&$changed) {
    foreach ($rows as &$s) {
        $defaults = ['end_time' => null, 'agenda_status' => 'Draft', 'agenda_revision' => 0, 'ended_at' => null,
                     'cancel_reason' => null, 'updated_at' => $s['created_at'] ?? null];
        foreach ($defaults as $k => $v) if (!array_key_exists($k, $s)) { $s[$k] = $v; $changed++; }
        if (!empty($s['started_at']) && !is_int($s['started_at'])) $s['started_at'] = (int)$s['started_at'];
    }
    return $rows;
});
ssms_mutate('members', function ($rows) use (&$changed) {
    foreach ($rows as &$m) if (!array_key_exists('eligible_for_quorum', $m)) { $m['eligible_for_quorum'] = true; $changed++; }
    return $rows;
});
ssms_mutate('attendance', function ($rows) use (&$changed) {
    foreach ($rows as &$a) {
        foreach (['time_out' => null, 'recorded_by' => null, 'updated_at' => null] as $k => $v) if (!array_key_exists($k, $a)) { $a[$k] = $v; $changed++; }
    }
    return $rows;
});
ssms_mutate('proceedings', function ($rows) use (&$changed) {
    foreach ($rows as &$p) {
        foreach (['status' => 'Draft', 'kind' => 'Discussion', 'agenda_id' => null, 'speaker' => '', 'version' => 1, 'author_id' => null] as $k => $v)
            if (!array_key_exists($k, $p)) { $p[$k] = $v; $changed++; }
    }
    return $rows;
});
foreach (['users', 'otp_challenges', 'badges', 'attendance_history', 'proceedings_history', 'agenda_revisions', 'minutes_versions'] as $t) {
    if (!ssms_table_exists($t)) ssms_store_raw($t, []);
}
if (!ssms_table_exists('settings')) {
    ssms_store_raw('settings', [
        'quorum_rule' => ['basis' => 'eligible_members', 'type' => 'majority', 'numerator' => 1, 'denominator' => 2, 'fixed' => null,
                          'confirmed_by_office' => false, 'confirmed_by' => null, 'note' => 'DEFAULT majority-of-members rule. Must be confirmed by the authorized office against the applicable rules of procedure.'],
        'default_session_minutes' => 180,
        'require_final_agenda_to_start' => true,
    ]);
}
echo "migrated legacy records (fields added: $changed)\n";

// 4. legacy plaintext QR tokens: NEVER carried over. They were world-readable under data/.
$legacyQr = SSMS_DATA_DIR . '/qr_users.json';
if (!ssms_db_enabled() && is_file($legacyQr)) {
    rename($legacyQr, $bk . '/qr_users.json.legacy');         // kept only in the (protected) backup
    echo "legacy qr_users.json (plaintext tokens) moved into the backup; old badges are invalid - re-issue from Admin > QR Badges\n";
}

// 5. dev seed
if ($dev) {
    $now = date('Y-m-d H:i:s');
    $pw = 'DevOnly#2026-ssms';
    $accounts = [
        ['admin_dev',   'Dev System Administrator', 'sysadmin',      null],
        ['secretary_dev', "Dev Secretary's Office", 'secretary',     null],
        ['presiding_dev', 'Hon. R. Almazan (dev)',  'presiding',     1],
        ['councilor_dev', 'Hon. D. Santos (dev)',   'councilor',     2],
        ['councilor2_dev', 'Hon. M. Reyes (dev)',   'councilor',     3],
        ['docstaff_dev', 'Dev Documentation Staff', 'documentation', null],
        ['viewer_dev',  'Dev Observer',             'viewer',        null],
        ['admin2_dev',  'Dev Second Administrator', 'sysadmin',      null],
    ];
    foreach ($accounts as [$u, $name, $role, $member]) {
        $exists = false; foreach (ssms_read('users') as $r) if ($r['username'] === $u) $exists = true;
        if ($exists) continue;
        ssms_insert('users', [
            'username' => $u, 'name' => $name, 'role' => $role, 'member_id' => $member,
            'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'active' => true,
            // DEV ONLY: pre-verified placeholder addresses (reserved .invalid domain) so the dev accounts can exercise the OTP flow
            // against a local SMTP sink. Real accounts are NEVER auto-verified. Change them in User Accounts to test a real inbox.
            'email' => $u . '@dev.invalid', 'email_verified' => true, 'email_verified_at' => $now, 'pending_email' => null,
            'mfa' => ['email_otp' => false],
            'failed_count' => 0, 'locked_until' => 0, 'session_version' => 1, 'created_at' => $now, 'last_login_at' => null,
            'is_dev_account' => true,
        ]);
        echo "seeded dev account $u ($role)\n";
    }
    echo "\nDEV ONLY password for all seeded accounts: $pw\nDelete these accounts (Admin > User Accounts) before any real use.\n";
}
$legacy = 0; foreach (ssms_read('users') as $r) if (!array_key_exists('email', $r)) $legacy++;
if ($legacy) echo "NOTE: $legacy existing account(s) predate e-mail OTP and have no e-mail fields. Run: php tools/migrate_email_otp.php --apply\n";
echo "done\n";
