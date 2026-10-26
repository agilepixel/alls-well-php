<?php

namespace AgilePixel\AllsWell\Laravel\Checks;

/**
 * Which environment names count as production.
 */
final class Environment
{
    /**
     * @param  string  $env
     * @return bool
     */
    public static function isProduction($env)
    {
        return in_array(strtolower(trim((string) $env)), ['production', 'prod', 'live'], true);
    }

    /**
     * @param  string  $value
     * @return string|null
     */
    public static function shortLabel($value)
    {
        $value = (string) $value;

        return $value === '' || strlen($value) > 32 ? null : $value;
    }
}
