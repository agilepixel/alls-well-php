<?php

namespace AgilePixel\AllsWell\Laravel;

/**
 * Where an application registers its own checks and, optionally, the
 * administrators it reports. Call it from a service provider's boot().
 */
final class AllsWell
{
    /**
     * @var array<string, mixed>
     */
    private static $checks = [];

    /**
     * @var callable|null
     */
    private static $administrators;

    private function __construct() {}

    /**
     * @param  string  $key  The check's key, such as app.stripe.
     * @param  mixed  $check  A Check, a callable returning a CheckResult, or the name of a class that is one.
     * @return void
     */
    public static function check($key, $check)
    {
        self::$checks[(string) $key] = $check;
    }

    /**
     * Opt in to reporting administrators; logins are masked before they leave.
     *
     * @return void
     */
    public static function administrators(callable $resolver)
    {
        self::$administrators = $resolver;
    }

    /**
     * @return array<string, mixed>
     */
    public static function checks()
    {
        return self::$checks;
    }

    /**
     * @return callable|null
     */
    public static function administratorsResolver()
    {
        return self::$administrators;
    }

    /**
     * @return void
     */
    public static function flush()
    {
        self::$checks = [];
        self::$administrators = null;
    }
}
