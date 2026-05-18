<?php

declare(strict_types=1);

namespace App\Application\Notification\Query\GetNotificationsByRecipient;

use App\Application\Notification\ReadRepository\NotificationReadRepository;

final readonly class GetNotificationsByRecipientHandler
{
    public function __construct(
        private NotificationReadRepository $readRepository
    ) {}

    /**
     * @return array{data: NotificationView[], next_cursor: string|null}
     */
    public function handle(GetNotificationsByRecipientQuery $query): array
    {
        return $this->readRepository->findByRecipient(
            $query->recipient,
            $query->cursor,
            $query->perPage
        );
    }
}
