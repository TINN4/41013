<?php
// includes/bootstrap.php
// One place to harden environment-level settings before anything else
// runs. Every entry point (login.php, logout.php, qr_login.php,
// api/qr_login.php, includes/auth.php) requires this instead of calling
// session_start() directly.

// Never show raw PHP errors/stack traces to whoever's looking at the
// page — a database hiccup or bad config shouldn't leak file paths or
// query fragments to a visitor. Still log errors server-side if the
// host's error_log is configured, just not display them.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    // Detect HTTPS across common proxy/load-balancer setups too, not
    // just a direct connection — hosts often terminate SSL upstream and
    // forward plain HTTP internally, in which case $_SERVER['HTTPS'] is
    // empty even though the visitor is genuinely on https://.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,   // cookie only ever sent over HTTPS once SSL is live — set up SSL first, or this silently blocks login on plain HTTP
        'httponly' => true,       // JavaScript can never read the session cookie — closes off a whole class of XSS-driven session theft
        'samesite' => 'Lax',      // blocks the cookie from being sent on most cross-site requests, an extra layer alongside the CSRF tokens
    ]);
    session_start();
}

// Baseline security headers on every response.
header('X-Content-Type-Options: nosniff');   // stop browsers from guessing/mis-sniffing content types
header('X-Frame-Options: DENY');             // stop the whole app from being embedded in another site's <iframe> (clickjacking)
header('Referrer-Policy: strict-origin-when-cross-origin');
// Voice dictation on the Proceedings page needs microphone access. Some
// hosts/proxies send a default Permissions-Policy that blocks it; being
// explicit here means that feature never silently fails because of an
// environment default outside this app's control.
header('Permissions-Policy: microphone=(self)');
