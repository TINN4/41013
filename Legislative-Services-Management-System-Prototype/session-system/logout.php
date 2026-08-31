<?php
require_once __DIR__ . '/includes/bootstrap.php';
$_SESSION = [];
// Also explicitly expire the session cookie in the browser, not just the
// server-side data — belt-and-suspenders alongside the bfcache guard in
// footer.php from the back-button fix.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: login.php');
exit();
