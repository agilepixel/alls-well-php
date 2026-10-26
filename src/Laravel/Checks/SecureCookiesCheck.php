<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Session cookies should be marked secure on an https production app.
 */
final class SecureCookiesCheck implements Check
{
    const KEY = 'laravel.secure_cookies';

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
        $secure = $this->app->make('config')->get('session.secure') === true;
        $label = 'Session cookies are secure';

        if ($secure) {
            return CheckResult::pass($label, 'low', true);
        }

        $https = stripos((string) $this->app->make('config')->get('app.url'), 'https://') === 0;
        if ($https && Environment::isProduction($this->app->environment())) {
            return CheckResult::warn($label, 'low', false);
        }

        return CheckResult::info($label, 'low', false);
    }
}
