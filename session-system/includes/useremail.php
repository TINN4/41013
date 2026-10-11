<?php
/**
 * Account e-mail ownership: normalisation, uniqueness, verification links and e-mailed password links.
 *
 * Model (users.json), all server-side and never taken from the browser:
 *   email, email_verified, email_verified_at   the TRUSTED address; the only one an OTP is ever sent to
 *   pending_email {address, token_hash, expires, sent_at, by}   an address awaiting proof of ownership
 *   pwreset {hash, expires, email}   one-time password link; 'email' = the address it was delivered to
 * A new or changed address becomes trusted only when the person who controls that inbox opens the
 * e-mailed link and confirms. An administrator typing an address never makes it trusted.
 */
require_once __DIR__ . '/mailer.php';

function ssms_email_normalize(string $e): ?string {
    $e = strtolower(trim($e));
    if ($e === '' || strlen($e) > 254 || preg_match('/[\s,;<>()"\\\\]/', $e)) return null;
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}
function ssms_user_email_verified(array $u): bool {
    return !empty($u['email_verified']) && !empty($u['email']) && ssms_email_normalize((string)$u['email']) !== null;
}
/** True if $email is the trusted or pending address of any account other than $exceptId. */
function ssms_email_taken(array $rows, string $email, int $exceptId = 0): bool {
    foreach ($rows as $r) {
        if ((int)($r['id'] ?? 0) === $exceptId) continue;
        if (strcasecmp((string)($r['email'] ?? ''), $email) === 0) return true;
        if (strcasecmp((string)($r['pending_email']['address'] ?? ''), $email) === 0) return true;
    }
    return false;
}
function ssms_email_link_ttl(): int { return ssms_cfg_int('SSMS_EMAIL_LINK_TTL_HOURS', 48, 1, 168) * 3600; }
function ssms_new_token(): array { $t = bin2hex(random_bytes(32)); return [$t, hash('sha256', $t)]; }
function ssms_mail_footer(): string {
    return "\n\n-- \nSession and Legislative Meeting Management System\nSangguniang Panlungsod ng San Jose del Monte\nThis is an automated message. Do not reply.";
}

/**
 * Record $address as the user's PENDING address and e-mail a verification link to it.
 * Returns 'sent' | 'conflict' | 'not_configured' | 'send_failed' | 'bad_address'.
 * $revokeCurrent drops the currently trusted address immediately (used when it is lost or compromised).
 */
function ssms_email_begin_verification(int $uid, string $address, ?int $byId = null, bool $revokeCurrent = false, bool $deliver = true): string {
    $address = ssms_email_normalize($address);
    if ($address === null) return 'bad_address';
    $conflict = false; [$plain, $hash] = ssms_new_token();
    ssms_mutate('users', function (array $rows) use ($uid, $address, $byId, $revokeCurrent, $hash, &$conflict) {
        if (ssms_email_taken($rows, $address, $uid)) { $conflict = true; return $rows; }
        foreach ($rows as &$r) if ((int)$r['id'] === $uid) {
            if (strcasecmp((string)($r['email'] ?? ''), $address) === 0 && !empty($r['email_verified'])) return $rows;   // already trusted: nothing to do
            $r['pending_email'] = ['address' => $address, 'token_hash' => $hash, 'expires' => time() + ssms_email_link_ttl(), 'sent_at' => time(), 'by' => $byId];
            if ($revokeCurrent) { $r['email'] = null; $r['email_verified'] = false; $r['email_verified_at'] = null; $r['session_version'] = (int)($r['session_version'] ?? 1) + 1; }
        }
        return $rows;
    });
    if ($conflict) return 'conflict';
    if (!$deliver) return 'sent';                                   // caller sends its own message (e.g. the account invitation)
    $u = ssms_user_by_id($uid);
    if ($u && ssms_user_email_verified($u) && strcasecmp((string)$u['email'], $address) === 0) return 'sent';
    return ssms_email_deliver_verification($address, $plain, (string)($u['name'] ?? ''));
}

/** Re-issue the link for an address that is already pending. */
function ssms_email_resend_verification(int $uid): string {
    $u = ssms_user_by_id($uid);
    $addr = $u['pending_email']['address'] ?? null;
    if (!$addr) return 'bad_address';
    return ssms_email_begin_verification($uid, $addr, $u['pending_email']['by'] ?? null);
}

function ssms_email_deliver_verification(string $address, string $plainToken, string $name): string {
    $base = ssms_app_url();
    if ($base === null || !ssms_mail_configured()) return 'not_configured';
    $hrs = (int)(ssms_email_link_ttl() / 3600);
    $body = "Hello" . ($name !== '' ? " $name" : '') . ",\n\n"
          . "This e-mail address was registered for an account in the Session and Legislative Meeting Management System. "
          . "Login verification codes will be sent here once you confirm that this address is yours.\n\n"
          . "Confirm this address (valid for $hrs hours):\n$base/verify_email.php?token=$plainToken\n\n"
          . "If you were not expecting this message, ignore it - the address will not be used.";
    [$ok] = ssms_mail_send($address, 'Confirm your e-mail address - SSMS', $body . ssms_mail_footer());
    return $ok ? 'sent' : 'send_failed';
}

/**
 * Complete verification from the emailed token. Returns [?array $user, string $status];
 * status: 'ok' | 'invalid' | 'conflict'. Single use; re-checks uniqueness under the lock.
 */
function ssms_email_verify_commit(string $token): array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return [null, 'invalid'];
    $h = hash('sha256', $token); $found = null; $status = 'invalid';
    ssms_mutate('users', function (array $rows) use ($h, &$found, &$status) {
        foreach ($rows as $i => $r) {
            $p = $r['pending_email'] ?? null;
            if (!$p || empty($p['token_hash']) || !hash_equals($p['token_hash'], $h) || ($p['expires'] ?? 0) < time() || empty($r['active'])) continue;
            if (ssms_email_taken($rows, $p['address'], (int)$r['id'])) { $status = 'conflict'; return $rows; }
            $changed = !empty($r['email']) && strcasecmp($r['email'], $p['address']) !== 0;
            $rows[$i]['email'] = $p['address']; $rows[$i]['email_verified'] = true; $rows[$i]['email_verified_at'] = ssms_now();
            $rows[$i]['pending_email'] = null;
            if ($changed) $rows[$i]['session_version'] = (int)($r['session_version'] ?? 1) + 1;   // end sessions bound to the old address
            $found = $rows[$i]; $status = 'ok'; return $rows;
        }
        return $rows;
    });
    return [$found, $status];
}

/**
 * Mail a one-time password link (invitation for a new account, or a reset) to the address the
 * account holder controls: the trusted address, else the pending one. The administrator never sees the link.
 * Following it proves control of that inbox, so it also confirms a pending address.
 * Returns 'sent' | 'no_email' | 'not_configured' | 'send_failed'.
 */
function ssms_send_password_link(int $uid, string $kind): string {
    $u = ssms_user_by_id($uid);
    if (!$u) return 'no_email';
    $addr = ssms_user_email_verified($u) ? $u['email'] : ($u['pending_email']['address'] ?? null);
    if (!$addr) return 'no_email';
    $base = ssms_app_url();
    if ($base === null || !ssms_mail_configured()) return 'not_configured';
    [$plain, $hash] = ssms_new_token();
    ssms_update('users', $uid, ['pwreset' => ['hash' => $hash, 'expires' => time() + ssms_email_link_ttl(), 'email' => $addr]]);
    $hrs = (int)(ssms_email_link_ttl() / 3600);
    $intro = $kind === 'invite'
        ? "An account (username: {$u['username']}) has been created for you in the Session and Legislative Meeting Management System. Choose your password to activate it."
        : "A password reset was requested for your account (username: {$u['username']}).";
    $body = "Hello {$u['name']},\n\n$intro\n\nSet your password (single use, valid for $hrs hours):\n$base/reset_password.php?token=$plain\n\n"
          . "Passwords are never sent by e-mail. If you did not expect this message, ignore it.";
    [$ok] = ssms_mail_send($addr, $kind === 'invite' ? 'Your SSMS account invitation' : 'Reset your SSMS password', $body . ssms_mail_footer());
    return $ok ? 'sent' : 'send_failed';
}

function ssms_email_status_message(string $s, string $address = ''): array {
    $to = $address !== '' ? ' to ' . ssms_mask_email($address) : '';
    return match ($s) {
        'sent'           => ['ok',   "Message sent$to."],
        'conflict'       => ['bad',  'That e-mail address is already used by another account.'],
        'bad_address'    => ['bad',  'Enter a valid e-mail address.'],
        'no_email'       => ['bad',  'This account has no e-mail address on file.'],
        'not_configured' => ['warn', 'E-mail delivery is not configured (SMTP settings or SSMS_APP_URL). Nothing was sent.'],
        default          => ['warn', 'The message could not be delivered. Check the SMTP settings and the server log, then resend.'],
    };
}
