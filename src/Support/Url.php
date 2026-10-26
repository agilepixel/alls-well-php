<?php

namespace AgilePixel\AllsWell\Support;

/**
 * URL identity, matching the server's App\Support\SiteUrl: whether two
 * addresses name one site, and whether an address is reachable from the internet.
 */
final class Url
{
    const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Lowercase "scheme://host[:port]" with default ports dropped, or null
     * unless the URL is http(s) with a host.
     *
     * @param  string  $url
     * @return string|null
     */
    public static function normaliseOrigin($url)
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (! isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }

        return $scheme.'://'.strtolower($parts['host']).self::portSuffix($scheme, $parts);
    }

    /**
     * @param  string|null  $origin
     * @param  string  $url
     * @return bool
     */
    public static function sameOrigin($origin, $url)
    {
        if ($origin === null) {
            return false;
        }

        $expected = self::normaliseOrigin($origin);

        return $expected !== null && $expected === self::normaliseOrigin($url);
    }

    /**
     * Host (no "www."), non-default port and path, or null without a host.
     *
     * @param  string  $url
     * @return string|null
     */
    public static function siteKey($url)
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = self::asciiHost($parts['host']);
        if (substr($host, 0, 4) === 'www.') {
            $host = substr($host, 4);
        }

        $path = rtrim(isset($parts['path']) ? $parts['path'] : '', '/');

        return $host.self::portSuffix($scheme, $parts).$path;
    }

    /**
     * @param  string  $a
     * @param  string  $b
     * @return bool
     */
    public static function sameSite($a, $b)
    {
        $keyA = self::siteKey($a);
        $keyB = self::siteKey($b);

        return $keyA !== null && $keyB !== null && $keyA === $keyB;
    }

    /**
     * Whether the URL has a host that the internet can reach.
     *
     * @param  string  $url
     * @return bool
     */
    public static function isPublic($url)
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = self::asciiHost(trim($host, '[]'));

        if ($host === 'localhost' || (strpos($host, '.') === false && strpos($host, ':') === false)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $host = self::unwrapMappedIpv4($host);

            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        foreach (['.localhost', '.test', '.local', '.internal', '.invalid', '.example'] as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string  $scheme
     * @param  array<string, mixed>  $parts
     * @return string
     */
    private static function portSuffix($scheme, array $parts)
    {
        if (! isset($parts['port'])) {
            return '';
        }

        $default = isset(self::DEFAULT_PORTS[$scheme]) ? self::DEFAULT_PORTS[$scheme] : null;

        return (int) $parts['port'] === $default ? '' : ':'.(int) $parts['port'];
    }

    /**
     * @param  string  $ip
     * @return string
     */
    private static function unwrapMappedIpv4($ip)
    {
        $packed = inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16 || substr($packed, 0, 12) !== str_repeat("\0", 10)."\xff\xff") {
            return $ip;
        }

        $mapped = inet_ntop(substr($packed, 12));

        return $mapped ? $mapped : $ip;
    }

    /**
     * @param  string  $host
     * @return string
     */
    private static function asciiHost($host)
    {
        $host = rtrim(strtolower($host), '.');

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $lower = function_exists('mb_strtolower') ? mb_strtolower($host, 'UTF-8') : $host;
            $ascii = idn_to_ascii($lower, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return $host;
    }
}
