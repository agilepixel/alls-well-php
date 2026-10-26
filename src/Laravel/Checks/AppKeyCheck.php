<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * The application key must be set.
 */
final class AppKeyCheck implements Check
{
    const KEY = 'laravel.app_key';

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
        $set = (string) $this->app->make('config')->get('app.key') !== '';
        $label = 'APP_KEY is set';

        return $set ? CheckResult::pass($label, 'critical', true) : CheckResult::fail($label, 'critical', false);
    }
}
