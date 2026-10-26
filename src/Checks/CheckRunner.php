<?php

namespace AgilePixel\AllsWell\Checks;

use AgilePixel\AllsWell\Support\ClassName;

/**
 * Runs the registered checks without letting any of them break the report.
 */
final class CheckRunner
{
    const MAX_RESULTS = 100;

    /**
     * @param  array<string, mixed>  $checks  Check|callable(): CheckResult, keyed by check key.
     * @return array<int, array<string, mixed>>
     */
    public function run(array $checks)
    {
        $results = [];
        $skipped = 0;

        foreach ($checks as $key => $check) {
            $key = (string) $key;

            if (! self::validKey($key)) {
                $skipped++;

                continue;
            }
            if (count($results) < self::MAX_RESULTS) {
                $results[] = $this->runOne($key, $check);
            }
        }

        if ($skipped > 0) {
            $results = array_slice($results, 0, self::MAX_RESULTS - 1);
            $results[] = CheckResult::warn('Checks with invalid keys were skipped', 'low', $skipped)
                ->toArray('addon.invalid_checks');
        }

        return $results;
    }

    /**
     * @param  string  $key
     * @return bool
     */
    private static function validKey($key)
    {
        return strlen($key) <= 191
            && strpos($key, 'server.') !== 0
            && strpos($key, 'addon.') !== 0
            && preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $key) === 1;
    }

    /**
     * @param  string  $key
     * @param  mixed  $check
     * @return array<string, mixed>
     */
    private function runOne($key, $check)
    {
        try {
            if ($check instanceof Check) {
                $result = $check->run();
            } elseif (is_callable($check)) {
                $result = $check();
            } else {
                $result = $check;
            }

            if ($result instanceof CheckResult) {
                return $result->toArray($key);
            }
            $problem = is_object($result) ? ClassName::of($result) : gettype($result);
        } catch (\Throwable $e) {
            $problem = ClassName::of($e);
        }

        return CheckResult::fail($key, 'medium', null, 'This check could not run ('.$problem.').')
            ->toArray($key);
    }
}
