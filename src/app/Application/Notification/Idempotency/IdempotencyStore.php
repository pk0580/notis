<?php

declare(strict_types=1);

namespace App\Application\Notification\Idempotency;

interface IdempotencyStore
{
    public function get(string $key): ?array;

    public function set(string $key, array $data, int $ttlSeconds): void;
}
