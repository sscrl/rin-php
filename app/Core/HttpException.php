<?php
declare(strict_types=1);

namespace Rin\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        private int $status = 400,
        private bool $asText = true,
        private mixed $payload = null,
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function asText(): bool
    {
        return $this->asText;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public static function text(string $message, int $status): self
    {
        return new self($message, $status, true);
    }

    public static function json(string $message, int $status, mixed $payload = null): self
    {
        return new self($message, $status, false, $payload);
    }
}
