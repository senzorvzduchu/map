<?php
declare(strict_types=1);

/**
 * HTTP helpers for the 2.8 endpoints (alerts, forecast, sensor bans).
 * cURL only — Active24 drops custom headers sent through stream contexts.
 * Ported from nosicheck.cz src/Http.php (subset).
 */
final class Http
{
    /** GET a URL. Returns [body|null, httpCode, curlError]. */
    public static function fetch(string $url, int $timeout = 15): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        return [$body === false ? null : $body, $code, $err];
    }

    /** Emit a JSON response and exit. */
    public static function jsonOut(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Emit a JSON error and exit. */
    public static function fail(string $message, int $status = 400): never
    {
        self::jsonOut(['error' => $message], $status);
    }

    /** Atomic file write (tmp + rename) — readers never see a torn cache. */
    public static function atomicWrite(string $path, string $content): bool
    {
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Non-blocking exclusive lock for "rebuild the cache once" sections.
     * Returns the handle to pass to unlock(), or null when another request
     * already holds it (caller serves the stale cache instead).
     */
    public static function tryLock(string $lockFile)
    {
        $h = @fopen($lockFile, 'c');
        if ($h === false) {
            return null;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            return null;
        }
        return $h;
    }

    public static function unlock($h): void
    {
        if ($h) {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    /** Read a JSON file into an array, or null. */
    public static function readJson(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }
        $d = json_decode((string)file_get_contents($path), true);
        return is_array($d) ? $d : null;
    }
}
