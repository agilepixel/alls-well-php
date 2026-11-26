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
     * The host's own checks come first, so a long list of the owner's never
     * pushes one out, and an owner's check never replaces one.
     *
     * @param  array<int|string, mixed>  $checks  Check|callable(): CheckResult, keyed by check key.
     * @param  array<int|string, mixed>  $host  The host's own checks, which may use the addon. prefix.
     * @return array<int, array<string, mixed>>
     */
    public function run(array $checks, array $host = [])
    {
        $results = [];
        $skipped = 0;

        foreach ([[$host, true], [array_diff_key($checks, $host), false]] as [$registry, $isHosts]) {
            foreach ($registry as $key => $check) {
                $key = (string) $key;

                if (! self::validKey($key, $isHosts)) {
                    $skipped++;

                    continue;
                }
                if (count($results) < self::MAX_RESULTS) {
                    $results[] = $this->runOne($key, $check);
                }
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
     * @param  bool  $isHosts  Whether the check is one of the host's own.
     * @return bool
     */
    private static function validKey($key, $isHosts)
    {
        return strlen($key) <= 191
            && strpos($key, 'server.') !== 0
            && ($isHosts || strpos($key, 'addon.') !== 0)
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
