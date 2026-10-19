<?php
// tests/test_webpush.php — offline test šifrování a VAPID (3.6)
//   php tests/test_webpush.php
// 1. RFC 8291 §5 + příloha A: pevný salt + klíč odesílatele → tělo musí sedět bajt po bajtu.
// 2. VAPID: JWT se ověří veřejným klíčem přes openssl_verify.
// 3. Náhodný pár: encrypt proběhne, hlavička má správnou strukturu.

require_once __DIR__ . '/../www/lib/WebPush.php';

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fail++;
}

// --- 1. RFC 8291 vektor --------------------------------------------------------------------
$plaintext = 'When I grow up, I want to be a watermelon';
$auth      = 'BTBZMqHH6r4Tts7J_aSIgg';
$uaPublic  = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
$asPrivate = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
$asPublic  = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
$salt      = 'DGv6ra1nlYgDCS1FRnbzlw';
$expected  = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

$body = WebPush::encrypt($uaPublic, $auth, $plaintext, [
    'salt' => WebPush::b64uDecode($salt),
    'private' => WebPush::b64uDecode($asPrivate),
    'public' => WebPush::b64uDecode($asPublic),
]);
check('RFC 8291 body matches', WebPush::b64u($body) === $expected, strlen($body) . ' B');

// Odvozený veřejný klíč ze soukromého musí odpovídat vektoru
$pubDerived = WebPush::publicFromPrivatePem(WebPush::privatePem(WebPush::b64uDecode($asPrivate), WebPush::b64uDecode($asPublic)));
check('public key from private matches', WebPush::b64u($pubDerived) === $asPublic);

// --- 2. VAPID ---------------------------------------------------------------------------------
$pair = WebPush::generateKeyPair();
$pubB = WebPush::b64u($pair['public']);
$privB = WebPush::b64u($pair['private']);
$hdr = WebPush::vapidHeader('https://fcm.googleapis.com/fcm/send/abc', 'mailto:info@senzorvzduchu.cz', $privB, $pubB, 1760000000);
check('VAPID header shape', (bool)preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $hdr, $m));
if (!empty($m)) {
    [$h, $p, $s] = explode('.', $m[1]);
    $payload = json_decode(WebPush::b64uDecode($p), true);
    check('VAPID aud/exp/sub', ($payload['aud'] ?? '') === 'https://fcm.googleapis.com' && ($payload['exp'] ?? 0) === 1760000000 + 43200 && ($payload['sub'] ?? '') === 'mailto:info@senzorvzduchu.cz');
    check('VAPID k = public key', $m[2] === $pubB);
    // raw r‖s → DER pro openssl_verify
    $raw = WebPush::b64uDecode($s);
    $der = '';
    foreach ([substr($raw, 0, 32), substr($raw, 32)] as $int) {
        $int = ltrim($int, "\0");
        if (ord($int[0]) & 0x80) $int = "\0" . $int;
        $der .= "\x02" . chr(strlen($int)) . $int;
    }
    $der = "\x30" . chr(strlen($der)) . $der;
    $ok = openssl_verify("$h.$p", $der, openssl_pkey_get_public(WebPush::publicPem($pair['public'])), OPENSSL_ALGO_SHA256);
    check('VAPID signature verifies', $ok === 1, strlen($raw) . ' B raw');
}

// --- 3. Náhodný odesílatel ---------------------------------------------------------------------
$body2 = WebPush::encrypt($uaPublic, $auth, '{"title":"test"}');
check('random sender: header rs=4096, idlen=65', unpack('N', substr($body2, 16, 4))[1] === 4096 && ord($body2[20]) === 65 && $body2[21] === "\x04");
check('random sender: length = 86 + len + 1 + 16', strlen($body2) === 86 + strlen('{"title":"test"}') + 1 + 16);

echo $fail ? "\n$fail FAILED\n" : "\nALL OK\n";
exit($fail ? 1 : 0);
