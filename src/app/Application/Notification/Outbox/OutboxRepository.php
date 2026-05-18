<?php

declare(strict_types=1);

namespace App\Application\Notification\Outbox;

interface OutboxRepository
{
    public function persist(string $notificationId, string $priority): void;

    /** @return list<array{id: string, notification_id: string, priority: string}> */
    public function findUnpublished(int $limit): array;

    public function markAsPublished(string $id): void;

    public function markAsFailed(string $id, string $error): void;
}
