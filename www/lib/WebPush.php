<?php
// lib/WebPush.php — Web Push bez závislostí (3.6)
//
// Odeslání jedné push zprávy na endpoint z PushSubscription prohlížeče:
//   - šifrování obsahu podle RFC 8291 (ECDH P-256 + HKDF + AES-128-GCM, Content-Encoding: aes128gcm, RFC 8188),
//   - autorizace VAPID podle RFC 8292 (JWT ES256 podepsaný klíčem serveru).
// Jen openssl + hash_hkdf (PHP ≥ 7.3), žádný composer — Active24 nic víc nemá.
// Testovací vektory z RFC 8291 §5 + příloha A: tests/test_webpush.php.
//
// Klíče serveru (VAPID): pár P-256; veřejný klíč jde do prohlížeče jako applicationServerKey,
// soukromý podepisuje JWT. Oba jako base64url (surové bajty: 65 B veřejný = 0x04‖x‖y, 32 B soukromý).

final class WebPush
{
    // --- base64url -------------------------------------------------------------------------
    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $out = base64_decode($s, true);
        return $out === false ? '' : $out;
    }

    // --- EC P-256 klíče ↔ PEM --------------------------------------------------------------
    /** Surový veřejný bod (65 B, 0x04‖x‖y) → PEM SubjectPublicKeyInfo. */
    public static function publicPem(string $raw): string
    {
        if (strlen($raw) !== 65 || $raw[0] !== "\x04") throw new RuntimeException('bad public key');
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Surový soukromý skalár (32 B) + veřejný bod (65 B) → PEM EC PRIVATE KEY (SEC1). */
    public static function privatePem(string $d, string $pub): string
    {
        if (strlen($d) !== 32 || strlen($pub) !== 65) throw new RuntimeException('bad private key');
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    /** Nový pár P-256 → ['public' => 65 B, 'private' => 32 B] (surové bajty). */
    public static function generateKeyPair(): array
    {
        // Náhodný skalár d ∈ [1, n−1]; veřejný bod spočítá openssl z PEM bez veřejné části.
        // (openssl_pkey_new() na Windows bez openssl.cnf selhává, tahle cesta funguje všude.)
        $n = hex2bin('ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551');
        do {
            $d = random_bytes(32);
        } while (strcmp($d, $n) >= 0 || ltrim($d, "\0") === '');
        $der = hex2bin('30310201010420') . $d . hex2bin('a00a06082a8648ce3d030107');
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        return ['public' => self::publicFromPrivatePem($pem), 'private' => $d];
    }

    /** Z PEM soukromého klíče odvodí surový veřejný bod (65 B). */
    public static function publicFromPrivatePem(string $pem): string
    {
        $res = openssl_pkey_get_private($pem);
        if ($res === false) throw new RuntimeException('bad private pem');
        $det = openssl_pkey_get_details($res);
        return "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    // --- Šifrování obsahu (RFC 8291, aes128gcm) --------------------------------------------
    /**
     * @param string $uaPublicB64u   keys.p256dh z PushSubscription (base64url, 65 B)
     * @param string $authB64u       keys.auth z PushSubscription (base64url, 16 B)
     * @param string $plaintext      obsah (JSON)
     * @param ?array $fixed          jen pro testy: ['salt' => 16 B, 'private' => 32 B, 'public' => 65 B] odesílatele
     * @return string tělo požadavku (hlavička aes128gcm + ciphertext + tag)
     */
    public static function encrypt(string $uaPublicB64u, string $authB64u, string $plaintext, ?array $fixed = null): string
    {
        $uaPublic = self::b64uDecode($uaPublicB64u);
        $auth = self::b64uDecode($authB64u);
        if (strlen($uaPublic) !== 65 || strlen($auth) !== 16) throw new RuntimeException('bad subscription keys');

        if ($fixed) {
            $salt = $fixed['salt'];
            $asPrivate = $fixed['private'];
            $asPublic = $fixed['public'];
        } else {
            $salt = random_bytes(16);
            $pair = self::generateKeyPair();
            $asPrivate = $pair['private'];
            $asPublic = $pair['public'];
        }

        $uaKey = openssl_pkey_get_public(self::publicPem($uaPublic));
        $asKey = openssl_pkey_get_private(self::privatePem($asPrivate, $asPublic));
        if ($uaKey === false || $asKey === false) throw new RuntimeException('openssl key load failed');
        $ecdh = openssl_pkey_derive($uaKey, $asKey);
        if ($ecdh === false || strlen($ecdh) !== 32) throw new RuntimeException('ECDH failed');

        // IKM = HKDF(salt = auth, IKM = ecdh, info = "WebPush: info\0" ‖ ua_public ‖ as_public, L = 32)
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $auth);
        // CEK a NONCE z IKM se solí záznamu
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        // Jediný (poslední) záznam: plaintext ‖ 0x02 (bez výplně); rs = 4096
        $record = $plaintext . "\x02";
        if (strlen($record) + 16 > 4096) throw new RuntimeException('payload too large');
        $tag = '';
        $cipher = openssl_encrypt($record, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) throw new RuntimeException('AES-GCM failed');

        $header = $salt . pack('N', 4096) . chr(65) . $asPublic;
        return $header . $cipher . $tag;
    }

    // --- VAPID (RFC 8292) ---------------------------------------------------------------------
    /** DER ECDSA podpis (SEQUENCE{r, s}) → surové r‖s (64 B), jak chce JWS ES256. */
    public static function derToRaw(string $der): string
    {
        $pos = 2; // 0x30 len
        if (ord($der[1]) & 0x80) $pos = 2 + (ord($der[1]) & 0x7f);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if ($der[$pos] !== "\x02") throw new RuntimeException('bad DER signature');
            $len = ord($der[$pos + 1]);
            $int = substr($der, $pos + 2, $len);
            $pos += 2 + $len;
            $int = ltrim($int, "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /**
     * Hlavička Authorization pro daný endpoint.
     * @param string $endpoint   URL push služby (audience = její origin)
     * @param string $subject    "mailto:…" nebo "https://…"
     * @param string $privateB64u soukromý klíč serveru (32 B, base64url)
     * @param string $publicB64u  veřejný klíč serveru (65 B, base64url)
     */
    public static function vapidHeader(string $endpoint, string $subject, string $privateB64u, string $publicB64u, int $now = 0): string
    {
        $u = parse_url($endpoint);
        if (empty($u['scheme']) || empty($u['host'])) throw new RuntimeException('bad endpoint');
        $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $now = $now ?: time();
        $header = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = self::b64u(json_encode(['aud' => $aud, 'exp' => $now + 12 * 3600, 'sub' => $subject]));
        $input = $header . '.' . $payload;

        $priv = self::b64uDecode($privateB64u);
        $pub = self::b64uDecode($publicB64u);
        $key = openssl_pkey_get_private(self::privatePem($priv, $pub));
        if ($key === false) throw new RuntimeException('bad VAPID private key');
        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('VAPID sign failed');
        $jwt = $input . '.' . self::b64u(self::derToRaw($der));
        return 'vapid t=' . $jwt . ', k=' . $publicB64u;
    }

    // --- Odeslání --------------------------------------------------------------------------------
    /**
     * Pošle zašifrovanou zprávu. Vrací ['code' => HTTP kód (0 = síťová chyba), 'error' => string].
     * 201 = přijato; 404/410 = odběr zanikl (smazat); 429 = zpomalit.
     */
    public static function send(array $subscription, string $payload, array $vapid, int $ttl = 3600, string $urgency = 'normal'): array
    {
        $endpoint = (string)($subscription['endpoint'] ?? '');
        $body = self::encrypt((string)$subscription['p256dh'], (string)$subscription['auth'], $payload);
        $auth = self::vapidHeader($endpoint, $vapid['subject'], $vapid['private'], $vapid['public']);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Content-Length: ' . strlen($body),
                'TTL: ' . $ttl,
                'Urgency: ' . $urgency,
                'Authorization: ' . $auth,
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'error' => $err ?: ($code >= 300 ? substr((string)$resp, 0, 200) : '')];
    }
}
