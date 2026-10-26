<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\Check;
use AgilePixel\AllsWell\Checks\CheckResult;

/**
 * Scheduled tasks that failed in the last 24 hours.
 */
final class ScheduleFailuresCheck implements Check
{
    const KEY = 'laravel.schedule_failures';

    /**
     * @var ScheduleFailures
     */
    private $failures;

    public function __construct(ScheduleFailures $failures)
    {
        $this->failures = $failures;
    }

    /**
     * @return CheckResult
     */
    public function run()
    {
        $label = 'Scheduled tasks failed in the last 24 hours';
        $count = $this->failures->recent(time());

        if ($count > 0) {
            return CheckResult::warn($label, 'medium', $count);
        }

        return $this->failures->canKnow()
            ? CheckResult::pass($label, 'medium', 0)
            : CheckResult::info($label, 'medium');
    }
}
