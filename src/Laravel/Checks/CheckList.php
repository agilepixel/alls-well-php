<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Laravel\AllsWell;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * Every check a Laravel report carries. Building the list runs none of the checks, but it does read spatie/laravel-health's stored results when that is installed.
 */
final class CheckList
{
    /**
     * Built-ins, then spatie/laravel-health's results, then the config file's
     * classes, then the ones registered in code: each layer replaces the one
     * before, and a null in config removes a key.
     *
     * @return array<string, mixed>
     */
    public static function make(Container $app)
    {
        $checks = self::builtIn($app);

        foreach (SpatieHealth::checks($app) as $key => $check) {
            $checks[$key] = $check;
        }

        $configured = $app->make('config')->get('alls_well.checks');
        foreach (is_array($configured) ? $configured : [] as $key => $check) {
            if ($check === null) {
                unset($checks[$key]);
            } elseif (is_string($check) && $check !== '') {
                $checks[$key] = self::resolving($app, $check);
            }
        }

        foreach (AllsWell::checks() as $key => $check) {
            $checks[$key] = is_string($check) ? self::resolving($app, $check) : $check;
        }

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private static function builtIn(Container $app)
    {
        if (! $app instanceof Application) {
            return [];
        }

        $checks = [
            DebugCheck::KEY => new DebugCheck($app),
            EnvironmentCheck::KEY => new EnvironmentCheck($app),
            MailCheck::KEY => new MailCheck($app),
            HttpsCheck::KEY => new HttpsCheck($app),
            SecureCookiesCheck::KEY => new SecureCookiesCheck($app),
            AppKeyCheck::KEY => new AppKeyCheck($app),
            FailedJobsCheck::KEY => new FailedJobsCheck($app),
            QueueSizeCheck::KEY => new QueueSizeCheck($app),
            MigrationsCheck::KEY => new MigrationsCheck($app),
            ScheduleFailuresCheck::KEY => function () use ($app) {
                return (new ScheduleFailuresCheck(new ScheduleFailures($app->make('cache')->store())))->run();
            },
        ];

        foreach ([CachedCheck::CONFIG, CachedCheck::ROUTES, CachedCheck::EVENTS] as $kind) {
            $check = new CachedCheck($app, $kind);
            $checks[$check->key()] = $check;
        }

        if (class_exists('Laravel\Horizon\Horizon')) {
            $checks[HorizonCheck::KEY] = new HorizonCheck($app);
        }

        return $checks;
    }

    /**
     * @param  string  $class
     * @return \Closure
     */
    private static function resolving(Container $app, $class)
    {
        return function () use ($app, $class) {
            $check = $app->make($class);

            return $check instanceof Check ? $check->run() : $check();
        };
    }
}
