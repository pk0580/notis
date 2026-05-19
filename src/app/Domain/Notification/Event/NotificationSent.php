<?php

declare(strict_types=1);

namespace App\Domain\Notification\Event;

use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\ProviderMessageId;

final readonly class NotificationSent
{
    public function __construct(
        public NotificationId $notificationId,
        public ProviderMessageId $providerMessageId,
    ) {}
}
