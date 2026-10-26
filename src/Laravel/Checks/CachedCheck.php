<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Configuration, routes and events should be cached in production.
 */
final class CachedCheck implements Check
{
    const CONFIG = 'config';

    const ROUTES = 'routes';

    const EVENTS = 'events';

    const KEYS = [
        self::CONFIG => 'laravel.config_cached',
        self::ROUTES => 'laravel.routes_cached',
        self::EVENTS => 'laravel.events_cached',
    ];

    const LABELS = [
        self::CONFIG => 'Configuration is cached',
        self::ROUTES => 'Routes are cached',
        self::EVENTS => 'Events are cached',
    ];

    /**
     * @var Application
     */
    private $app;

    /**
     * @var string
     */
    private $kind;

    /**
     * @param  string  $kind  One of the CONFIG, ROUTES and EVENTS constants.
     */
    public function __construct(Application $app, $kind)
    {
        if (! isset(self::KEYS[$kind])) {
            throw new \InvalidArgumentException('Unknown cache kind.');
        }

        $this->app = $app;
        $this->kind = $kind;
    }

    /**
     * @return string
     */
    public function key()
    {
        return self::KEYS[$this->kind];
    }

    /**
     * @return CheckResult
     */
    public function run()
    {
        $label = self::LABELS[$this->kind];

        if ($this->isCached()) {
            return CheckResult::pass($label, 'low', true);
        }

        if (Environment::isProduction($this->app->environment())) {
            return CheckResult::warn($label, 'low', false);
        }

        return CheckResult::info($label, 'low', false);
    }

    /**
     * @return bool
     */
    private function isCached()
    {
        /** @var \Illuminate\Foundation\Application $app */
        $app = $this->app;

        switch ($this->kind) {
            case self::CONFIG:
                return (bool) $app->configurationIsCached();
            case self::ROUTES:
                return (bool) $app->routesAreCached();
            default:
                return (bool) $app->eventsAreCached();
        }
    }
}
