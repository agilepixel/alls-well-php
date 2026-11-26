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

    const PLATFORMS = ['php', 'laravel', 'craft'];

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

    /** @var bool */
    private $keepsConnection;

    /** @var string|null */
    private $boundEnvironment;

    /** @var Wording */
    private $wording;

    /** @var array<int|string, mixed> */
    private $checks;

    /** @var array<int|string, mixed> */
    private $hostChecks;

    /** @var callable|null */
    private $components;

    /** @var callable|null */
    private $administrators;

    /** @var string */
    private $addonVersion;

    /** @var string|null */
    private $platformVersion;

    /** @var string|null */
    private $phpVersion;

    /** @var bool|null */
    private $multisite;

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
        $config->platform = isset($settings['platform']) && in_array($settings['platform'], self::PLATFORMS, true) ? $settings['platform'] : 'php';
        $config->addonVersion = self::version($settings, 'addon_version', 32) ?: AllsWell::VERSION;
        $config->platformVersion = self::version($settings, 'platform_version', 64);
        $config->phpVersion = self::version($settings, 'php_version', 64);
        $config->multisite = isset($settings['multisite']) && is_bool($settings['multisite']) ? $settings['multisite'] : null;
        $config->timeout = self::timeoutFrom($settings);
        $config->state = isset($settings['state']) && $settings['state'] instanceof StateStore
            ? $settings['state']
            : new MemoryStateStore;
        $config->keepsConnection = isset($settings['stored_connection']) && $settings['stored_connection'] === true;
        $config->boundEnvironment = self::text($settings, 'bind_environment');
        $config->wording = isset($settings['wording']) && $settings['wording'] instanceof Wording
            ? $settings['wording']
            : Wording::cli();
        $config->checks = isset($settings['checks']) && is_array($settings['checks']) ? $settings['checks'] : [];
        $config->hostChecks = isset($settings['host_checks']) && is_array($settings['host_checks']) ? $settings['host_checks'] : [];
        $config->components = self::callable($settings, 'components');
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
     * @return string One of PLATFORMS.
     */
    public function platform()
    {
        return $this->platform;
    }

    /**
     * @return string The reporting add-on's version: the host's own, or the package's.
     */
    public function addonVersion()
    {
        return $this->addonVersion;
    }

    /**
     * @return string|null The platform's version as the host knows it; null to read it from Composer.
     */
    public function platformVersion()
    {
        return $this->platformVersion;
    }

    /**
     * @return string|null The PHP version to report instead of the running one, for a host whose command line runs another.
     */
    public function phpVersion()
    {
        return $this->phpVersion;
    }

    /**
     * @return bool|null Null when the platform has no such thing.
     */
    public function multisite()
    {
        return $this->multisite;
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
     * @return bool Whether a connection made by hand, and kept in the state, may be used.
     */
    public function keepsConnection()
    {
        return $this->keepsConnection;
    }

    /**
     * @return string|null The environment a kept connection belongs to, so a copy of its state elsewhere isn't this site.
     */
    public function boundEnvironment()
    {
        return $this->boundEnvironment;
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
     * The host's own checks, which may use the addon. prefix.
     *
     * @return array<int|string, mixed>
     */
    public function hostChecks()
    {
        return $this->hostChecks;
    }

    /**
     * @return callable|null Returns the host's components, in the protocol's shape.
     */
    public function components()
    {
        return $this->components;
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
     * What the site reports with: the site token that is set, else a
     * connection the set key enrols, else one kept in the state.
     *
     * @return 'token'|'key'|'stored'|null Null when there is nothing to report with.
     */
    public function mode()
    {
        if ($this->credentials->siteToken() !== null) {
            return 'token';
        }
        if ($this->credentials->enrolmentKey() !== null) {
            return 'key';
        }

        return $this->keepsConnection ? 'stored' : null;
    }

    /**
     * @return bool Whether an enrolment key is set and no site token.
     */
    public function isKeyMode()
    {
        return $this->mode() === 'key';
    }

    /**
     * What the owner must fix before any report can be sent.
     *
     * @param  bool  $enrolling  Whether the set key may have to enrol, which needs somewhere to keep the token.
     * @return string[]
     */
    public function errors($enrolling = true)
    {
        $errors = [];
        $setting = $this->wording->addressSetting();

        if ($this->siteUrl === null) {
            $errors[] = 'Set '.$setting.' to the address this site is served from.';
        } elseif (preg_match('#^https?://#i', $this->siteUrl) !== 1) {
            $errors[] = ucfirst($setting).' must start with https:// (or http://).';
        } elseif (Url::normaliseOrigin($this->siteUrl) === null || ! Url::isPublic($this->siteUrl)) {
            $errors[] = "This site's address (".$this->siteUrl.") isn't public (localhost or a private network). "
                .'Set '.$setting.' to the address the site is served from.';
        }

        if ($this->mode() === null) {
            $errors[] = "Set ALLS_WELL_TOKEN to this site's token, or ALLS_WELL_KEY to your team's enrolment key.";
        }

        foreach ($this->credentials->errors() as $error) {
            $errors[] = $error;
        }

        if ($this->origin !== null && Url::normaliseOrigin($this->origin) === null) {
            $errors[] = 'ALLS_WELL_URL is not a valid http(s) address.';
        }

        if ($enrolling && $this->isKeyMode() && ! $this->state->isPersistent()) {
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
     * @param  int  $limit
     * @return string|null Null when it is longer than the protocol allows.
     */
    private static function version(array $settings, $key, $limit)
    {
        $value = self::text($settings, $key);

        return $value !== null && strlen($value) <= $limit ? $value : null;
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
