<?php
// One-shot go-live helper. Run as www-data from the Terminal (see instructions). Idempotent.
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$sender = trim((string)getenv('GL_SMTP_USER')); $pass = preg_replace('/\s+/', '', (string)getenv('GL_SMTP_PASS'));
$adminMail = strtolower(trim((string)getenv('GL_ADMIN_EMAIL'))); $url = rtrim((string)getenv('GL_APP_URL'), '/');
if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || $pass === '' || !filter_var($adminMail, FILTER_VALIDATE_EMAIL) || $url === '') { fwrite(STDERR, "Fill in all four values at the top first.\n"); exit(1); }

/* 1. remove the temporary debug patch from the error page */
$bs = $root . '/includes/bootstrap.php'; $s = file_get_contents($bs);
$s = preg_replace("/ \[DEBUG ' \. htmlspecialchars\(.*?\) \. '\]/s", '', $s, -1, $n);
if ($n) { file_put_contents($bs, $s); echo "removed debug patch\n"; }
@unlink($root . '/diag.php');

/* 2. settings into config/.env (real environment variables set in HostForge still win over this file) */
$envFile = $root . '/config/.env';
if (!is_file($envFile) && is_file($root . '/config/.env.example')) copy($root . '/config/.env.example', $envFile);
$vals = ['SSMS_ENV' => 'production', 'SSMS_APP_URL' => $url, 'SSMS_SMTP_HOST' => 'smtp.gmail.com', 'SSMS_SMTP_PORT' => '587',
         'SSMS_SMTP_ENCRYPTION' => 'tls', 'SSMS_SMTP_USER' => $sender, 'SSMS_SMTP_PASS' => $pass, 'SSMS_MAIL_FROM' => $sender];
$lines = is_file($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [];
if (!preg_grep('/^SSMS_APP_KEY=.+/', $lines)) $vals['SSMS_APP_KEY'] = base64_encode(random_bytes(32));
foreach ($vals as $k => $v) {
    $found = false;
    foreach ($lines as $i => $l) if (preg_match('/^' . $k . '=/', $l)) { $lines[$i] = "$k=$v"; $found = true; }
    if (!$found) $lines[] = "$k=$v";
}
file_put_contents($envFile, implode("\n", $lines) . "\n"); chmod($envFile, 0640);
echo "settings written to config/.env\n";

/* 3. use the app's own code from here on */
require $root . '/includes/bootstrap.php';
require $root . '/includes/authflow.php';
$now = ssms_now();

/* 4. real administrator, e-mail pre-verified by the operator, random password nobody knows (set via one-time link) */
$mine = null;
foreach (ssms_read('users') as $u) if (strtolower((string)($u['email'] ?? '')) === $adminMail && empty($u['is_dev_account'])) $mine = $u;
if (!$mine) {
    $name = 'sysadmin'; $i = 1; while (ssms_user_by_username($name)) $name = 'sysadmin' . (++$i);
    $id = ssms_insert('users', [
        'username' => $name, 'name' => 'System Administrator', 'role' => 'sysadmin', 'member_id' => null,
        'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'active' => true,
        'email' => $adminMail, 'email_verified' => true, 'email_verified_at' => $now, 'pending_email' => null,
        'mfa' => ['email_otp' => false], 'failed_count' => 0, 'locked_until' => 0, 'session_version' => 1,
        'created_at' => $now, 'last_login_at' => null,
    ]);
    $mine = ssms_find('users', $id);
    echo "created administrator account: {$mine['username']} ({$adminMail})\n";
} else echo "administrator account already exists: {$mine['username']}\n";

/* 5. delete every development account (they share a publicly known password) */
$removed = 0;
ssms_mutate('users', function ($rows) use (&$removed) {
    return array_values(array_filter($rows, function ($r) use (&$removed) { if (!empty($r['is_dev_account'])) { $removed++; return false; } return true; }));
});
echo "deleted $removed development account(s)\n";

/* 6. one-time link so the administrator chooses a password (shown here only, never e-mailed) */
[$plain, $hash] = ssms_new_token();
ssms_update('users', $mine['id'], ['pwreset' => ['hash' => $hash, 'expires' => time() + ssms_email_link_ttl(), 'email' => null]]);
ssms_audit('recovery.password_link_issued', $mine['id'], 'ok', ['reason' => 'go-live setup', 'operator' => get_current_user()], ['id' => null, 'role' => 'server-operator']);
echo "\nOPEN THIS LINK ONCE to choose your password (valid " . (int)(ssms_email_link_ttl() / 3600) . " hours):\n" . ssms_app_url() . "/reset_password.php?token=$plain\n";

/* 7. real test e-mail */
[$ok, $why] = ssms_mail_send($adminMail, 'SSMS e-mail test', "If you can read this, sign-in codes will reach you." . ssms_mail_footer());
echo $ok ? "\nTEST E-MAIL SENT to $adminMail - check inbox and spam.\n" : "\nTEST E-MAIL FAILED ($why). Re-check the Gmail address and the App Password (16 letters, 2-Step Verification must be on).\n";
