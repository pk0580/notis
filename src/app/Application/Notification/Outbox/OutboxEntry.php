<?php

declare(strict_types=1);

namespace App\Application\Notification\Outbox;

use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\Priority;

final readonly class OutboxEntry
{
    public function __construct(
        public NotificationId $notificationId,
        public Priority $priority,
    ) {}
}
