<?php

namespace AgilePixel\AllsWell\Laravel\Commands;

use AgilePixel\AllsWell\Laravel\LaravelConfig;
use AgilePixel\AllsWell\Laravel\ReporterFactory;
use AgilePixel\AllsWell\Laravel\Scheduling;
use AgilePixel\AllsWell\Support\StatusLines;

/**
 * Shows what the package knows, and sends nothing.
 */
class StatusCommand extends AllsWellCommand
{
    const CACHED_CONFIG = "The configuration is cached, so .env changes aren't read: run php artisan config:clear (or config:cache again).";

    /**
     * @var string
     */
    protected $signature = 'alls-well:status';

    /**
     * @var string
     */
    protected $description = "Show how this app reports to All's Well";

    /**
     * @return int
     */
    protected function perform()
    {
        $config = LaravelConfig::make($this->laravel);
        $status = ReporterFactory::with($this->laravel, $config)->status();
        $skipped = $this->skippedEnvironment();
        $cached = $this->configIsCached();

        $rows = StatusLines::lines($status);
        $rows[] = ['environment', ($this->laravel->environment()).($skipped !== null ? ' (skipped)' : '')];
        $rows[] = ['state', $config->state()->name()];
        $rows[] = ['schedule', $this->scheduleLine()];
        $rows[] = ['config cached', $cached ? 'yes' : 'no'];
        $this->table(['setting', 'value'], $rows);

        $errors = $status['errors'];
        if ($cached && $status['mode'] === null) {
            array_unshift($errors, self::CACHED_CONFIG);
        }
        if ($errors === []) {
            $this->line('errors: -');
        } else {
            $this->line('errors:');
            foreach ($errors as $error) {
                $this->line('  - '.$error);
            }
        }

        return 0;
    }

    /**
     * @return string
     */
    protected function scheduleLine()
    {
        $reason = Scheduling::shouldRegister($this->laravel);

        return $reason === null
            ? 'hourly at :'.sprintf('%02d', Scheduling::minute((string) LaravelConfig::siteUrl($this->laravel)))
            : 'not scheduled ('.$reason.')';
    }
}
