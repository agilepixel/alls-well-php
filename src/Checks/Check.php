<?php

namespace AgilePixel\AllsWell\Checks;

/**
 * A check a report carries.
 */
interface Check
{
    /**
     * @return CheckResult
     */
    public function run();
}
