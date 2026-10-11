<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/authflow.php';
ssms_start_session();
// Logout is state-changing: POST + CSRF only (a GET link could be triggered by any other site).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ssms_redirect('login.php');
ssms_csrf_check();
if (!empty($_SESSION['pending']['txid'])) { ssms_audit('auth.login_cancelled', (int)$_SESSION['pending']['uid'], 'ok'); ssms_otp_cancel_tx((string)$_SESSION['pending']['txid']); }
if (!empty($_SESSION['uid'])) ssms_audit('auth.logout', (int)$_SESSION['uid'], 'ok');
ssms_destroy_session();
ssms_redirect('login.php');
