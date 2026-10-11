<?php
/**
 * Outbound e-mail through a maintained library (PHPMailer, vendored under vendor/ or installed by Composer).
 * All settings come from the environment / config/.env. Nothing here ever logs or returns credentials,
 * and callers receive only a coarse reason code, never a raw SMTP transcript.
 */
$__pm = __DIR__ . '/../vendor/phpmailer/phpmailer/src/';
if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', false) && is_file($__pm . 'PHPMailer.php')) {
    require_once $__pm . 'Exception.php';
    require_once $__pm . 'PHPMailer.php';
    require_once $__pm . 'SMTP.php';
}
unset($__pm);

/** @return array<string,mixed>|null  null when SMTP is not (fully) configured. */
function ssms_mail_config(): ?array {
    $host = trim((string)ssms_cfg('SSMS_SMTP_HOST', ''));
    $from = trim((string)ssms_cfg('SSMS_MAIL_FROM', ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $host)) return null;
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return null;
    $enc = strtolower((string)ssms_cfg('SSMS_SMTP_ENCRYPTION', 'tls'));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) return null;
    $loopback = in_array($host, ['localhost', '127.0.0.1'], true);
    if ($enc === 'none' && !$loopback && ssms_cfg('SSMS_ENV', 'development') === 'production') return null;   // no plaintext SMTP to remote hosts in production
    $name = trim(preg_replace('/[\r\n]+/', ' ', (string)ssms_cfg('SSMS_MAIL_FROM_NAME', 'SSMS - SP San Jose del Monte')));
    return [
        'host' => $host, 'port' => ssms_cfg_int('SSMS_SMTP_PORT', 587, 1, 65535), 'enc' => $enc,
        'user' => (string)ssms_cfg('SSMS_SMTP_USER', ''), 'pass' => (string)ssms_cfg('SSMS_SMTP_PASS', ''),
        'from' => $from, 'from_name' => $name,
    ];
}
function ssms_mail_configured(): bool {
    return ssms_mail_config() !== null && class_exists('PHPMailer\\PHPMailer\\PHPMailer', false);
}

function ssms_mail_sanitize_error(string $msg, array $cfg): string {
    foreach ([$cfg['pass'] ?? '', $cfg['user'] ?? ''] as $secret) {
        if ($secret !== '') $msg = str_replace($secret, '[hidden]', $msg);
    }
    return mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 200);
}

/**
 * Send a plain-text message. Returns [bool $ok, string $reason] where $reason is
 * 'ok' | 'not_configured' | 'send_failed'. Technical detail goes to the server error log only.
 */
function ssms_mail_send(string $to, string $subject, string $body): array {
    $cfg = ssms_mail_config();
    if ($cfg === null || !class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) return [false, 'not_configured'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) return [false, 'send_failed'];
    try {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = $cfg['host']; $m->Port = $cfg['port'];
        $m->SMTPSecure = $cfg['enc'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                        : ($cfg['enc'] === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        $m->SMTPAutoTLS = $cfg['enc'] === 'tls';
        if ($cfg['user'] !== '') { $m->SMTPAuth = true; $m->Username = $cfg['user']; $m->Password = $cfg['pass']; }
        $m->Timeout = 10;
        $m->CharSet = 'UTF-8';
        $m->SMTPDebug = 0;
        $m->setFrom($cfg['from'], $cfg['from_name']);
        $m->addAddress($to);
        $m->Subject = $subject;
        $m->isHTML(false);
        $m->Body = $body;
        $m->send();
        return [true, 'ok'];
    } catch (Throwable $e) {
        error_log('[SSMS] mail send failed: ' . get_class($e) . ': ' . ssms_mail_sanitize_error($e->getMessage(), $cfg));
        return [false, 'send_failed'];
    }
}

/** "m****1@g****.com" style masking for display. */
function ssms_mask_email(string $email): string {
    $at = strrpos($email, '@');
    if ($at === false) return '***';
    $l = substr($email, 0, $at); $d = substr($email, $at + 1);
    $dot = strrpos($d, '.');
    $dh = $dot === false ? $d : substr($d, 0, $dot); $tld = $dot === false ? '' : substr($d, $dot);
    $ml = mb_substr($l, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($l) - 2))) . (mb_strlen($l) > 2 ? mb_substr($l, -1) : '');
    return $ml . '@' . mb_substr($dh, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($dh) - 1))) . $tld;
}
