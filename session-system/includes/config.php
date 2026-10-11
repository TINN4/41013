<?php
/** Minimal .env loader. Real environment variables win over the file. */
function ssms_cfg(string $key, ?string $default = null): ?string {
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = getenv('SSMS_ENV_FILE') ?: dirname(__DIR__) . '/config/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                [$k, $v] = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
    }
    $real = getenv($key);
    if ($real !== false && $real !== '') return $real;
    return (isset($env[$key]) && $env[$key] !== '') ? $env[$key] : $default;
}

/** Decoded 32-byte application key, or null when not configured. */
function ssms_app_key(): ?string {
    $b = ssms_cfg('SSMS_APP_KEY');
    if (!$b) return null;
    $k = base64_decode($b, true);
    return ($k !== false && strlen($k) === 32) ? $k : null;
}

/** Integer setting clamped to [$min, $max]; invalid or missing values fall back to $default. */
function ssms_cfg_int(string $key, int $default, int $min, int $max): int {
    $v = ssms_cfg($key);
    if ($v === null || !preg_match('/^\d{1,9}$/', $v)) return $default;
    return max($min, min($max, (int)$v));
}

/** Base URL (to the session-system folder) used in e-mailed links. Never derived from the Host header. */
function ssms_app_url(): ?string {
    $u = rtrim((string)ssms_cfg('SSMS_APP_URL', ''), '/');
    return preg_match('#^https?://[A-Za-z0-9.\-]+(:\d{1,5})?(/[A-Za-z0-9._~/\-]*)?$#', $u) ? $u : null;
}
