<?php
declare(strict_types=1);

/**
 * HTTP helpers for the 2.8 endpoints (alerts, forecast, sensor bans).
 * cURL only — Active24 drops custom headers sent through stream contexts.
 * Ported from nosicheck.cz src/Http.php (subset). 3.1 adds fetchMany() (curl_multi).
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

    /**
     * Paralelní GET více URL (curl_multi), max $concurrency najednou.
     * 3.1: chmi-meteo-proxy stahuje ~60 souborů stanic ČHMÚ — sekvenčně by to
     * trvalo 5–10 s, paralelně ~1–2 s. Vrací [url => [body|null, httpCode, curlError]].
     *
     * @param string[] $urls
     * @return array<string, array{0: ?string, 1: int, 2: string}>
     */
    public static function fetchMany(array $urls, int $concurrency = 8, int $timeout = 15): array
    {
        $results = [];
        $urls = array_values(array_unique($urls));
        if (!$urls) return $results;
        $mh = curl_multi_init();
        $active = [];   // handle id → url
        $queue = $urls;

        $add = function (string $url) use ($mh, &$active, $timeout): void {
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
                CURLOPT_ENCODING       => '',
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[(int)$ch] = $url;
        };

        while ($queue && count($active) < $concurrency) {
            $add(array_shift($queue));
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status !== CURLM_OK) break;
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $url = $active[(int)$ch] ?? '';
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = $info['result'] === CURLE_OK ? '' : curl_strerror($info['result']);
                $results[$url] = [($body === false || $body === null || $err !== '') ? null : $body, $code, $err];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[(int)$ch]);
                if ($queue) {
                    $add(array_shift($queue));
                    $running = 1;
                }
            }
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running || $active);
        curl_multi_close($mh);

        foreach ($urls as $u) {
            if (!isset($results[$u])) $results[$u] = [null, 0, 'not fetched'];
        }
        return $results;
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
