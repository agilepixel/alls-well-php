<?php

namespace AgilePixel\AllsWell\Laravel;

use AgilePixel\AllsWell\Client;
use AgilePixel\AllsWell\Config;
use AgilePixel\AllsWell\Http\Transport;
use AgilePixel\AllsWell\Report\ComposerPackages;
use AgilePixel\AllsWell\Reporter;
use Illuminate\Contracts\Container\Container;

/**
 * Builds the core's reporter for a Laravel application.
 */
final class ReporterFactory
{
    /**
     * @param  ComposerPackages|null  $packages  The installed packages to report; read from Composer when null.
     * @return Reporter
     */
    public static function make(Container $app, ?ComposerPackages $packages = null)
    {
        return self::with($app, LaravelConfig::make($app), $packages);
    }

    /**
     * For a caller that already built the config and wants to read it as well.
     *
     * @return Reporter
     */
    public static function with(Container $app, Config $config, ?ComposerPackages $packages = null)
    {
        return new Reporter($config, new Client($app->make(Transport::class)), $packages);
    }
}
