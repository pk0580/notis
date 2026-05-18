<?php

declare(strict_types=1);

namespace App\Application\Notification\UseCase\DispatchNotifications;

use App\Domain\Notification\ValueObject\NotificationId;

final readonly class DispatchAcceptedResult
{
    /**
     * @param  NotificationId[]  $notificationIds
     */
    public function __construct(
        public int $accepted,
        public array $notificationIds,
    ) {}
}
