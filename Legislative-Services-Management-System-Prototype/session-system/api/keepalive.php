<?php
// Called by the idle-timeout warning modal (includes/footer.php) when the
// user clicks "Stay signed in". Simply including auth.php is enough to
// refresh $_SESSION['ssms_last_activity'] to now — that's the actual
// server-side idle-timeout clock, so this is a real reset, not cosmetic.
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'server_time' => time()]);
