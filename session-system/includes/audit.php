<?php
/**
 * Append-only, hash-chained audit log (data/audit.log, one JSON object per line).
 * Each entry stores the SHA-256 of the previous entry, so deleting or editing any line
 * breaks the chain and is detected by ssms_audit_verify(). The application has no code path
 * that edits or deletes entries. Never pass passwords, OTPs or secrets in $ctx.
 */
const SSMS_AUDIT_FORBIDDEN = ['password', 'pass', 'otp', 'code', 'secret', 'token', 'recovery', 'hash'];

function ssms_audit_clean(array $ctx): array {
    $out = [];
    foreach ($ctx as $k => $v) {
        foreach (SSMS_AUDIT_FORBIDDEN as $bad) {
            if (stripos((string)$k, $bad) !== false) { $out[$k] = '[redacted]'; continue 2; }
        }
        if (is_array($v)) $out[$k] = ssms_audit_clean($v);
        else $out[$k] = is_string($v) ? mb_substr($v, 0, 300) : $v;
    }
    return $out;
}

function ssms_audit(string $action, $target = null, string $result = 'ok', array $ctx = [], ?array $actor = null): void {
    try {
        if ($actor === null && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) {
            $actor = ['id' => (int)$_SESSION['uid'], 'role' => null];
            $u = @ssms_find('users', (int)$_SESSION['uid']);
            if ($u) $actor['role'] = $u['role'];
        }
        $file = SSMS_DATA_DIR . '/audit.log';
        $fh = fopen($file, 'c+');
        if (!$fh) return;
        flock($fh, LOCK_EX);
        $prev = str_repeat('0', 64); $lastId = 0;
        $size = filesize($file);
        if ($size > 0) {
            fseek($fh, max(0, $size - 4096));
            $tail = stream_get_contents($fh);
            $lines = array_values(array_filter(explode("\n", $tail)));
            $last = end($lines);
            if ($last !== false) {
                $prev = hash('sha256', $last);
                $j = json_decode($last, true);
                $lastId = (int)($j['id'] ?? 0);
            }
        }
        $entry = [
            'id' => $lastId + 1, 'ts' => date('c'),
            'actor_id' => $actor['id'] ?? null, 'actor_role' => $actor['role'] ?? null,
            'action' => $action, 'target' => $target === null ? null : (string)$target,
            'result' => $result, 'ip' => ssms_ip(), 'ctx' => ssms_audit_clean($ctx), 'prev' => $prev,
        ];
        fseek($fh, 0, SEEK_END);
        fwrite($fh, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        fflush($fh);
        flock($fh, LOCK_UN); fclose($fh);
        @chmod($file, 0640);
    } catch (Throwable $e) {
        error_log('[SSMS] audit write failed: ' . $e->getMessage());
    }
}

function ssms_audit_read(int $limit = 200, ?string $filterAction = null): array {
    $file = SSMS_DATA_DIR . '/audit.log';
    if (!is_file($file)) return [];
    $rows = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $j = json_decode($line, true);
        if (is_array($j) && ($filterAction === null || strpos($j['action'], $filterAction) === 0)) $rows[] = $j;
    }
    return array_slice(array_reverse($rows), 0, $limit);
}

/** Returns [bool intact, ?int firstBrokenId]. */
function ssms_audit_verify(): array {
    $file = SSMS_DATA_DIR . '/audit.log';
    if (!is_file($file)) return [true, null];
    $prev = str_repeat('0', 64);
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $j = json_decode($line, true);
        if (!is_array($j) || ($j['prev'] ?? '') !== $prev) return [false, $j['id'] ?? null];
        $prev = hash('sha256', $line);
    }
    return [true, null];
}
