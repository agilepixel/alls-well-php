<?php

namespace AgilePixel\AllsWell\Laravel;

use AgilePixel\AllsWell\Support\Credentials;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Adds the hourly report to the application's scheduler.
 *
 * It only reads settings: the schedule is resolved by every schedule:run, so
 * nothing here may reach the database or the network.
 */
final class Scheduling
{
    /**
     * @return string|null Why the report is not scheduled, or null when it is.
     */
    public static function shouldRegister(Container $app)
    {
        $settings = $app->make('config');
        $credentials = Credentials::fromValues(self::text($settings->get('alls_well.token')), self::text($settings->get('alls_well.key')));

        if ($credentials->siteToken() === null && $credentials->enrolmentKey() === null) {
            return 'no token or key';
        }
        if (! LaravelConfig::enabled($app)) {
            return 'turned off (ALLS_WELL_ENABLED)';
        }
        $skipped = LaravelConfig::skippedEnvironment($app);
        if ($skipped !== null) {
            return 'skipped in the '.$skipped.' environment';
        }
        if ($settings->get('alls_well.schedule', true) === false) {
            return 'turned off (alls_well.schedule)';
        }

        return null;
    }

    /**
     * @param  string  $siteUrl
     * @return int
     */
    public static function minute($siteUrl)
    {
        return (int) sprintf('%u', crc32($siteUrl)) % 60;
    }

    /**
     * @return void
     */
    public static function register(Schedule $schedule, Container $app)
    {
        try {
            $event = $schedule->command('alls-well:report')
                ->hourlyAt(self::minute((string) LaravelConfig::siteUrl($app)))
                ->withoutOverlapping(30)
                ->runInBackground();

            if ($app->make('cache')->getStore() instanceof LockProvider) {
                $event->onOneServer();
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private static function text($value)
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
