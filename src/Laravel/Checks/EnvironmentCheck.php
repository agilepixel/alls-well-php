<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Reports the environment name, as a label.
 */
final class EnvironmentCheck implements Check
{
    const KEY = 'laravel.environment';

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
        return CheckResult::info('Environment', 'info', Environment::shortLabel($this->app->environment()));
    }
}
