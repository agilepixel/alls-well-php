<?php

namespace AgilePixel\AllsWell\Support;

/**
 * An object's class, the only part of an exception (or of a check's wrong
 * answer) the package prints, logs or reports.
 */
final class ClassName
{
    /**
     * The class name without the NUL byte and file path PHP appends to an
     * anonymous class's name.
     *
     * @return string
     */
    public static function of(object $object)
    {
        $class = get_class($object);
        $end = strpos($class, "\0");

        return $end === false ? $class : substr($class, 0, $end);
    }
}
