<?php

namespace App\Services\Courier;

class CourierEvent
{
    public function __construct(
        public readonly string $occurredAt,      // 'Y-m-d H:i:s'
        public readonly ?string $code = null,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
        public readonly array $raw = [],
    ) {}
}
