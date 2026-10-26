<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Production mail should be sent, not logged.
 */
final class MailCheck implements Check
{
    const KEY = 'laravel.mail';

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
        $config = $this->app->make('config');
        $mailer = $config->get('mail.default');
        $transport = is_string($mailer) && $mailer !== '' ? $config->get('mail.mailers.'.$mailer.'.transport') : null;
        $label = 'Mail is sent, not logged';

        if (! is_string($transport) || $transport === '') {
            return CheckResult::info($label, 'medium');
        }

        $value = Environment::shortLabel($transport);
        if (! in_array(strtolower($transport), ['log', 'array'], true)) {
            return CheckResult::pass($label, 'medium', $value);
        }

        if (Environment::isProduction($this->app->environment())) {
            return CheckResult::warn($label, 'medium', $value);
        }

        return CheckResult::info($label, 'medium', $value);
    }
}
