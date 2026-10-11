<?php
/**
 * One-time migration from authenticator-app (TOTP) MFA to e-mail OTP. CLI only.
 *   php tools/migrate_email_otp.php            dry run: report what would change, write nothing
 *   php tools/migrate_email_otp.php --apply    back up data/, then migrate (idempotent)
 *   php tools/migrate_email_otp.php --purge-legacy   after the cut-over is proven: erase retained TOTP secrets
 *
 * What it does to each account (no account is deleted, no password is touched):
 *   - adds email / email_verified / pending_email fields (email stays EMPTY and UNVERIFIED: addresses are never guessed
 *     or auto-verified; the Secretary/Administrator must enter each address and its owner must confirm it)
 *   - moves the old `mfa` block (encrypted TOTP secret, recovery-code hashes) to `mfa_legacy_totp` for rollback; NO code reads it
 *   - accounts that had TOTP enabled and are not in a mandatory role keep their protection: they are marked opted in to
 *     e-mail codes, so they cannot sign in with a password alone until a verified address exists
 *   - sessions of every migrated account are ended (session_version + 1)
 * Mandatory roles (sysadmin, secretary, presiding) are blocked at sign-in until a verified address exists, so
 * nobody becomes accessible without a second factor during the migration.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
define('SSMS_DATA_DIR', getenv('SSMS_DATA_DIR') ?: $root . '/data');
require $root . '/includes/config.php';
require $root . '/includes/store.php';
require $root . '/includes/audit.php';
function ssms_ip(): string { return 'cli'; }
$apply = in_array('--apply', $argv, true); $purge = in_array('--purge-legacy', $argv, true);

if ($purge) {
    $bk = ssms_backup_data('pre-purge-legacy-totp'); $n = 0;
    ssms_mutate('users', function ($rows) use (&$n) { foreach ($rows as &$r) if (array_key_exists('mfa_legacy_totp', $r)) { unset($r['mfa_legacy_totp']); $n++; } return $rows; });
    ssms_audit('migration.legacy_totp_purged', null, 'ok', ['accounts' => $n], ['id' => null, 'role' => 'server-operator']);
    echo "Removed retained TOTP data from $n account(s). Backup (still contains it - delete when no longer needed): " . basename($bk) . "\n";
    exit(0);
}

$users = ssms_read('users'); $plan = [];
foreach ($users as $u) {
    $needs = !array_key_exists('email', $u) || array_key_exists('secret_enc', $u['mfa'] ?? []) || array_key_exists('enabled', $u['mfa'] ?? []);
    if ($needs) $plan[] = $u;
}
printf("%d account(s) total, %d need migration.\n", count($users), count($plan));
foreach ($plan as $u) {
    $mand = in_array($u['role'], ['sysadmin', 'secretary', 'presiding'], true);
    $had = !empty($u['mfa']['enabled']);
    printf("  - %-20s role=%-13s had_totp=%s -> %s\n", $u['username'], $u['role'], $had ? 'yes' : 'no',
        $mand ? 'sign-in blocked until a verified e-mail exists' : ($had ? 'opted in to e-mail codes; blocked until a verified e-mail exists' : 'password-only unless policy requires'));
}
if (!$apply) { echo "\nDry run only. Re-run with --apply to migrate (a backup is taken first).\n"; exit(0); }
if (!$plan) { echo "Nothing to do.\n"; exit(0); }

$bk = ssms_backup_data('pre-email-otp-migration');
echo "Backup written: " . basename($bk) . "\n";
if (!ssms_table_exists('otp_challenges')) ssms_store_raw('otp_challenges', []);
ssms_mutate('users', function ($rows) {
    foreach ($rows as &$r) {
        $old = $r['mfa'] ?? [];
        if (array_key_exists('secret_enc', $old) || array_key_exists('enabled', $old)) {
            if (!empty($old['secret_enc']) || !empty($old['recovery'])) $r['mfa_legacy_totp'] = $old;      // retained for rollback only
            $r['mfa'] = ['email_otp' => !empty($old['enabled']) && !in_array($r['role'], ['sysadmin', 'secretary', 'presiding'], true)];
        }
        foreach (['email' => null, 'email_verified' => false, 'email_verified_at' => null, 'pending_email' => null] as $k => $v) if (!array_key_exists($k, $r)) $r[$k] = $v;
        unset($r['mfa_reset_request']);
        $r['session_version'] = (int)($r['session_version'] ?? 1) + 1;
    }
    return $rows;
});
ssms_audit('migration.email_otp_applied', null, 'ok', ['accounts' => count($plan), 'backup' => basename($bk)], ['id' => null, 'role' => 'server-operator']);
echo "Migrated " . count($plan) . " account(s). Next: every migrated administrator needs a verified e-mail before they can sign in. Use tools/account_recovery.php set-email for the first administrator (see docs/EMAIL_OTP_SETUP.md).\n";
