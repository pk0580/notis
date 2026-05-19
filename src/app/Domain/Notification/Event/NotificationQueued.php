<?php

declare(strict_types=1);

namespace App\Domain\Notification\Event;

use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\Priority;

final readonly class NotificationQueued
{
    public function __construct(
        public NotificationId $notificationId,
        public Priority $priority,
    ) {}
}
