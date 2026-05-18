<?php

declare(strict_types=1);

namespace App\Application\Notification\ReadRepository;

use App\Domain\Notification\ValueObject\Recipient;
use App\Application\Notification\Query\GetNotificationsByRecipient\NotificationView;

interface NotificationReadRepository
{
    /**
     * @param Recipient $recipient
     * @param string|null $cursor
     * @param int $perPage
     * @return array{data: NotificationView[], next_cursor: string|null}
     */
    public function findByRecipient(Recipient $recipient, ?string $cursor, int $perPage): array;
}
