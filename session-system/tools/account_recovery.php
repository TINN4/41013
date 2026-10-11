<?php
/**
 * Server-side, auditable account recovery for when NO administrator can sign in (lost mailbox, outage, migration).
 * Needs shell access to the server: that is the trust boundary. There is no public or web equivalent.
 *   php tools/account_recovery.php show <username>
 *   php tools/account_recovery.php set-email <username> <new-email> "<reason>" [--mark-verified]
 *   php tools/account_recovery.php password-link <username> "<reason>"
 * set-email always drops the old trusted address and ends the account's sessions. Without --mark-verified the new
 * address is only PENDING and a confirmation link is e-mailed to it (the safe default). --mark-verified trusts it
 * immediately; use it only after the operator has verified the person's identity and address out of band, because
 * it bypasses the inbox-ownership check. Every use is backed up and written to the audit log with the reason.
 * password-link prints a one-time link on the console (it is not e-mailed) for the operator to hand over in person.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/authflow.php';
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$markVerified = in_array('--mark-verified', $argv, true);
[$cmd, $username] = array_pad($args, 2, '');
$usage = function () { fwrite(STDERR, "usage:\n  php tools/account_recovery.php show <username>\n  php tools/account_recovery.php set-email <username> <new-email> \"<reason>\" [--mark-verified]\n  php tools/account_recovery.php password-link <username> \"<reason>\"\n"); exit(1); };
if ($cmd === '' || $username === '') $usage();
$u = ssms_user_by_username($username);
if (!$u) { fwrite(STDERR, "no such user\n"); exit(1); }
$op = ['id' => null, 'role' => 'server-operator'];
$who = get_current_user();

if ($cmd === 'show') {
    printf("%s role=%s active=%s locked=%s\n  email=%s verified=%s pending=%s\n  gate=%s\n", $u['username'], $u['role'], var_export(!empty($u['active']), true), var_export(!empty($u['locked']), true),
        $u['email'] ?? '(none)', var_export(ssms_user_email_verified($u), true), $u['pending_email']['address'] ?? '(none)', ssms_mfa_gate($u));
    exit(0);
}
$reason = $args[$cmd === 'set-email' ? 3 : 2] ?? '';
if ($reason === '') { fwrite(STDERR, "a reason is required\n"); $usage(); }

if ($cmd === 'set-email') {
    $email = ssms_email_normalize($args[2] ?? '');
    if ($email === null) { fwrite(STDERR, "invalid e-mail address\n"); exit(1); }
    if (ssms_email_taken(ssms_read('users'), $email, (int)$u['id'])) { fwrite(STDERR, "that address belongs to another account\n"); exit(1); }
    $bk = ssms_backup_data('recovery-set-email');
    if ($markVerified) {
        ssms_update('users', $u['id'], ['email' => $email, 'email_verified' => true, 'email_verified_at' => ssms_now(), 'pending_email' => null, 'session_version' => (int)($u['session_version'] ?? 1) + 1]);
        ssms_audit('recovery.email_set_verified', $u['id'], 'ok', ['reason' => $reason, 'operator' => $who, 'backup' => basename($bk)], $op);
        echo "E-mail for {$u['username']} set and marked verified (operator attestation). Sessions ended.\n";
    } else {
        $st = ssms_email_begin_verification((int)$u['id'], $email, null, true);
        ssms_audit('recovery.email_set_pending', $u['id'], $st === 'sent' ? 'ok' : 'failed', ['reason' => $reason, 'operator' => $who, 'status' => $st, 'backup' => basename($bk)], $op);
        echo $st === 'sent' ? "Old address revoked. Confirmation link e-mailed to the new address; the account can sign in once its owner confirms.\n"
                            : "Old address revoked and new address stored as PENDING, but the confirmation e-mail was not sent ($st). Fix SMTP and use the admin page 'Resend verification', or re-run with --mark-verified after checking identity.\n";
        exit($st === 'sent' ? 0 : 2);
    }
} elseif ($cmd === 'password-link') {
    $base = ssms_app_url();
    if ($base === null) { fwrite(STDERR, "SSMS_APP_URL is not configured\n"); exit(1); }
    ssms_backup_data('recovery-password-link');
    [$plain, $hash] = ssms_new_token();
    ssms_update('users', $u['id'], ['pwreset' => ['hash' => $hash, 'expires' => time() + ssms_email_link_ttl(), 'email' => null]]);
    ssms_audit('recovery.password_link_issued', $u['id'], 'ok', ['reason' => $reason, 'operator' => $who], $op);
    echo "One-time password link (single use, hand over in person):\n$base/reset_password.php?token=$plain\n";
} else $usage();
