<?php

namespace AgilePixel\AllsWell\Support;

/**
 * Formats a Reporter status for printing, one line per key.
 */
final class StatusLines
{
    /**
     * Every key but `errors`, which the caller lists, in the order given.
     *
     * @param  array<string, mixed>  $status
     * @return array<int, array{0: string, 1: string}>
     */
    public static function lines(array $status)
    {
        unset($status['errors']);

        $lines = [];
        foreach ($status as $key => $value) {
            $lines[] = [(string) $key, self::format((string) $key, $value)];
        }

        return $lines;
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    private static function format($key, $value)
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($value === null || $value === '' || ($key === 'backoff_until' && $value === 0)) {
            return '-';
        }
        if (is_int($value) && substr($key, -3) === '_at') {
            return gmdate('Y-m-d\TH:i:s\Z', $value);
        }
        if ($key === 'backoff_until' && is_int($value)) {
            return gmdate('Y-m-d\TH:i:s\Z', $value);
        }

        return preg_replace('/\s+/', ' ', is_scalar($value) ? (string) $value : '') ?? '';
    }
}
