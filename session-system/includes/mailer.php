<?php
/** Minimal SMTP client (STARTTLS + AUTH LOGIN) — no Composer needed. */
function smtp_read($fp) {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) { $data .= $line; if (strlen($line) < 4 || $line[3] === ' ') break; }
    return $data;
}
function smtp_cmd($fp, $cmd, $expect) {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $r = smtp_read($fp);
    if (strpos($r, (string)$expect) !== 0) throw new RuntimeException('SMTP error: ' . trim($r));
    return $r;
}
function ssms_smtp_send($cfg, $to, $subject, $body) {
    $fp = @stream_socket_client($cfg['smtp_host'] . ':' . $cfg['smtp_port'], $en, $es, 12);
    if (!$fp) throw new RuntimeException("Cannot reach mail server ($es)");
    stream_set_timeout($fp, 12);
    try {
        smtp_cmd($fp, null, 220);
        smtp_cmd($fp, 'EHLO ssms.local', 250);
        if (($cfg['smtp_secure'] ?? 'tls') === 'tls') {
            smtp_cmd($fp, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS failed');
            smtp_cmd($fp, 'EHLO ssms.local', 250);
        }
        if ($cfg['smtp_user'] !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', 334);
            smtp_cmd($fp, base64_encode($cfg['smtp_user']), 334);
            smtp_cmd($fp, base64_encode($cfg['smtp_pass']), 235);
        }
        $from = $cfg['smtp_user'] ?: 'noreply@ssms.local';
        smtp_cmd($fp, "MAIL FROM:<$from>", 250);
        smtp_cmd($fp, "RCPT TO:<$to>", 250);
        smtp_cmd($fp, 'DATA', 354);
        $headers = "From: =?UTF-8?B?" . base64_encode($cfg['from_name']) . "?= <$from>\r\nTo: <$to>\r\n"
                 . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        fwrite($fp, $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n");
        $r = smtp_read($fp); if (strpos($r, '250') !== 0) throw new RuntimeException('SMTP error: ' . trim($r));
        @fwrite($fp, "QUIT\r\n");
    } finally { fclose($fp); }
}
