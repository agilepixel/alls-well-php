<?php

namespace AgilePixel\AllsWell\Support;

/**
 * How the owner-facing messages name the host's commands and what to do
 * when a disconnect can't be kept: the CLI's wording, or the Laravel one.
 */
final class Wording
{
    /** @var string */
    private $connect;

    /** @var string */
    private $disconnect;

    /** @var string */
    private $deletedWithToken;

    /** @var string */
    private $disconnectNotKept;

    /** @var string */
    private $keyNeedsState;

    /**
     * @param  string  $connect
     * @param  string  $disconnect
     * @param  string  $deletedWithToken
     * @param  string  $disconnectNotKept
     * @param  string  $keyNeedsState
     */
    private function __construct($connect, $disconnect, $deletedWithToken, $disconnectNotKept, $keyNeedsState)
    {
        $this->connect = $connect;
        $this->disconnect = $disconnect;
        $this->deletedWithToken = $deletedWithToken;
        $this->disconnectNotKept = $disconnectNotKept;
        $this->keyNeedsState = $keyNeedsState;
    }

    /**
     * @return Wording
     */
    public static function cli()
    {
        return new self(
            '`connect`',
            '`disconnect`',
            "This site was deleted in All's Well. Set ALLS_WELL_TOKEN to a new site's token, or remove it and the cron line.",
            "Without ALLS_WELL_STATE_PATH a disconnect can't be remembered, so the next report would reconnect. "
                .'Remove ALLS_WELL_TOKEN or the cron line, or set ALLS_WELL_STATE_PATH.',
            'Set ALLS_WELL_STATE_PATH so the site token the key enrols can be kept.'
        );
    }

    /**
     * @return Wording
     */
    public static function laravel()
    {
        return new self(
            '`php artisan alls-well:connect`',
            '`php artisan alls-well:disconnect`',
            "This site was deleted in All's Well. Set ALLS_WELL_TOKEN to a new site's token, or remove it to stop reporting.",
            "The cache can't keep a disconnect between runs, so the next report would reconnect. "
                .'Remove ALLS_WELL_TOKEN, or run php artisan migrate so the disconnect is kept.',
            'With ALLS_WELL_KEY the site token must be kept: run php artisan migrate, '
                .'or set ALLS_WELL_STORE=cache with a cache that outlives each run.'
        );
    }

    /**
     * @return string The command that connects the site, in backticks.
     */
    public function connect()
    {
        return $this->connect;
    }

    /**
     * @return string The command that stops reporting, in backticks.
     */
    public function disconnect()
    {
        return $this->disconnect;
    }

    /**
     * @return string
     */
    public function deletedWithToken()
    {
        return $this->deletedWithToken;
    }

    /**
     * @return string
     */
    public function disconnectNotKept()
    {
        return $this->disconnectNotKept;
    }

    /**
     * @return string What to do when a key is set but the state can't be kept.
     */
    public function keyNeedsState()
    {
        return $this->keyNeedsState;
    }
}
