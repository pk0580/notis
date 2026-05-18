<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Repositories;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\Repository\NotificationRepository;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Infrastructure\Notification\Persistence\Eloquent\Mappers\NotificationMapper;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationRepository implements NotificationRepository
{
    public function __construct(
        private NotificationMapper $mapper
    ) {
    }

    public function save(Notification $notification): void
    {
        NotificationModel::query()->updateOrCreate(
            ['id' => $notification->id->value],
            $this->mapper->toRow($notification)
        );
    }

    public function saveMany(array $notifications): void
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

    public function findByRecipient(string $recipient, int $limit): array
    {
        return NotificationModel::query()
            ->where('recipient', $recipient)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (NotificationModel $model) => $this->mapper->toDomain($model))
            ->all();
    }
}
