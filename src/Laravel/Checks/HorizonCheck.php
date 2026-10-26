<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Foundation\Application;

/**
 * Horizon's master supervisors are running and not paused.
 */
final class HorizonCheck implements Check
{
    const KEY = 'laravel.horizon';

    const REPOSITORY = 'Laravel\Horizon\Contracts\MasterSupervisorRepository';

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
        $label = 'Horizon is running';

        try {
            if (! $this->app->bound(self::REPOSITORY)) {
                return CheckResult::info($label, 'high');
            }

            $masters = $this->app->make(self::REPOSITORY)->all();
            $masters = is_array($masters) ? $masters : iterator_to_array($masters, false);
        } catch (\Throwable $e) {
            return CheckResult::info($label, 'high');
        }

        if (count($masters) === 0) {
            return CheckResult::fail($label, 'high', 'inactive');
        }

        foreach ($masters as $master) {
            if (self::status($master) === 'paused') {
                return CheckResult::warn($label, 'high', 'paused');
            }
        }

        return CheckResult::pass($label, 'high', 'running');
    }

    /**
     * @param  mixed  $master
     * @return string|null
     */
    private static function status($master)
    {
        if (is_object($master) && isset($master->status)) {
            return (string) $master->status;
        }

        return is_array($master) && isset($master['status']) ? (string) $master['status'] : null;
    }
}
