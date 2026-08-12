<?php

namespace App\Services\Courier;

class CourierResult
{
    private function __construct(
        public readonly string $status,
        public readonly array $events,
        public readonly bool $notFound,
        public readonly ?string $error,
    ) {}

    /** @param CourierEvent[] $events */
    public static function found(string $status, array $events): self
    {
        return new self($status, $events, false, null);
    }

    public static function notFound(): self
    {
        return new self(CourierStatus::SIN_DATOS, [], true, null);
    }

    public static function error(string $message): self
    {
        return new self(CourierStatus::SIN_DATOS, [], false, $message);
    }
}
