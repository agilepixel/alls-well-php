<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * A backlog on the default queue means workers are behind or stopped.
 */
final class QueueSizeCheck implements Check
{
    const KEY = 'laravel.queue_size';

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
        $label = 'Jobs waiting on the default queue';

        try {
            $config = $this->app->make('config');
            $connection = $config->get('queue.default');
            $driver = is_string($connection) ? $config->get('queue.connections.'.$connection.'.driver') : null;
            if ($driver === null || in_array($driver, ['sync', 'null'], true)) {
                return CheckResult::info($label, 'medium');
            }

            $size = (int) $this->app->make('queue')->connection()->size();
            $threshold = (int) $config->get('alls_well.queue_size_threshold', 1000);
        } catch (\Throwable $e) {
            return CheckResult::info($label, 'medium');
        }

        return $size > $threshold
            ? CheckResult::warn($label, 'medium', $size)
            : CheckResult::pass($label, 'medium', $size);
    }
}
