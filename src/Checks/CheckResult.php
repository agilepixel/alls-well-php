<?php

namespace AgilePixel\AllsWell\Checks;

/**
 * The outcome of one check, in the protocol's shape.
 */
final class CheckResult
{
    const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    /**
     * @var string
     */
    private $status;

    /**
     * @var string
     */
    private $label;

    /**
     * @var string
     */
    private $severity;

    /**
     * @var bool|int|float|string|null
     */
    private $value;

    /**
     * @var string|null
     */
    private $detail;

    /**
     * @param  string  $status
     * @param  string  $label
     * @param  string  $severity
     * @param  mixed  $value
     * @param  string|null  $detail
     */
    private function __construct($status, $label, $severity, $value, $detail)
    {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException('Unknown check severity.');
        }

        $this->status = $status;
        $this->label = (string) $label;
        $this->severity = $severity;
        $this->value = $value;
        $this->detail = $detail;
    }

    /**
     * @param  string  $label
     * @param  string  $severity
     * @param  mixed  $value
     * @param  string|null  $detail
     * @return self
     */
    public static function pass($label, $severity, $value = null, $detail = null)
    {
        return new self('pass', $label, $severity, $value, $detail);
    }

    /**
     * @param  string  $label
     * @param  string  $severity
     * @param  mixed  $value
     * @param  string|null  $detail
     * @return self
     */
    public static function warn($label, $severity, $value = null, $detail = null)
    {
        return new self('warn', $label, $severity, $value, $detail);
    }

    /**
     * @param  string  $label
     * @param  string  $severity
     * @param  mixed  $value
     * @param  string|null  $detail
     * @return self
     */
    public static function fail($label, $severity, $value = null, $detail = null)
    {
        return new self('fail', $label, $severity, $value, $detail);
    }

    /**
     * @param  string  $label
     * @param  string  $severity
     * @param  mixed  $value
     * @param  string|null  $detail
     * @return self
     */
    public static function info($label, $severity, $value = null, $detail = null)
    {
        return new self('info', $label, $severity, $value, $detail);
    }

    /**
     * @param  string  $key
     * @return array{key: string, label: string, status: string, severity: string, value: bool|int|float|string|null, detail: string|null}
     */
    public function toArray($key)
    {
        $label = self::cut($this->label, 255);

        return [
            'key' => $key,
            'label' => $label === '' ? $key : $label,
            'status' => $this->status,
            'severity' => $this->severity,
            'value' => $this->scalarValue(),
            'detail' => $this->detail === null ? null : self::cut((string) $this->detail, 1000),
        ];
    }

    /**
     * @return bool|int|float|string|null
     */
    private function scalarValue()
    {
        $value = $this->value;

        if (is_string($value)) {
            return self::cut($value, 255);
        }
        if (is_float($value) && ! is_finite($value)) {
            return null;
        }

        return is_bool($value) || is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @param  string  $text
     * @param  int  $length
     * @return string
     */
    private static function cut($text, $length)
    {
        if (preg_match('//u', $text) !== 1) {
            $text = (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
        }

        if (preg_match('/^.{0,'.$length.'}/us', $text, $matches) === 1) {
            return $matches[0];
        }

        return substr($text, 0, $length);
    }
}
