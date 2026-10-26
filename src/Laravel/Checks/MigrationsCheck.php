<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Pending migration files mean the code and the schema have drifted.
 */
final class MigrationsCheck implements Check
{
    const KEY = 'laravel.migrations';

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
        $label = 'Migrations are up to date';

        try {
            $migrator = $this->app->make('migrator');
            if (! $migrator->repositoryExists()) {
                return CheckResult::warn($label, 'high');
            }

            $files = $migrator->getMigrationFiles(array_merge([$this->app->databasePath('migrations')], $migrator->paths()));
            $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (\Throwable $e) {
            return CheckResult::info($label, 'high');
        }

        return $pending > 0
            ? CheckResult::warn($label, 'high', $pending)
            : CheckResult::pass($label, 'high', 0);
    }
}
