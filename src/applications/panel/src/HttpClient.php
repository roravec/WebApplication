<?php
namespace Panel;

/**
 * Small HTTPS client for public services (register RPO, Fio, PrestaShop Webservice, later Peppol access points).
 * Certificates are ALWAYS verified. Shared hostings often have an old system CA store (e.g. without
 * "Sectigo Public Server Authentication Root R46", used by api.statistics.sk) → when the system store can't verify
 * the server, the request is repeated with the Mozilla CA bundle shipped in panel/data/cacert.pem
 * (from https://curl.se/ca/cacert.pem — refresh it once a year or so).
 */
class HttpClient
{
    const UA = 'Mozilla/5.0 (compatible; invoicing-panel)';
    const MAX_PARALLEL = 8;

    /** @return array{0: int, 1: string} [http status, body]; status 0 = no connection (body = error) */
    public static function get(string $url, array $headers = [], int $timeout = 10): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }

    /**
     * @param string|null $body request body (PUT / POST)
     * @return array{0: int, 1: string} [http status, body]; status 0 = no connection (body = error)
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array
    {
        $r = self::send($method, $url, $headers, $body, $timeout, null);
        if ($r[0] === 0 && stripos($r[1], 'certificate') !== false && is_file(self::bundle())) {
            $r = self::send($method, $url, $headers, $body, $timeout, self::bundle());
        }
        return $r;
    }

    public static function bundle(): string
    {
        return dirname(__DIR__) . '/data/cacert.pem';
    }

    /**
     * Several requests at once (curl_multi — a page that needs 3 Webservice calls waits for the slowest one, not for
     * all three in a row), at most MAX_PARALLEL at a time (not to flood a shop on shared hosting). Without curl: one
     * after another.
     * @param array $requests key => [method, url, headers, body|null]
     * @return array key => [http status, body] (same as request())
     */
    public static function requestMany(array $requests, int $timeout = 10): array
    {
        if (count($requests) > self::MAX_PARALLEL) {
            $out = [];
            foreach (array_chunk($requests, self::MAX_PARALLEL, true) as $part) {
                $out += self::requestMany($part, $timeout);
            }
            return $out;
        }
        $out = [];
        if (count($requests) < 2 || !function_exists('curl_multi_init')) {
            foreach ($requests as $k => $r) {
                $out[$k] = self::request($r[0], $r[1], $r[2] ?? [], $r[3] ?? null, $timeout);
            }
            return $out;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($requests as $k => $r) {
            $handles[$k] = self::handle($r[0], $r[1], $r[2] ?? [], $r[3] ?? null, $timeout, null);
            curl_multi_add_handle($mh, $handles[$k]);
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running && curl_multi_select($mh, 1.0) === -1) {
                usleep(10000);
            }
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $k => $ch) {
            $res = curl_multi_getcontent($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);   // (no curl_close: deprecated in PHP 8.5, no effect since 8.0)
            $out[$k] = $code === 0 ? [0, $err !== '' ? $err : 'connection failed'] : [$code, (string)$res];
            if ($code === 0 && stripos($out[$k][1], 'certificate') !== false && is_file(self::bundle())) {
                $r = $requests[$k];
                $out[$k] = self::send($r[0], $r[1], $r[2] ?? [], $r[3] ?? null, $timeout, self::bundle());
            }
        }
        curl_multi_close($mh);
        return $out;
    }

    /** @return resource|\CurlHandle */
    private static function handle(string $method, string $url, array $headers, ?string $body, int $timeout, ?string $caFile)
    {
        $ch = curl_init($url);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => self::UA, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => $method];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = $body;
        }
        if ($caFile !== null) {
            $opt[CURLOPT_CAINFO] = $caFile;
        }
        curl_setopt_array($ch, $opt);
        return $ch;
    }

    private static function send(string $method, string $url, array $headers, ?string $body, int $timeout, ?string $caFile): array
    {
        if (function_exists('curl_init')) {
            $ch = self::handle($method, $url, $headers, $body, $timeout, $caFile);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);   // frees the handle (curl_close is deprecated in PHP 8.5)
            return $res === false ? [0, $err] : [$code, (string)$res];
        }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        if ($caFile !== null) {
            $ssl['cafile'] = $caFile;
        }
        $http = ['method' => $method, 'timeout' => $timeout, 'ignore_errors' => true,
                 'header' => implode("\r\n", array_merge(['User-Agent: ' . self::UA], $headers)) . "\r\n"];
        if ($body !== null) {
            $http['content'] = $body;
        }
        $res = @file_get_contents($url, false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
        if ($res === false) {
            $e = error_get_last();
            return [0, (string)($e['message'] ?? 'connection failed')];
        }
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
        return [$code, (string)$res];
    }
}
