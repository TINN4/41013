<?php
// Unit test: TOTP against RFC 6238 Appendix B (SHA-1) vectors (6-digit truncation of the published 8-digit values)
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/mfa.php';
$secret = '12345678901234567890';
$vec = [59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'];
$fail = 0;
foreach ($vec as $t => $exp) { $got = ssms_totp_at($secret, $t); $ok = $got === $exp; $fail += !$ok; echo ($ok ? 'PASS' : 'FAIL') . " RFC6238 t=$t expect=$exp got=$got\n"; }
// base32 round trip + known RFC 4648 vector
$ok = ssms_b32_encode('foobar') === 'MZXW6YTBOI'; $fail += !$ok; echo ($ok?'PASS':'FAIL')." b32 encode foobar\n";
$ok = ssms_b32_decode('MZXW6YTBOI') === 'foobar'; $fail += !$ok; echo ($ok?'PASS':'FAIL')." b32 decode\n";
$r = random_bytes(20); $ok = ssms_b32_decode(ssms_b32_encode($r)) === $r; $fail += !$ok; echo ($ok?'PASS':'FAIL')." b32 roundtrip 20 random bytes\n";
// verify window + replay
$c = ssms_totp_at($secret, 1000000);
$ok = ssms_totp_verify($secret, $c, 0, 1, 1000000) === intdiv(1000000,30); $fail += !$ok; echo ($ok?'PASS':'FAIL')." verify current step\n";
$ok = ssms_totp_verify($secret, $c, intdiv(1000000,30), 1, 1000000) === null; $fail += !$ok; echo ($ok?'PASS':'FAIL')." replay of same counter rejected\n";
$ok = ssms_totp_verify($secret, $c, 0, 1, 1000000 + 31) !== null; $fail += !$ok; echo ($ok?'PASS':'FAIL')." +1 step drift accepted\n";
$ok = ssms_totp_verify($secret, $c, 0, 1, 1000000 + 95) === null; $fail += !$ok; echo ($ok?'PASS':'FAIL')." expired code (3 steps late) rejected\n";
$ok = ssms_totp_verify($secret, '12ab56', 0, 1, 1000000) === null; $fail += !$ok; echo ($ok?'PASS':'FAIL')." non-numeric rejected\n";
echo $fail ? "FAILED: $fail\n" : "ALL PASSED\n"; exit($fail ? 1 : 0);
