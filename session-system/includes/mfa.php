<?php
/**
 * TOTP (RFC 6238 / HOTP RFC 4226), Base32 (RFC 4648), secret encryption and recovery codes.
 * Uses only PHP built-ins (hash_hmac, random_bytes, libsodium); no custom cryptography.
 * Verified against the RFC 6238 SHA-1 test vectors in tests/unit_totp.php.
 */
const SSMS_B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function ssms_b32_encode(string $bin): string {
    $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = ''; foreach (str_split($bits, 5) as $chunk) $out .= SSMS_B32[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}
function ssms_b32_decode(string $s): string {
    $s = strtoupper(preg_replace('/[\s=-]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) { $p = strpos(SSMS_B32, $c); if ($p === false) return ''; $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    $out = ''; foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}

function ssms_hotp(string $secret, int $counter, int $digits = 6): string {
    $bin = pack('J', $counter);                                // 8-byte big-endian
    $h = hash_hmac('sha1', $bin, $secret, true);
    $o = ord($h[19]) & 0x0f;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($v % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
function ssms_totp_at(string $secret, int $time, int $step = 30): string { return ssms_hotp($secret, intdiv($time, $step)); }

/**
 * Verify a TOTP code with +/- $window steps. Returns the matched counter (int) or null.
 * $lastCounter implements replay prevention: a counter <= last accepted is rejected.
 */
function ssms_totp_verify(string $secret, string $code, int $lastCounter = 0, int $window = 1, ?int $now = null): ?int {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $now = $now ?? time();
    $cur = intdiv($now, 30);
    $match = null;
    for ($i = -$window; $i <= $window; $i++) {                  // constant-ish: always test all slots
        $c = $cur + $i;
        if (hash_equals(ssms_hotp($secret, $c), $code) && $c > $lastCounter) $match = $c;
    }
    return $match;
}

function ssms_totp_uri(string $b32Secret, string $account, string $issuer = 'SSMS SP San Jose del Monte'): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $b32Secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/* ---- encryption of the TOTP secret at rest (libsodium secretbox, key from env) ---- */
function ssms_secret_encrypt(string $plain): string {
    $key = ssms_app_key();
    if (!$key) throw new RuntimeException('SSMS_APP_KEY is not configured');
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}
function ssms_secret_decrypt(string $enc): ?string {
    $key = ssms_app_key();
    if (!$key) return null;
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    return $p === false ? null : $p;
}

/* ---- recovery codes: 10 x 10 chars from CSPRNG, stored only as keyed HMAC hashes ---- */
function ssms_recovery_hash(string $code): string {
    $key = ssms_app_key() ?? '';
    return hash_hmac('sha256', strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code)), $key);
}
/** @return array [plainCodesForOneTimeDisplay, storedRecords] */
function ssms_recovery_generate(int $n = 10): array {
    $plain = []; $stored = [];
    for ($i = 0; $i < $n; $i++) {
        $raw = ''; for ($j = 0; $j < 10; $j++) $raw .= SSMS_B32[random_int(0, 31)];
        $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $stored[] = ['h' => ssms_recovery_hash($raw), 'used_at' => null];
    }
    return [$plain, $stored];
}
