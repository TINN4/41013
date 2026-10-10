<?php
/**
 * Server-side MFA recovery for when NO administrator can sign in (e.g. the only admin lost their
 * device and recovery codes). Requires shell access to the server, which is the intended trust boundary.
 *   php tools/mfa_break_glass.php <username> "<reason>"
 * Clears the user's MFA (they must re-enroll), ends their sessions, and writes an audit entry.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SSMS_DATA_DIR', dirname(__DIR__) . '/data');
require dirname(__DIR__) . '/includes/config.php';
require dirname(__DIR__) . '/includes/store.php';
require dirname(__DIR__) . '/includes/audit.php';
function ssms_ip(): string { return 'cli'; }
[$_, $username, $reason] = array_pad($argv, 3, '');
if ($username === '' || $reason === '') { fwrite(STDERR, "usage: php tools/mfa_break_glass.php <username> \"<reason>\"\n"); exit(1); }
$target = null; foreach (ssms_read('users') as $u) if (strcasecmp($u['username'], $username) === 0) $target = $u;
if (!$target) { fwrite(STDERR, "no such user\n"); exit(1); }
ssms_backup_data('break-glass');
ssms_update('users', $target['id'], ['mfa' => ['enabled' => false, 'secret_enc' => null, 'last_counter' => 0, 'recovery' => [], 'enrolled_at' => null], 'mfa_reset_request' => null, 'session_version' => ($target['session_version'] ?? 1) + 1]);
ssms_audit('mfa.break_glass_reset', $target['id'], 'ok', ['reason' => $reason, 'operator' => get_current_user()], ['id' => null, 'role' => 'server-operator']);
echo "MFA cleared for {$target['username']}. They must re-enroll at next sign-in.\n";
