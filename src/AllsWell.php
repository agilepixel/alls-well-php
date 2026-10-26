<?php

namespace AgilePixel\AllsWell;

use AgilePixel\AllsWell\Http\CurlTransport;

final class AllsWell
{
    const VERSION = '1.0.1';

    const DEFAULT_ORIGIN = 'https://alls-well.io';

    /**
     * The User-Agent every request to All's Well carries.
     */
    public static function userAgent(): string
    {
        return 'AllsWell-PHP/'.self::VERSION;
    }

    /**
     * A Reporter for an app with its own bootstrap: call report() from its
     * own cron. The settings are those Config::fromArray() takes.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function reporter(array $settings): Reporter
    {
        return new Reporter(Config::fromArray($settings), new Client(new CurlTransport));
    }
}
