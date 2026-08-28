<?php
/**
 * JSON API backing app.html's client-side collections in real MySQL.
 *
 * This mirrors js/store.js's writeKey()-always-writes-the-whole-array
 * pattern: the client keeps its instant local cache (localStorage) for
 * a snappy UI, and calls this endpoint to (a) pull the authoritative
 * copy on load and (b) push the full updated array after any change.
 * No page module in js/pages/ had to change for this — only
 * store.js's low-level writeKey()/initStore() talk to this file.
 *
 * NOTE: app.html currently has no login of its own (it's a static
 * page), so this endpoint is intentionally left open, matching that.
 * If you add authentication to app.html later, gate this file the
 * same way session-system's modules gate on $_SESSION['ssms_user'].
 */

require_once __DIR__ . '/../includes/appdata_store.php';

header('Content-Type: application/json');

// Only these collections get a real database table — the rest
// (feedback, notifications, activities, settings) are per-browser
// app state / ephemeral UI data that doesn't need to be shared or
// durable across devices, so they intentionally stay in localStorage.
$allowedCollections = [
    'councilMembers', 'ordinances', 'resolutions', 'committees',
    'committeeMembers', 'votes', 'records', 'hearings', 'archives',
    'research', 'agenda', 'sessions', 'proceedings',
];

$collection = $_GET['collection'] ?? '';
$action = $_GET['action'] ?? '';

if (!in_array($collection, $allowedCollections, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown or unsupported collection: ' . $collection]);
    exit();
}

$table = 'app_' . $collection;

if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(ssms_app_read($table));
    exit();
}

if ($action === 'bulk_set' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['error' => 'Expected a JSON array of records']);
        exit();
    }
    ssms_app_write($table, $body);
    echo json_encode(['success' => true, 'count' => count($body)]);
    exit();
}

http_response_code(400);
echo json_encode(['error' => 'Unsupported action/method']);
