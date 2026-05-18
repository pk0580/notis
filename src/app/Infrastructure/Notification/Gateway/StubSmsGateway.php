<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\GatewayRejectedException;
use App\Domain\Notification\Exception\GatewayUnavailableException;
use App\Domain\Notification\Gateway\GatewayResult;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final readonly class StubSmsGateway implements NotificationGateway
{
    private const REDIS_KEY = 'gateway:idempotency:sms';

    public function supports(Channel $channel): bool
    {
        return $channel === Channel::Sms;
    }

    public function send(Notification $notification): GatewayResult
    {
        $cachedId = Redis::hget(self::REDIS_KEY, $notification->id->value);
        if ($cachedId) {
            return new GatewayResult(new ProviderMessageId($cachedId));
        }

        $chance = random_int(1, 100);

        if ($chance <= 5) {
            throw new GatewayRejectedException('Stub SMS Gateway: Recipient rejected');
        }

        if ($chance <= 20) {
            throw new GatewayUnavailableException('Stub SMS Gateway: Provider temporary unavailable');
        }

        $messageId = 'sms_'.Str::random(10);

        Redis::hset(self::REDIS_KEY, $notification->id->value, $messageId);
        Redis::expire(self::REDIS_KEY, 86400);

        return new GatewayResult(new ProviderMessageId($messageId));
    }
}
