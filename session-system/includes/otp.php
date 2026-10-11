<?php
/**
 * E-mail one-time passcodes (second factor).
 *
 * A challenge belongs to ONE pending login (a random transaction id kept only in that browser's
 * server-side session) and ONE account. Only a keyed hash of the code is stored (HMAC-SHA256, key
 * derived from SSMS_APP_KEY); the plain code exists only in memory and in the e-mail.
 * All state changes run under the table lock (ssms_mutate), so two simultaneous requests cannot
 * both consume the same challenge or both exceed the attempt budget.
 *
 * Challenge status: active -> used | expired | exhausted | superseded | cancelled | undelivered
 */
require_once __DIR__ . '/useremail.php';

function ssms_otp_ttl(): int       { return ssms_cfg_int('SSMS_OTP_EXPIRY_MIN', 5, 1, 30) * 60; }
function ssms_otp_cooldown(): int  { return ssms_cfg_int('SSMS_OTP_RESEND_COOLDOWN_SEC', 60, 5, 600); }
function ssms_otp_max_tries(): int { return ssms_cfg_int('SSMS_OTP_MAX_ATTEMPTS', 5, 1, 10); }
/** Codes e-mailed per account per 15 minutes before sending is paused (per-IP cap is 5x). */
function ssms_otp_send_cap(): int  { return ssms_cfg_int('SSMS_OTP_SENDS_PER_15MIN', 6, 2, 200); }

function ssms_otp_key(): ?string {
    $k = ssms_app_key();
    return $k === null ? null : hash_hmac('sha256', 'ssms-email-otp-v1', $k, true);
}
function ssms_otp_hash(string $key, int $cid, int $uid, string $code): string {
    return hash_hmac('sha256', "otp|$cid|$uid|$code", $key);
}
function ssms_otp_generate(): string { return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); }

/**
 * Create a fresh challenge for this login and e-mail it. Always supersedes earlier active challenges.
 * Returns ['status' => 'sent'|'cooldown'|'rate_limited'|'no_email'|'not_configured'|'send_failed', 'wait' => seconds].
 */
function ssms_otp_issue(array $user, string $txid): array {
    $uid = (int)$user['id'];
    $actor = ['id' => $uid, 'role' => $user['role'] ?? null];
    $out = fn(string $s, int $wait = 0) => ['status' => $s, 'wait' => $wait];
    if (!ssms_user_email_verified($user)) { ssms_audit('otp.issue', $uid, 'denied', ['reason' => 'no_verified_email'], $actor); return $out('no_email'); }
    $key = ssms_otp_key();
    if ($key === null || !ssms_mail_configured()) { ssms_audit('otp.issue', $uid, 'error', ['reason' => 'not_configured'], $actor); return $out('not_configured'); }

    $rlU = 'otp:send:u:' . $uid; $rlI = 'otp:send:ip:' . ssms_ip();
    $wait = max(ssms_rl_blocked($rlU), ssms_rl_blocked($rlI));
    if ($wait > 0) { ssms_audit('otp.issue', $uid, 'throttled', [], $actor); return $out('rate_limited', $wait); }

    $now = time(); $cool = ssms_otp_cooldown(); $cid = 0; $cooldownLeft = 0; $code = ssms_otp_generate();
    $emailH = hash('sha256', strtolower((string)$user['email'])); $txh = hash('sha256', $txid);
    ssms_mutate('otp_challenges', function (array $rows) use ($uid, $now, $cool, $key, $code, $emailH, $txh, &$cid, &$cooldownLeft) {
        $rows = array_values(array_filter($rows, fn($r) => $r['created_at'] > $now - 86400));   // cleanup: drop challenges older than a day
        foreach ($rows as $r) {                                   // cooldown: resends within ONE pending login (a new login's first code is not delayed; send caps below bound the rest)
            if ((int)$r['user_id'] === $uid && hash_equals($r['tx'], $txh) && $r['status'] !== 'undelivered' && $now - $r['created_at'] < $cool) $cooldownLeft = max($cooldownLeft, $cool - ($now - $r['created_at']));
        }
        if ($cooldownLeft > 0) return $rows;
        foreach ($rows as &$r) if ((int)$r['user_id'] === $uid && $r['status'] === 'active') { $r['status'] = 'superseded'; $r['closed_at'] = $now; }
        unset($r);
        $cid = ssms_next_id($rows);
        $rows[] = ['id' => $cid, 'user_id' => $uid, 'tx' => $txh, 'email_h' => $emailH, 'code_h' => ssms_otp_hash($key, $cid, $uid, $code),
                   'created_at' => $now, 'expires_at' => $now + ssms_otp_ttl(), 'attempts' => 0, 'status' => 'active', 'closed_at' => null];
        return $rows;
    });
    if ($cooldownLeft > 0) { ssms_audit('otp.issue', $uid, 'throttled', ['reason' => 'cooldown'], $actor); return $out('cooldown', $cooldownLeft); }

    $cap = ssms_otp_send_cap(); ssms_rl_fail($rlU, $cap, 900, 900); ssms_rl_fail($rlI, $cap * 5, 900, 900);
    $mins = (int)(ssms_otp_ttl() / 60);
    $body = "Hello {$user['name']},\n\nA login verification code was requested for your account in the Session and Legislative Meeting Management System.\n\n"
          . "    Your verification code:  $code\n\nThis code expires in $mins minute(s) and can be used once.\n"
          . "Do not share it with anyone. SSMS staff will never ask you for it.\n\nIf you did not try to sign in, your password may be known to someone else - change it and tell the System Administrator."
          . ssms_mail_footer();
    [$ok] = ssms_mail_send((string)$user['email'], 'Your SSMS login verification code', $body);
    unset($code, $body);
    if (!$ok) {
        ssms_mutate('otp_challenges', function (array $rows) use ($cid, $now) {
            foreach ($rows as &$r) if ((int)$r['id'] === $cid && $r['status'] === 'active') { $r['status'] = 'undelivered'; $r['closed_at'] = $now; }
            return $rows;
        });
        ssms_audit('otp.issue', $uid, 'failed', ['reason' => 'delivery_failed'], $actor);
        return $out('send_failed');
    }
    ssms_audit('otp.issue', $uid, 'ok', ['cid' => $cid], $actor);
    return $out('sent', $cool);
}

/**
 * Check a submitted code against this login's active challenge. Atomic: success consumes it.
 * Returns ['status' => 'ok'|'invalid'|'expired'|'exhausted'|'none', 'left' => attempts remaining].
 */
function ssms_otp_verify(array $user, string $txid, string $code): array {
    $uid = (int)$user['id']; $key = ssms_otp_key(); $res = ['status' => 'none', 'left' => 0];
    if ($key === null || !preg_match('/^\d{6}$/', $code)) return $res;
    $txh = hash('sha256', $txid); $now = time(); $max = ssms_otp_max_tries();
    $emailH = ssms_user_email_verified($user) ? hash('sha256', strtolower((string)$user['email'])) : '';
    ssms_mutate('otp_challenges', function (array $rows) use ($uid, $txh, $code, $key, $now, $max, $emailH, &$res) {
        $idx = null;
        foreach ($rows as $i => $r) if ((int)$r['user_id'] === $uid && hash_equals($r['tx'], $txh) && ($idx === null || $r['id'] > $rows[$idx]['id'])) $idx = $i;
        if ($idx === null) return $rows;
        $r = &$rows[$idx];
        if ($r['status'] === 'expired') { $res = ['status' => 'expired', 'left' => 0]; return $rows; }
        if ($r['status'] !== 'active') return $rows;                                  // used / superseded / exhausted / cancelled
        if ($now > $r['expires_at']) { $r['status'] = 'expired'; $r['closed_at'] = $now; $res = ['status' => 'expired', 'left' => 0]; return $rows; }
        if ($emailH === '' || !hash_equals($r['email_h'], $emailH)) { $r['status'] = 'cancelled'; $r['closed_at'] = $now; return $rows; }   // address changed since issue
        if (hash_equals($r['code_h'], ssms_otp_hash($key, (int)$r['id'], $uid, $code))) {
            $r['status'] = 'used'; $r['closed_at'] = $now; $res = ['status' => 'ok', 'left' => 0]; return $rows;
        }
        $r['attempts']++;
        if ($r['attempts'] >= $max) { $r['status'] = 'exhausted'; $r['closed_at'] = $now; $res = ['status' => 'exhausted', 'left' => 0]; }
        else $res = ['status' => 'invalid', 'left' => $max - $r['attempts']];
        return $rows;
    });
    return $res;
}

/** Cancel whatever is still active for this pending login (user pressed Cancel / logged out). */
function ssms_otp_cancel_tx(string $txid): void {
    $txh = hash('sha256', $txid); $now = time();
    ssms_mutate('otp_challenges', function (array $rows) use ($txh, $now) {
        foreach ($rows as &$r) if ($r['status'] === 'active' && hash_equals($r['tx'], $txh)) { $r['status'] = 'cancelled'; $r['closed_at'] = $now; }
        return $rows;
    });
}
/** Cancel every active challenge of an account (deactivation, lock, role/e-mail change). */
function ssms_otp_cancel_user(int $uid): void {
    $now = time();
    ssms_mutate('otp_challenges', function (array $rows) use ($uid, $now) {
        foreach ($rows as &$r) if ($r['status'] === 'active' && (int)$r['user_id'] === $uid) { $r['status'] = 'cancelled'; $r['closed_at'] = $now; }
        return $rows;
    });
}

/** Human message for an issue() result. */
function ssms_otp_notice(array $r, string $email = ''): array {
    return match ($r['status']) {
        'sent'         => ['ok',  'A 6-digit verification code was sent to ' . ssms_mask_email($email) . '. It expires in ' . (int)(ssms_otp_ttl() / 60) . ' minutes.'],
        'cooldown'     => ['warn', 'A code was sent recently. Check your inbox, or request another in ' . (int)$r['wait'] . ' second(s).'],
        'rate_limited' => ['bad', 'Too many codes were requested. Please wait ' . max(1, (int)ceil($r['wait'] / 60)) . ' minute(s) and sign in again.'],
        'no_email'     => ['bad', 'Your account has no verified e-mail address. Contact the System Administrator.'],
        default        => ['bad', 'We could not send your verification code right now. Press "Send a new code" to retry, or contact the System Administrator if this continues.'],
    };
}
