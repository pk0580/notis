<?php

declare(strict_types=1);

namespace App\Domain\Notification\Repository;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\ValueObject\NotificationId;

interface NotificationRepository
{
    public function save(Notification $notification): void;

    /** @param list<Notification> $notifications */
    public function saveMany(array $notifications): void;

    public function findById(NotificationId $id): ?Notification;

    /**
     * @param string $recipient
     * @param int $limit
     * @return list<Notification>
     */
    public function findByRecipient(string $recipient, int $limit): array;
}
