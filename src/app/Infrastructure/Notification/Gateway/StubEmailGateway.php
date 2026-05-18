<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Gateway;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Gateway\NotificationGateway;
use App\Domain\Notification\Gateway\SendResult;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Infrastructure\Notification\Job\SimulateDeliveryAckJob;
use Illuminate\Support\Str;

final readonly class StubEmailGateway implements NotificationGateway
{
    public function send(Notification $notification): SendResult
    {
        // Имитируем работу внешнего API
        $messageId = 'email_' . Str::random(10);

        // Имитируем асинхронный колбэк о доставке через 1-5 секунд
        SimulateDeliveryAckJob::dispatch($notification->id->value)
            ->delay(now()->addSeconds(random_int(1, 5)));

        return new SendResult(new ProviderMessageId($messageId));
    }
}
