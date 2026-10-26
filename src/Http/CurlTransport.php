<?php

namespace AgilePixel\AllsWell\Http;

use AgilePixel\AllsWell\AllsWell;

/**
 * Posts JSON over curl. A redirect is returned as it is, never followed, so
 * the bearer token cannot reach another host. Invalid UTF-8 (a Latin-1 site
 * name, say) is sent as U+FFFD rather than failing the whole request.
 */
final class CurlTransport implements Transport
{
    public function post(string $url, string $bearer, array $body, int $timeout): Response
    {
        $json = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return new Response(0, [], null, 'The request body could not be encoded');
        }

        $timeout = max(1, $timeout);
        $retryAfter = null;
        $handle = curl_init($url);
        if ($handle === false) {
            return new Response(0, [], null, 'curl could not start');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$bearer,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: '.AllsWell::userAgent(),
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = self::parseRetryAfter(substr($line, 12));
                }

                return strlen($line);
            },
        ]);

        $answer = curl_exec($handle);
        if ($answer === false || $answer === true) {
            $error = curl_error($handle);

            return new Response(0, [], null, $error !== '' ? $error : 'The request failed');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $decoded = json_decode($answer, true);

        return new Response($status, is_array($decoded) ? $decoded : [], $retryAfter, null);
    }

    /**
     * Seconds, or an HTTP-date turned into seconds from now (never below 0).
     */
    private static function parseRetryAfter(string $value): ?int
    {
        $value = trim($value);
        if ($value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        $time = $value === '' ? false : strtotime($value);

        return $time === false ? null : max(0, $time - time());
    }
}
