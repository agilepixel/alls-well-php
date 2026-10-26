<?php

namespace AgilePixel\AllsWell\Laravel;

use AgilePixel\AllsWell\Config;
use AgilePixel\AllsWell\Laravel\Checks\CheckList;
use AgilePixel\AllsWell\Laravel\State\StateStores;
use AgilePixel\AllsWell\Support\Credentials;
use AgilePixel\AllsWell\Support\Wording;
use Illuminate\Contracts\Container\Container;

/**
 * Turns a Laravel application's settings into the core's Config.
 */
final class LaravelConfig
{
    /**
     * @return Config
     */
    public static function make(Container $app)
    {
        $settings = $app->make('config');
        $token = self::text($settings->get('alls_well.token'));
        $key = self::text($settings->get('alls_well.key'));
        $credentials = Credentials::fromValues($token, $key);
        $keyMode = $credentials->enrolmentKey() !== null && $credentials->siteToken() === null;

        return Config::fromArray([
            'token' => $token,
            'key' => $key,
            'origin' => self::text($settings->get('alls_well.url')),
            'site_url' => self::siteUrl($app),
            'site_name' => self::siteName($app),
            'environment' => self::text($settings->get('alls_well.environment')) ?: self::environment($app),
            'enabled' => self::enabled($app),
            'platform' => 'laravel',
            'timeout' => $settings->get('alls_well.timeout'),
            'state' => StateStores::make($app, $keyMode),
            'checks' => CheckList::make($app),
            'administrators' => self::administrators($app),
            'pdo' => function () use ($app) {
                return $app->make('db')->connection()->getPdo();
            },
            'logger' => function ($message) use ($app) {
                try {
                    $app->make('log')->warning($message);
                } catch (\Throwable $e) {
                    return;
                }
            },
            'wording' => Wording::laravel(),
        ]);
    }

    /**
     * Whether reporting is switched on. env() only turns true, false and null
     * into values, so "0", "off" and "no" arrive as text: they mean off, as in the CLI.
     *
     * @return bool
     */
    public static function enabled(Container $app)
    {
        $value = $app->make('config')->get('alls_well.enabled', true);

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
        }

        return $value !== false && $value !== 0;
    }

    /**
     * The address the site is reported under, read from settings alone.
     *
     * @return string|null
     */
    public static function siteUrl(Container $app)
    {
        $settings = $app->make('config');

        return self::text($settings->get('alls_well.site_url')) ?: self::text($settings->get('app.url'));
    }

    /**
     * The setting, else the app's name unless that is empty or Laravel's default.
     *
     * @return string|null
     */
    private static function siteName(Container $app)
    {
        $settings = $app->make('config');
        $configured = self::text($settings->get('alls_well.site_name'));
        if ($configured !== null) {
            return $configured;
        }

        $name = self::text($settings->get('app.name'));

        return $name !== null && strtolower($name) !== 'laravel' ? $name : null;
    }

    /**
     * The environment's name when reporting is switched off there.
     *
     * @return string|null
     */
    public static function skippedEnvironment(Container $app)
    {
        $skipped = $app->make('config')->get('alls_well.skip_environments');
        $environment = self::environment($app);

        return $environment !== null && is_array($skipped) && in_array($environment, $skipped, true) ? $environment : null;
    }

    /**
     * @return string|null
     */
    private static function environment(Container $app)
    {
        $environment = $app->bound('env') ? $app->make('env') : null;

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    /**
     * @param  mixed  $value
     * @return string|null
     */
    private static function text($value)
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return callable|null
     */
    private static function administrators(Container $app)
    {
        $registered = AllsWell::administratorsResolver();
        if ($registered !== null) {
            return $registered;
        }

        $class = $app->make('config')->get('alls_well.administrators');
        if (! is_string($class) || $class === '' || ! class_exists($class) || ! method_exists($class, '__invoke')) {
            return null;
        }

        return function () use ($app, $class) {
            $resolver = $app->make($class);

            return $resolver();
        };
    }
}
