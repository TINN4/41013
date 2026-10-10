<?php
/**
 * Second login step: a one-time code sent by EMAIL (Gmail SMTP). No app needed.
 *
 * Flow: primary login (password or QR badge) -> ssms_begin_mfa() -> mfa.php sends a 6-digit code
 *       -> ssms_complete_login() only after the correct, unexpired, unused code is entered.
 * The code is stored only as a hash in the server-side session, expires in 5 minutes,
 * is single-use, allows 5 tries (then 5-minute lock per account), and resends are rate-limited.
 */
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/mailer.php';

const MFA_MAX_ATTEMPTS = 5;
const MFA_LOCK_SECONDS = 300;
const MFA_PENDING_TTL  = 600;

function mfa_config() { static $c; return $c ?: ($c = require __DIR__ . '/mail_config.php'); }
function mfa_demo_mode() { return mfa_config()['smtp_pass'] === ''; }

function mfa_email_for($uid) {
    if (strpos($uid, 'qr:') === 0) { $u = ssms_find('qr_users', (int)substr($uid, 3)); $e = trim($u['email'] ?? ''); }
    else { $e = trim(mfa_config()['admin_email']); }
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}
function mfa_mask($email) { [$u, $d] = explode('@', $email, 2); return substr($u, 0, 1) . str_repeat('*', max(2, strlen($u) - 1)) . '@' . $d; }

function mfa_state() { $f = SSMS_DATA_DIR . '/mfa_state.json'; $d = file_exists($f) ? json_decode(file_get_contents($f), true) : []; return is_array($d) ? $d : []; }
function mfa_state_put($uid, $rec) { $d = mfa_state(); $d[$uid] = $rec; file_put_contents(SSMS_DATA_DIR . '/mfa_state.json', json_encode($d), LOCK_EX); }
function mfa_locked_for($uid) { $u = (mfa_state()[$uid]['lockedUntil'] ?? 0); return $u > time() ? $u - time() : 0; }
function mfa_fail($uid) {
    $r = mfa_state()[$uid] ?? []; $r['fails'] = ($r['fails'] ?? 0) + 1;
    if ($r['fails'] >= MFA_MAX_ATTEMPTS) { $r['lockedUntil'] = time() + MFA_LOCK_SECONDS; $r['fails'] = 0; }
    mfa_state_put($uid, $r);
}
function mfa_clear_fails($uid) { mfa_state_put($uid, []); }

function mfa_send_code($uid, $name) {
    $cfg = mfa_config();
    $email = mfa_email_for($uid);
    if (!$email) return [false, 'No email address is on file for this account. Please contact the administrator.'];
    $last = $_SESSION['ssms_pending']['sentAt'] ?? 0;
    if (time() - $last < $cfg['resend_cooldown']) return [false, 'Please wait ' . ($cfg['resend_cooldown'] - (time() - $last)) . ' seconds before requesting a new code.'];
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $body = "Hello $name,\n\nYour one-time verification code is:\n\n    $code\n\nIt expires in " . intdiv($cfg['otp_ttl'], 60)
          . " minutes and can be used once. If you did not try to sign in, ignore this email and tell your administrator.\n\n- Session and Legislative Meeting Management System";
    try {
        if (mfa_demo_mode()) file_put_contents(SSMS_DATA_DIR . '/otp_outbox.log', date('Y-m-d H:i:s') . "  to=$email  code=$code\n", FILE_APPEND | LOCK_EX);
        else ssms_smtp_send($cfg, $email, "Your SSMS verification code: $code", $body);
    } catch (Throwable $e) {
        error_log('SSMS OTP mail failed: ' . $e->getMessage());
        return [false, 'We could not send the email right now. Please try again, or contact the administrator.'];
    }
    $_SESSION['ssms_pending'] += ['sentAt' => 0];
    $_SESSION['ssms_pending']['otp'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['ssms_pending']['exp'] = time() + $cfg['otp_ttl'];
    $_SESSION['ssms_pending']['sentAt'] = time();
    $_SESSION['ssms_pending']['email'] = $email;
    return [true, 'A 6-digit code was sent to ' . mfa_mask($email) . '.'];
}
function mfa_check_code($code) {
    $p = $_SESSION['ssms_pending'] ?? null;
    $code = preg_replace('/\s+/', '', (string)$code);
    if (!$p || empty($p['otp']) || time() > ($p['exp'] ?? 0)) return 'expired';
    if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, $p['otp'])) return 'bad';
    unset($_SESSION['ssms_pending']['otp']);     // single use
    return 'ok';
}

function ssms_begin_mfa($uid, $name, $role, $label) {
    session_regenerate_id(true);
    unset($_SESSION['ssms_user']);
    $_SESSION['ssms_pending'] = ['uid' => $uid, 'name' => $name, 'role' => $role, 'label' => $label, 'at' => time()];
}
function ssms_pending() {
    $p = $_SESSION['ssms_pending'] ?? null;
    if (!$p || time() - $p['at'] > MFA_PENDING_TTL) { unset($_SESSION['ssms_pending']); return null; }
    return $p;
}
function ssms_complete_login() {
    $p = $_SESSION['ssms_pending'];
    session_regenerate_id(true);
    $_SESSION['ssms_user'] = $p['uid'];
    $_SESSION['ssms_name'] = $p['name'];
    if ($p['role']) $_SESSION['ssms_role'] = $p['role'];
    unset($_SESSION['ssms_pending']);
}
