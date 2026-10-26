<?php

namespace AgilePixel\AllsWell;

use AgilePixel\AllsWell\State\MemoryStateStore;
use AgilePixel\AllsWell\State\StateStore;
use AgilePixel\AllsWell\Support\Credentials;
use AgilePixel\AllsWell\Support\Url;
use AgilePixel\AllsWell\Support\Wording;

/**
 * The package's settings, read once and never changed. A wrongly typed
 * setting falls back to its default; problems the owner must fix are listed
 * by errors() instead of thrown.
 */
final class Config
{
    const MIN_TIMEOUT = 1;

    const MAX_TIMEOUT = 60;

    const DEFAULT_TIMEOUT = 15;

    /** @var string|null */
    private $origin;

    /** @var Credentials */
    private $credentials;

    /** @var string|null */
    private $siteUrl;

    /** @var string|null */
    private $siteName;

    /** @var string|null */
    private $environment;

    /** @var bool */
    private $enabled;

    /** @var string */
    private $platform;

    /** @var int */
    private $timeout;

    /** @var StateStore */
    private $state;

    /** @var Wording */
    private $wording;

    /** @var array<int|string, mixed> */
    private $checks;

    /** @var callable|null */
    private $administrators;

    /** @var \PDO|callable|null */
    private $pdoSource;

    /** @var bool */
    private $pdoResolved = false;

    /** @var \PDO|null */
    private $pdo;

    /** @var callable|null */
    private $logger;

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $settings
     * @return Config
     */
    public static function fromArray(array $settings)
    {
        $config = new self;
        $config->origin = self::text($settings, 'origin');
        $config->credentials = Credentials::fromValues(self::text($settings, 'token'), self::text($settings, 'key'));
        $config->siteUrl = self::text($settings, 'site_url');
        $config->siteName = self::text($settings, 'site_name');
        $config->environment = self::text($settings, 'environment');
        $config->enabled = isset($settings['enabled']) && is_bool($settings['enabled']) ? $settings['enabled'] : true;
        $config->platform = isset($settings['platform']) && $settings['platform'] === 'laravel' ? 'laravel' : 'php';
        $config->timeout = self::timeoutFrom($settings);
        $config->state = isset($settings['state']) && $settings['state'] instanceof StateStore
            ? $settings['state']
            : new MemoryStateStore;
        $config->wording = isset($settings['wording']) && $settings['wording'] instanceof Wording
            ? $settings['wording']
            : Wording::cli();
        $config->checks = isset($settings['checks']) && is_array($settings['checks']) ? $settings['checks'] : [];
        $config->administrators = self::callable($settings, 'administrators');
        $config->logger = self::callable($settings, 'logger');

        if (isset($settings['pdo']) && ($settings['pdo'] instanceof \PDO || is_callable($settings['pdo']))) {
            $config->pdoSource = $settings['pdo'];
        }

        return $config;
    }

    /**
     * The normalised origin; the default when none is set or the one set is invalid.
     *
     * @return string
     */
    public function origin()
    {
        if ($this->origin === null) {
            return AllsWell::DEFAULT_ORIGIN;
        }

        $origin = Url::normaliseOrigin($this->origin);

        return $origin === null ? AllsWell::DEFAULT_ORIGIN : $origin;
    }

    /**
     * @return Credentials
     */
    public function credentials()
    {
        return $this->credentials;
    }

    /**
     * @return string|null
     */
    public function siteUrl()
    {
        return $this->siteUrl;
    }

    /**
     * @return string
     */
    public function siteName()
    {
        if ($this->siteName !== null) {
            return $this->siteName;
        }

        if ($this->siteUrl !== null) {
            $host = parse_url($this->siteUrl, PHP_URL_HOST);

            if (is_string($host)) {
                return $host;
            }
        }

        return '';
    }

    /**
     * @return string|null
     */
    public function environment()
    {
        return $this->environment;
    }

    /**
     * @return bool
     */
    public function enabled()
    {
        return $this->enabled;
    }

    /**
     * @return string 'php' or 'laravel'.
     */
    public function platform()
    {
        return $this->platform;
    }

    /**
     * @return int Seconds, 1 to 60.
     */
    public function timeout()
    {
        return $this->timeout;
    }

    /**
     * @return StateStore
     */
    public function state()
    {
        return $this->state;
    }

    /**
     * @return Wording
     */
    public function wording()
    {
        return $this->wording;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function checks()
    {
        return $this->checks;
    }

    /**
     * @return callable|null
     */
    public function administrators()
    {
        return $this->administrators;
    }

    /**
     * The database connection, resolved on first use. Null when there is none,
     * when the callable returned something else, or when it threw.
     *
     * @return \PDO|null
     */
    public function pdo()
    {
        if (! $this->pdoResolved) {
            $this->pdoResolved = true;

            try {
                $source = $this->pdoSource;
                $pdo = is_callable($source) ? call_user_func($source) : $source;
                $this->pdo = $pdo instanceof \PDO ? $pdo : null;
            } catch (\Throwable $e) {
                $this->pdo = null;
            }
        }

        return $this->pdo;
    }

    /**
     * @param  string  $message
     * @return void
     */
    public function log($message)
    {
        if ($this->logger === null) {
            return;
        }

        try {
            call_user_func($this->logger, $message);
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @return bool Whether an enrolment key is set and no site token.
     */
    public function isKeyMode()
    {
        return $this->credentials->enrolmentKey() !== null && $this->credentials->siteToken() === null;
    }

    /**
     * What the owner must fix before any report can be sent.
     *
     * @return string[]
     */
    public function errors()
    {
        $errors = [];

        if ($this->siteUrl === null) {
            $errors[] = 'Set ALLS_WELL_SITE_URL to the address this site is served from.';
        } elseif (preg_match('#^https?://#i', $this->siteUrl) !== 1) {
            $errors[] = 'ALLS_WELL_SITE_URL must start with https:// (or http://).';
        } elseif (Url::normaliseOrigin($this->siteUrl) === null || ! Url::isPublic($this->siteUrl)) {
            $errors[] = "This site's address (".$this->siteUrl.") isn't public (localhost or a private network). "
                .'Set ALLS_WELL_SITE_URL to the address the site is served from.';
        }

        if ($this->credentials->siteToken() === null && $this->credentials->enrolmentKey() === null) {
            $errors[] = "Set ALLS_WELL_TOKEN to this site's token, or ALLS_WELL_KEY to your team's enrolment key.";
        }

        foreach ($this->credentials->errors() as $error) {
            $errors[] = $error;
        }

        if ($this->origin !== null && Url::normaliseOrigin($this->origin) === null) {
            $errors[] = 'ALLS_WELL_URL is not a valid http(s) address.';
        }

        if ($this->isKeyMode() && ! $this->state->isPersistent()) {
            $errors[] = $this->wording->keyNeedsState();
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  string  $key
     * @return string|null Trimmed; null when absent, not a string or empty.
     */
    private static function text(array $settings, $key)
    {
        if (! isset($settings[$key]) || ! is_string($settings[$key])) {
            return null;
        }

        $value = trim($settings[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  string  $key
     * @return callable|null
     */
    private static function callable(array $settings, $key)
    {
        return isset($settings[$key]) && is_callable($settings[$key]) ? $settings[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return int
     */
    private static function timeoutFrom(array $settings)
    {
        $value = isset($settings['timeout']) ? $settings['timeout'] : null;

        if (is_string($value) && preg_match('/^\d{1,9}$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            $value = self::DEFAULT_TIMEOUT;
        }

        return max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $value));
    }
}
