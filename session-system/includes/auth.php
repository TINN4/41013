<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['ssms_user'])) {
    $inSubfolder = (strpos($_SERVER['SCRIPT_NAME'], '/modules/') !== false)
                || (strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false);
    $base = $inSubfolder ? '../' : '';
    header('Location: ' . $base . 'login.php');
    exit();
}
