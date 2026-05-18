<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Idempotency;

use App\Application\Notification\Idempotency\IdempotencyStore;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

final readonly class RedisIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private RedisFactory $redis,
        private string $connection = 'default',
    ) {}

    public function get(string $key): ?array
    {
        $data = $this->redis->connection($this->connection)->get($this->fullKey($key));

        return $data ? json_decode((string) $data, true) : null;
    }

    public function set(string $key, array $data, int $ttlSeconds): void
    {
        $this->redis->connection($this->connection)->setex(
            $this->fullKey($key),
            $ttlSeconds,
            (string) json_encode($data)
        );
    }

    private function fullKey(string $key): string
    {
        return "idempotency:notifications.dispatch:{$key}";
    }
}
