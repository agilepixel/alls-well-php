<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Failed jobs nobody has retried or cleared.
 */
final class FailedJobsCheck implements Check
{
    const KEY = 'laravel.failed_jobs';

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
        $label = 'Failed jobs';

        try {
            $count = $this->count();
        } catch (\Throwable $e) {
            return CheckResult::info($label, 'medium');
        }

        if ($count === null) {
            return CheckResult::info($label, 'medium');
        }

        return $count > 0
            ? CheckResult::warn($label, 'medium', $count)
            : CheckResult::pass($label, 'medium', 0);
    }

    /**
     * @return int|null
     */
    private function count()
    {
        $failer = $this->app->make('queue.failer');
        if (method_exists($failer, 'count')) {
            return (int) $failer->count();
        }

        $config = $this->app->make('config');
        $driver = $config->get('queue.failed.driver');
        $table = $config->get('queue.failed.table');
        if (! in_array($driver, ['database', 'database-uuids'], true) || ! is_string($table) || $table === '') {
            return null;
        }

        $connection = $config->get('queue.failed.database');
        $database = $this->app->make('db')->connection(is_string($connection) && $connection !== '' ? $connection : null);

        return (int) $database->table($table)->count();
    }
}
