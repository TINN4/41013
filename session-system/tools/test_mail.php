<?php
/** Send one real test message through the configured SMTP account:  php tools/test_mail.php you@example.com */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/bootstrap.php';
$to = ssms_email_normalize($argv[1] ?? '');
if ($to === null) { fwrite(STDERR, "usage: php tools/test_mail.php recipient@example.com\n"); exit(1); }
$c = ssms_mail_config();
if ($c === null || !ssms_mail_configured()) { fwrite(STDERR, "SMTP is not configured (need SSMS_SMTP_HOST, SSMS_MAIL_FROM, valid SSMS_SMTP_ENCRYPTION; PHPMailer present).\n"); exit(2); }
printf("SMTP %s:%d (%s) as %s, from %s\n", $c['host'], $c['port'], $c['enc'], $c['user'] !== '' ? $c['user'] : '(no auth)', $c['from']);
printf("SSMS_APP_URL: %s | SSMS_APP_KEY: %s\n", ssms_app_url() ?? 'NOT SET (links in e-mails will not be sent)', ssms_app_key() ? 'set' : 'NOT SET (OTP cannot be issued)');
[$ok, $why] = ssms_mail_send($to, 'SSMS e-mail delivery test', "This is a test message from the Session and Legislative Meeting Management System.\nIf you can read it, SMTP delivery works." . ssms_mail_footer());
echo $ok ? "SENT to $to. Check the inbox (and spam) to confirm actual delivery.\n" : "FAILED ($why). See the PHP error log for the sanitized reason.\n";
exit($ok ? 0 : 3);
