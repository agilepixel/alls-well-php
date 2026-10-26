<?php

namespace AgilePixel\AllsWell;

/**
 * What one call to Reporter came to. needsAttention() is true only when the
 * site's owner must act, which is when the cron command speaks up.
 */
final class Result
{
    const SENT = 'sent';

    const SKIPPED = 'skipped';

    const PAUSED = 'paused';

    const FAILED = 'failed';

    /** @var string */
    private $outcome;

    /** @var string */
    private $message;

    /** @var int|null */
    private $status;

    /** @var bool */
    private $needsAttention;

    /**
     * @param  string  $outcome  One of the constants.
     * @param  int|null  $status  The HTTP status All's Well answered with on this run; 0 for no answer, null for no request.
     */
    public function __construct(string $outcome, string $message, ?int $status = null, bool $needsAttention = false)
    {
        $this->outcome = $outcome;
        $this->message = $message;
        $this->status = $status;
        $this->needsAttention = $needsAttention;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->outcome === self::SENT || $this->outcome === self::SKIPPED;
    }

    public function needsAttention(): bool
    {
        return $this->needsAttention;
    }
}
