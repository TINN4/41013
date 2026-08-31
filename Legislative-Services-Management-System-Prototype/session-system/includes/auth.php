<?php
require_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['ssms_user'])) {
    $inSubfolder = (strpos($_SERVER['SCRIPT_NAME'], '/modules/') !== false)
                || (strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false);
    $base = $inSubfolder ? '../' : '';
    header('Location: ' . $base . 'login.php');
    exit();
}

// Stop the browser from showing a cached, still-"logged in" copy of this page
// when the Back button is pressed after logging out. Only staff accounts
// (admin/secretary, who sign in/out through the login form) get this —
// QR/council-member sessions are left alone since those devices stay
// signed in for the whole session and aren't driven by manual login/logout.
$isStaffSession = strpos($_SESSION['ssms_user'], 'qr:') !== 0;
if ($isStaffSession) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Belt-and-suspenders for the bfcache case: some browsers restore a page
// from an in-memory snapshot on Back/Forward and skip the server (and
// the Cache-Control headers above) entirely. This flag tells the page's
// own JS (see includes/footer.php) to force a real reload if that
// happens, so a destroyed session always gets caught.
$ssms_guard_bfcache = $isStaffSession;

// True for admin/secretary logins, false for QR/council-member logins.
function ssms_is_staff() {
    return strpos($_SESSION['ssms_user'] ?? '', 'qr:') !== 0;
}

// Call at the top of any module that only the secretary/admin should be
// able to open (Scheduling, Agenda, Members, Proceedings, Minutes, ...).
// Council members who try to reach the URL directly get bounced to their
// own landing page instead of a raw 403.
function ssms_require_staff() {
    if (!ssms_is_staff()) {
        $inSubfolder = (strpos($_SERVER['SCRIPT_NAME'], '/modules/') !== false)
                    || (strpos($_SERVER['SCRIPT_NAME'], '/api/') !== false);
        $base = $inSubfolder ? '../' : '';
        header('Location: ' . $base . 'modules/attendance.php');
        exit();
    }
}

// --- CSRF protection ---------------------------------------------------
// Every POST form in the app (delete, archive, reset device, mark
// attendance, generate minutes, ...) submits back to a page that includes
// this file, so this is the one choke point where every state-changing
// action can be checked. Without this, any other website the logged-in
// secretary happens to have open could silently submit a hidden form to
// this app using her live session — she'd never see it happen.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        die('Security check failed — this form may have expired. Please refresh the page and try again.');
    }
}

// Echo this inside every <form method="POST">...</form> in the app.
function ssms_csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') . '">';
}
