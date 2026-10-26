<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Debug mode must be off in production.
 */
final class DebugCheck implements Check
{
    const KEY = 'laravel.debug';

    /**
     * @var Application
     */
    private $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @return CheckResult
     */
    public function run()
    {
        $debug = (bool) $this->app->make('config')->get('app.debug');
        $label = 'Debug mode is off';

        if (! $debug) {
            return CheckResult::pass($label, 'high', false);
        }

        if (Environment::isProduction($this->app->environment())) {
            return CheckResult::fail($label, 'high', true);
        }

        return CheckResult::info($label, 'high', true);
    }
}
