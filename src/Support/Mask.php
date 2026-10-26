<?php

namespace AgilePixel\AllsWell\Support;

/**
 * Masks administrator logins before they leave the site.
 */
final class Mask
{
    const MAX_LENGTH = 191;

    /**
     * "jane@example.com" becomes "j***@example.com"; a login without a name
     * before its "@" is kept as it is. At most 191 characters.
     *
     * @param  string  $login
     * @return string
     */
    public static function login($login)
    {
        $at = strrpos($login, '@');
        if ($at === false || $at === 0) {
            return self::cut($login);
        }

        return self::cut(self::cut($login, 1).'***'.substr($login, $at));
    }

    /**
     * @param  string  $value
     * @param  int  $length
     * @return string
     */
    private static function cut($value, $length = self::MAX_LENGTH)
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
