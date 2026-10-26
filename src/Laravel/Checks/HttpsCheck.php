<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * APP_URL should use https.
 */
final class HttpsCheck implements Check
{
    const KEY = 'laravel.https';

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
        $https = stripos((string) $this->app->make('config')->get('app.url'), 'https://') === 0;
        $label = 'APP_URL uses https';

        if ($https) {
            return CheckResult::pass($label, 'medium', true);
        }

        if (Environment::isProduction($this->app->environment())) {
            return CheckResult::warn($label, 'medium', false);
        }

        return CheckResult::info($label, 'medium', false);
    }
}
