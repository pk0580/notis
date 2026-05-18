<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Gateway\SendResult;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final readonly class StubSmsGateway implements NotificationGateway
{
    private const REDIS_KEY = 'gateway:idempotency:sms';

    public function send(Notification $notification): SendResult
    {
        // 1. Дедупликация (N3)
        $cachedId = Redis::hget(StubSmsGateway::REDIS_KEY, $notification->id->value);
        if ($cachedId) {
            return new SendResult(new ProviderMessageId($cachedId));
        }

        // 2. Имитация распределения (§6.4)
        $chance = random_int(1, 100);

        // 5% - GatewayRejectedException (permanent)
        if ($chance <= 5) {
            throw new GatewayRejectedException('Stub SMS Gateway: Recipient rejected');
        }

        // 15% - GatewayUnavailableException (transient)
        if ($chance <= 20) {
            throw new GatewayUnavailableException('Stub SMS Gateway: Provider temporary unavailable');
        }

        // 80% - Success
        $messageId = 'sms_'.Str::random(10);

        Redis::hset(StubSmsGateway::REDIS_KEY, $notification->id->value, $messageId);
        Redis::expire(StubSmsGateway::REDIS_KEY, 86400); // 24h

        return new SendResult(new ProviderMessageId($messageId));
    }
}
