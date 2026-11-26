<?php

namespace AgilePixel\AllsWell\Support;

/**
 * How the owner-facing messages name the host's commands and settings, and
 * what they tell the owner to do: the CLI's wording, the Laravel one, or a
 * host's own.
 */
final class Wording
{
    private const CLI = [
        'connect' => '`connect`',
        'disconnect' => '`disconnect`',
        'deleted_with_token' => "This site was deleted in All's Well. Set ALLS_WELL_TOKEN to a new site's token, or remove it and the cron line.",
        'disconnect_not_kept' => "Without ALLS_WELL_STATE_PATH a disconnect can't be remembered, so the next report would reconnect. "
            .'Remove ALLS_WELL_TOKEN or the cron line, or set ALLS_WELL_STATE_PATH.',
        'key_needs_state' => 'Set ALLS_WELL_STATE_PATH so the site token the key enrols can be kept.',
        'address_setting' => 'ALLS_WELL_SITE_URL',
        'not_connected' => "This site isn't connected to All's Well. Set ALLS_WELL_TOKEN or ALLS_WELL_KEY.",
        'site_exists' => "This site is already connected and reporting. Set ALLS_WELL_TOKEN to its site token, or delete it in All's Well.",
        'disconnected' => 'Disconnected. Remove ALLS_WELL_TOKEN or ALLS_WELL_KEY to stop for good.',
    ];

    private const LARAVEL = [
        'connect' => '`php artisan alls-well:connect`',
        'disconnect' => '`php artisan alls-well:disconnect`',
        'deleted_with_token' => "This site was deleted in All's Well. Set ALLS_WELL_TOKEN to a new site's token, or remove it to stop reporting.",
        'disconnect_not_kept' => "The cache can't keep a disconnect between runs, so the next report would reconnect. "
            .'Remove ALLS_WELL_TOKEN, or run php artisan migrate so the disconnect is kept.',
        'key_needs_state' => 'With ALLS_WELL_KEY the site token must be kept: run php artisan migrate, '
            .'or set ALLS_WELL_STORE=cache with a cache that outlives each run.',
    ];

    /** @var array<string, string> */
    private $phrases;

    /**
     * @param  array<string, string>  $phrases
     */
    private function __construct(array $phrases)
    {
        $this->phrases = $phrases;
    }

    /**
     * @return Wording
     */
    public static function cli()
    {
        return new self(self::CLI);
    }

    /**
     * @return Wording
     */
    public static function laravel()
    {
        return self::custom(self::LARAVEL);
    }

    /**
     * A host's own phrases. Whatever it leaves out, or gives as anything but
     * text, is the CLI's; `reconnect` follows from `connect`.
     *
     * @param  array<mixed>  $phrases  connect, disconnect, reconnect, deleted_with_token, disconnect_not_kept, key_needs_state, address_setting, not_connected, site_exists, disconnected
     * @return Wording
     */
    public static function custom(array $phrases)
    {
        $wording = self::CLI;

        foreach (array_intersect_key($phrases, self::CLI + ['reconnect' => null]) as $key => $phrase) {
            if (is_string($phrase) && $phrase !== '') {
                $wording[$key] = $phrase;
            }
        }

        return new self($wording);
    }

    /**
     * @return string The command that connects the site, in backticks.
     */
    public function connect()
    {
        return $this->phrases['connect'];
    }

    /**
     * @return string The command that stops reporting, in backticks.
     */
    public function disconnect()
    {
        return $this->phrases['disconnect'];
    }

    /**
     * @return string How to connect a site whose connection no longer holds, as a sentence.
     */
    public function reconnect()
    {
        return isset($this->phrases['reconnect']) ? $this->phrases['reconnect'] : 'Run '.$this->connect().' to connect it again.';
    }

    /**
     * @return string
     */
    public function deletedWithToken()
    {
        return $this->phrases['deleted_with_token'];
    }

    /**
     * @return string
     */
    public function disconnectNotKept()
    {
        return $this->phrases['disconnect_not_kept'];
    }

    /**
     * @return string What to do when a key is set but the state can't be kept.
     */
    public function keyNeedsState()
    {
        return $this->phrases['key_needs_state'];
    }

    /**
     * @return string Where the site's address is set: "Set … to the address this site is served from."
     */
    public function addressSetting()
    {
        return $this->phrases['address_setting'];
    }

    /**
     * @return string What to do when the site isn't connected.
     */
    public function notConnected()
    {
        return $this->phrases['not_connected'];
    }

    /**
     * @return string What to do when a key meets a site that is already connected and reporting.
     */
    public function siteExists()
    {
        return $this->phrases['site_exists'];
    }

    /**
     * @return string What disconnecting says once it's done.
     */
    public function disconnected()
    {
        return $this->phrases['disconnected'];
    }
}
