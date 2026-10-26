<?php

namespace AgilePixel\AllsWell\Laravel;

use AgilePixel\AllsWell\Http\CurlTransport;
use AgilePixel\AllsWell\Http\Transport;
use AgilePixel\AllsWell\Laravel\Checks\ScheduleFailures;
use AgilePixel\AllsWell\Laravel\Commands\ConnectCommand;
use AgilePixel\AllsWell\Laravel\Commands\DisconnectCommand;
use AgilePixel\AllsWell\Laravel\Commands\InstallCommand;
use AgilePixel\AllsWell\Laravel\Commands\ReportCommand;
use AgilePixel\AllsWell\Laravel\Commands\StatusCommand;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Connects a Laravel application to All's Well.
 *
 * Composer discovers it through extra.laravel.providers. It does nothing on a
 * web request beyond merging its config and registering its migration path.
 */
class AllsWellServiceProvider extends ServiceProvider
{
    /**
     * The package's migrations, loaded in place and never published.
     */
    const MIGRATIONS = __DIR__.'/../../database/migrations';

    /**
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/alls_well.php', 'alls_well');

        $this->app->bindIf(Transport::class, function () {
            return new CurlTransport;
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (Scheduling::shouldRegister($this->app) === null) {
                Scheduling::register($schedule, $this->app);
            }
        });
    }

    /**
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(self::MIGRATIONS);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/alls_well.php' => config_path('alls_well.php'),
            ], 'alls-well-config');

            $this->commands([
                ReportCommand::class,
                ConnectCommand::class,
                DisconnectCommand::class,
                StatusCommand::class,
                InstallCommand::class,
            ]);

            if (class_exists(ScheduledTaskFailed::class)) {
                $this->app->make('events')->listen(ScheduledTaskFailed::class, function () {
                    try {
                        (new ScheduleFailures($this->app->make('cache')->store()))->record(time());
                    } catch (\Throwable $e) {
                        return;
                    }
                });
            }
        }
    }
}
