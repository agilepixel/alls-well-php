<?php

namespace AgilePixel\AllsWell\Http;

/**
 * What All's Well answered. Status 0 means there was no HTTP answer, and
 * error() then says why.
 */
final class Response
{
    const MESSAGE_LIMIT = 1000;

    /**
     * @var int
     */
    private $status;

    /**
     * @var array<mixed>
     */
    private $body;

    /**
     * @var int|null
     */
    private $retryAfter;

    /**
     * @var string|null
     */
    private $error;

    /**
     * @param  array<mixed>  $body
     */
    public function __construct(int $status, array $body, ?int $retryAfter, ?string $error)
    {
        $this->status = $status;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
        $this->error = $error;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function isSuccess(): bool
    {
        return $this->status === 200 || $this->status === 201;
    }

    public function errorCode(): ?string
    {
        return isset($this->body['error']) && is_string($this->body['error']) ? $this->body['error'] : null;
    }

    public function message(): ?string
    {
        if (! isset($this->body['message']) || ! is_string($this->body['message'])) {
            return null;
        }

        $message = trim($this->body['message']);
        if (preg_match('/^.{0,'.self::MESSAGE_LIMIT.'}/su', $message, $match) === 1) {
            return $match[0];
        }

        return substr($message, 0, self::MESSAGE_LIMIT);
    }
}
