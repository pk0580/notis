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
use LogicException;

final readonly class EloquentNotificationRepository implements NotificationRepository
{
    private const int DEFAULT_INSERT_CHUNK = 2000;

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
            if ($version === 0 && ! NotificationModel::query()->where('id', $notification->id->value)->exists()) {
                NotificationModel::query()->create(array_merge($data, ['version' => 1]));
                $notification->incrementVersion();

                return;
            }

            throw new ConcurrencyException(
                "Notification {$notification->id->value} was modified by another process or does not exist."
            );
        }

        $notification->incrementVersion();
    }

    /**
     * Bulk-insert freshly created notifications in chunks (plan §3.4 / §10 Phase 2).
     *
     * Precondition: every notification has `version=0` (just constructed via Notification::create()).
     * Callers that need to update existing aggregates must use save() per entity.
     */
    public function saveMany(Notification ...$notifications): void
    {
        if ($notifications === []) {
            return;
        }

        foreach ($notifications as $n) {
            if ($n->version() !== 0) {
                throw new LogicException(
                    "saveMany supports only newly-created notifications (version=0); "
                    ."got version={$n->version()} for {$n->id->value}"
                );
            }
        }

        $chunkSize = max(1, (int) config('notifications.insert_chunk', self::DEFAULT_INSERT_CHUNK));

        DB::transaction(function () use ($notifications, $chunkSize): void {
            foreach (array_chunk($notifications, $chunkSize) as $chunk) {
                NotificationModel::query()->insert(array_map(
                    fn (Notification $n) => $this->toInsertRow($n),
                    $chunk,
                ));
            }

            foreach ($notifications as $n) {
                $n->incrementVersion();
            }
        });
    }

    public function findById(NotificationId $id): ?Notification
    {
        /** @var NotificationModel|null $model */
        $model = NotificationModel::query()->find($id->value);

        return $model ? $this->mapper->toDomain($model) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function toInsertRow(Notification $notification): array
    {
        $row = $this->mapper->toRow($notification);
        $row['version'] = 1;
        $row['status_history'] = json_encode($row['status_history'], JSON_THROW_ON_ERROR);
        $row['created_at'] = $notification->createdAt;
        $row['updated_at'] = $notification->updatedAt;

        return $row;
    }
}
