<?php

namespace AgilePixel\AllsWell\Connect;

use AgilePixel\AllsWell\Support\Url;

/**
 * A request to connect this site with a button, shaped like OAuth's
 * authorization code with PKCE (RFC 7636). The browser goes to url(), where
 * someone approves the site, and comes back to the return address with a
 * one-time code and the state.
 *
 * The state ties the return to the person who started it, and the verifier,
 * which never leaves this site until the code is traded, stops anyone else
 * using the code. Keep the request in that person's session meanwhile, with
 * toArray(), and drop it once the return has used it.
 */
final class AuthorizationRequest
{
    const LIFETIME = 3600;

    const NAME_LIMIT = 255;

    const URL_LIMIT = 2048;

    /** @var string */
    private $origin;

    /** @var string */
    private $platform;

    /** @var string */
    private $siteUrl;

    /** @var string|null */
    private $siteName;

    /** @var string */
    private $returnUrl;

    /** @var string */
    private $state;

    /** @var string */
    private $verifier;

    /** @var int */
    private $createdAt;

    private function __construct() {}

    /**
     * @param  string  $origin  The All's Well origin to connect with.
     * @param  string  $platform  One of Config::PLATFORMS.
     * @param  string  $siteUrl
     * @param  string|null  $siteName
     * @param  string  $returnUrl  Where the browser comes back to: one canReturnTo() allows.
     * @param  int  $now
     * @return self
     *
     * @throws \Exception When PHP has no source of randomness.
     */
    public static function create($origin, $platform, $siteUrl, $siteName, $returnUrl, $now)
    {
        $request = new self;
        $request->origin = rtrim($origin, '/');
        $request->platform = $platform;
        $request->siteUrl = $siteUrl;
        $request->siteName = self::name($siteName);
        $request->returnUrl = $returnUrl;
        $request->state = self::base64Url(random_bytes(32));
        $request->verifier = self::base64Url(random_bytes(32));
        $request->createdAt = $now;

        return $request;
    }

    /**
     * @param  array<mixed>  $data  What toArray() gave.
     * @return self|null Null when it isn't a whole request.
     */
    public static function fromArray(array $data)
    {
        foreach (['origin', 'platform', 'site_url', 'return_url', 'state', 'verifier'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $data['state']) !== 1
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $data['verifier']) !== 1
            || ! isset($data['created_at']) || ! is_int($data['created_at'])) {
            return null;
        }

        $request = new self;
        $request->origin = $data['origin'];
        $request->platform = $data['platform'];
        $request->siteUrl = $data['site_url'];
        $request->siteName = self::name(isset($data['site_name']) ? $data['site_name'] : null);
        $request->returnUrl = $data['return_url'];
        $request->state = $data['state'];
        $request->verifier = $data['verifier'];
        $request->createdAt = $data['created_at'];

        return $request;
    }

    /**
     * Whether something is shaped like the one-time code the browser comes back with.
     *
     * @param  mixed  $code
     * @return bool
     */
    public static function isCode($code)
    {
        return is_string($code) && preg_match('/^amc_[A-Za-z0-9]{40}$/D', $code) === 1;
    }

    /**
     * Whether All's Well will send the browser back to an address: a plain one
     * on the site's own host, or on a parent or subdomain of it. A screen
     * served from anywhere else connects with a token or key instead.
     *
     * @param  string  $returnUrl
     * @param  string  $siteUrl
     * @return bool
     */
    public static function canReturnTo($returnUrl, $siteUrl)
    {
        $site = Url::hostKey($siteUrl);
        $host = Url::hostKey($returnUrl);
        if ($site === null || $host === null || Url::normaliseOrigin($returnUrl) === null
            || Url::hasCredentials($returnUrl) || strpos($returnUrl, '#') !== false || strlen($returnUrl) > self::URL_LIMIT) {
            return false;
        }

        return $site === $host || substr($host, -strlen('.'.$site)) === '.'.$site || substr($site, -strlen('.'.$host)) === '.'.$host;
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray()
    {
        return [
            'origin' => $this->origin,
            'platform' => $this->platform,
            'site_url' => $this->siteUrl,
            'site_name' => $this->siteName,
            'return_url' => $this->returnUrl,
            'state' => $this->state,
            'verifier' => $this->verifier,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Where to send the browser.
     *
     * @param  bool  $signup  Whether to open on creating an account rather than signing in.
     * @return string
     */
    public function url($signup = false)
    {
        $query = [
            'site_url' => $this->siteUrl,
            'site_name' => $this->siteName,
            'return_url' => $this->returnUrl,
            'state' => $this->state,
            'code_challenge' => $this->challenge(),
            'signup' => $signup ? '1' : null,
        ];

        return $this->origin.'/connect/'.rawurlencode($this->platform).'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Whether a return carries this request's state.
     *
     * @param  mixed  $state  As it came in the address.
     * @return bool
     */
    public function matches($state)
    {
        return is_string($state) && hash_equals($this->state, $state);
    }

    /**
     * @param  int  $now
     * @return bool
     */
    public function isExpired($now)
    {
        return abs($now - $this->createdAt) >= self::LIFETIME;
    }

    /**
     * The S256 code challenge: base64url of the verifier's SHA-256, unpadded.
     *
     * @return string
     */
    public function challenge()
    {
        return self::base64Url(hash('sha256', $this->verifier, true));
    }

    /**
     * @return string
     */
    public function verifier()
    {
        return $this->verifier;
    }

    /**
     * @return string
     */
    public function origin()
    {
        return $this->origin;
    }

    /**
     * @return string
     */
    public function platform()
    {
        return $this->platform;
    }

    /**
     * @return string
     */
    public function siteUrl()
    {
        return $this->siteUrl;
    }

    /**
     * @return string|null
     */
    public function siteName()
    {
        return $this->siteName;
    }

    /**
     * @param  mixed  $name
     * @return string|null
     */
    private static function name($name)
    {
        if (! is_string($name) || $name === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, self::NAME_LIMIT, 'UTF-8') : substr($name, 0, self::NAME_LIMIT);
    }

    /**
     * @param  string  $bytes
     * @return string
     */
    private static function base64Url($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
