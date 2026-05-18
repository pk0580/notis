<?php

declare(strict_types=1);

namespace App\Domain\Notification\ValueObject;

enum NotificationStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Dropped = 'dropped';
}
