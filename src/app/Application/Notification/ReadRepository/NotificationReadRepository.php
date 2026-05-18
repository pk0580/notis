<?php

declare(strict_types=1);

namespace App\Application\Notification\ReadRepository;

use App\Application\Notification\Query\GetNotificationsByRecipient\NotificationView;
use App\Domain\Notification\ValueObject\Recipient;

interface NotificationReadRepository
{
    /**
     * @return array{data: NotificationView[], next_cursor: string|null}
     */
    public function findByRecipient(Recipient $recipient, ?string $cursor, int $perPage): array;
}
