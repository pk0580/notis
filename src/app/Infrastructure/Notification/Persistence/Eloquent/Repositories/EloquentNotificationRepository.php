<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Repositories;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Exception\ConcurrencyException;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Infrastructure\Notification\Persistence\Eloquent\Mappers\NotificationMapper;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationRepository implements NotificationRepository
{
    public function __construct(
        private NotificationMapper $mapper
    ) {}

    public function save(Notification $notification): void
    {
        $data = $this->mapper->toRow($notification);
        $version = $data['version'];
        unset($data['version']);

        $updated = NotificationModel::query()
            ->where('id', $notification->id->value)
            ->where('version', $version)
            ->update(array_merge($data, ['version' => $version + 1]));

        if ($updated === 0) {
            // If it's a new notification, it won't be updated, so we try to create it
            if ($version === 0 && ! NotificationModel::query()->where('id', $notification->id->value)->exists()) {
                NotificationModel::query()->create(array_merge($data, ['version' => 1]));
                $notification->incrementVersion();

                return;
            }

            throw new ConcurrencyException("Notification {$notification->id->value} was modified by another process or does not exist.");
        }

        $notification->incrementVersion();
    }

    public function saveMany(Notification ...$notifications): void
    {
        DB::transaction(function () use ($notifications) {
            foreach ($notifications as $notification) {
                $this->save($notification);
            }
        });
    }

    public function findById(NotificationId $id): ?Notification
    {
        /** @var NotificationModel|null $model */
        $model = NotificationModel::query()->find($id->value);

        return $model ? $this->mapper->toDomain($model) : null;
    }
}
