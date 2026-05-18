<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\AcknowledgeDelivery;

use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;

final readonly class AcknowledgeDeliveryData
{
    public function __construct(
        public NotificationId $notificationId,
        public NotificationStatus $finalStatus,
        public ?string $reason = null,
    ) {}
}
