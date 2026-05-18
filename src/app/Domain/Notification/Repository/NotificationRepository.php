<?php

declare(strict_types=1);

namespace App\Domain\Notification\Repository;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\ValueObject\NotificationId;

interface NotificationRepository
{
    public function save(Notification $notification): void;

    public function saveMany(Notification ...$notifications): void;

    public function findById(NotificationId $id): ?Notification;
}
