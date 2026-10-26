<?php

namespace AgilePixel\AllsWell\State;

/**
 * What the package remembers between runs: the connection, any pause and the
 * back-off. Immutable; every change returns a new copy.
 */
final class State
{
    const PAUSED_DELETED = 'deleted';

    const PAUSED_DISCONNECTED = 'disconnected';

    const PAUSED_MOVED = 'moved';

    const PAUSED_SITE_MOVED = 'site_moved';

    const DEFAULT_INTERVAL = 3600;

    const STRING_FIELDS = [
        'site_token', 'site_id', 'origin', 'report_url', 'dashboard_url',
        'enrolled_site_key', 'last_error',
    ];

    const INT_FIELDS = [
        'interval_seconds' => self::DEFAULT_INTERVAL,
        'backoff_until' => 0,
        'failures' => 0,
        'last_attempt_at' => null,
        'last_report_at' => null,
        'last_status' => null,
    ];

    const CONNECTION_FIELDS = [
        'site_token', 'site_id', 'origin', 'report_url', 'dashboard_url',
        'enrolled_site_key', 'last_report_at',
    ];

    /** @var array<string, mixed> */
    private $data;

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return self
     */
    public static function empty()
    {
        return self::fromArray([]);
    }

    /**
     * Never throws: unknown keys are ignored, and a value of the wrong type
     * falls back to its default. Integers may arrive as digit-only strings.
     *
     * @param  array<mixed>  $data
     * @return self
     */
    public static function fromArray(array $data)
    {
        $clean = [];

        foreach (self::STRING_FIELDS as $key) {
            $clean[$key] = isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        }

        foreach (self::INT_FIELDS as $key => $default) {
            $clean[$key] = self::integer(isset($data[$key]) ? $data[$key] : null, $default);
        }

        $paused = isset($data['paused']) ? $data['paused'] : null;
        $clean['paused'] = in_array($paused, [
            self::PAUSED_DELETED, self::PAUSED_DISCONNECTED, self::PAUSED_MOVED, self::PAUSED_SITE_MOVED,
        ], true) ? $paused : null;

        return new self($clean);
    }

    /**
     * @param  mixed  $value
     * @param  int|null  $default
     * @return int|null
     */
    private static function integer($value, $default)
    {
        if (is_string($value) && preg_match('/\A\d{1,15}\z/', $value) === 1) {
            return (int) $value;
        }

        return is_int($value) && $value >= 0 ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return [
            'site_token' => $this->data['site_token'],
            'site_id' => $this->data['site_id'],
            'origin' => $this->data['origin'],
            'report_url' => $this->data['report_url'],
            'dashboard_url' => $this->data['dashboard_url'],
            'enrolled_site_key' => $this->data['enrolled_site_key'],
            'interval_seconds' => $this->data['interval_seconds'],
            'paused' => $this->data['paused'],
            'backoff_until' => $this->data['backoff_until'],
            'failures' => $this->data['failures'],
            'last_attempt_at' => $this->data['last_attempt_at'],
            'last_report_at' => $this->data['last_report_at'],
            'last_status' => $this->data['last_status'],
            'last_error' => $this->data['last_error'],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes  snake_case keys, as in toArray()
     * @return self
     */
    public function with(array $changes)
    {
        return self::fromArray(array_merge($this->data, $changes));
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->data['site_token'] !== null && $this->data['report_url'] !== null;
    }

    /**
     * Drops the connection and what belongs to it, keeping the pause and the
     * last attempt's outcome.
     *
     * @return self
     */
    public function forgetConnection()
    {
        $changes = array_fill_keys(self::CONNECTION_FIELDS, null);
        $changes['interval_seconds'] = self::DEFAULT_INTERVAL;
        $changes['failures'] = 0;
        $changes['backoff_until'] = 0;

        return $this->with($changes);
    }

    /** @return string|null */
    public function siteToken()
    {
        return $this->data['site_token'];
    }

    /** @return string|null */
    public function siteId()
    {
        return $this->data['site_id'];
    }

    /** @return string|null */
    public function origin()
    {
        return $this->data['origin'];
    }

    /** @return string|null */
    public function reportUrl()
    {
        return $this->data['report_url'];
    }

    /** @return string|null */
    public function dashboardUrl()
    {
        return $this->data['dashboard_url'];
    }

    /** @return string|null */
    public function enrolledSiteKey()
    {
        return $this->data['enrolled_site_key'];
    }

    /** @return int */
    public function intervalSeconds()
    {
        return $this->data['interval_seconds'];
    }

    /** @return string|null */
    public function paused()
    {
        return $this->data['paused'];
    }

    /** @return int */
    public function backoffUntil()
    {
        return $this->data['backoff_until'];
    }

    /** @return int */
    public function failures()
    {
        return $this->data['failures'];
    }

    /** @return int|null */
    public function lastAttemptAt()
    {
        return $this->data['last_attempt_at'];
    }

    /** @return int|null */
    public function lastReportAt()
    {
        return $this->data['last_report_at'];
    }

    /** @return int|null */
    public function lastStatus()
    {
        return $this->data['last_status'];
    }

    /** @return string|null */
    public function lastError()
    {
        return $this->data['last_error'];
    }
}
