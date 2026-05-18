<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DeliverNotification;

use App\Domain\Notification\ValueObject\NotificationId;

final readonly class DeliverNotificationData
{
    public function __construct(
        public NotificationId $notificationId,
    ) {}
}
