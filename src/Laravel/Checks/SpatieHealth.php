<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

use AgilePixel\AllsWell\Checks\CheckResult;
use Illuminate\Contracts\Container\Container;

/**
 * Carries spatie/laravel-health's stored results as checks.
 *
 * Reads name, label, summary and status only: notification messages and meta
 * can carry details.
 */
final class SpatieHealth
{
    const STORE = 'Spatie\Health\ResultStores\ResultStore';

    /**
     * @return array<string, callable>
     */
    public static function checks(Container $app)
    {
        try {
            if (! $app->bound(self::STORE)) {
                return [];
            }

            $latest = $app->make(self::STORE)->latestResults();
            $results = is_object($latest) && isset($latest->storedCheckResults) ? $latest->storedCheckResults : [];
            if ($results instanceof \Traversable) {
                $results = iterator_to_array($results, false);
            }

            $checks = [];
            foreach (is_array($results) ? $results : [] as $result) {
                $key = self::key($result);
                if ($key !== null) {
                    $checks[$key] = self::check($result);
                }
            }

            return $checks;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param  mixed  $result
     * @return string|null
     */
    private static function key($result)
    {
        if (! is_object($result) || ! isset($result->name)) {
            return null;
        }

        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower((string) $result->name)), '-');

        return $slug === '' ? null : 'health.'.$slug;
    }

    /**
     * @param  object  $result
     * @return callable
     */
    private static function check($result)
    {
        $name = (string) $result->name;
        $label = isset($result->label) && (string) $result->label !== '' ? (string) $result->label : $name;
        $summary = isset($result->shortSummary) ? (string) $result->shortSummary : '';
        $status = isset($result->status) ? (string) $result->status : '';
        $value = $summary !== '' && strlen($summary) <= 64 ? $summary : null;

        return function () use ($label, $status, $value) {
            switch ($status) {
                case 'ok':
                    return CheckResult::pass($label, 'medium', $value);
                case 'warning':
                    return CheckResult::warn($label, 'medium', $value);
                case 'failed':
                case 'crashed':
                    return CheckResult::fail($label, 'medium', $value);
                default:
                    return CheckResult::info($label, 'medium', $value);
            }
        };
    }
}
