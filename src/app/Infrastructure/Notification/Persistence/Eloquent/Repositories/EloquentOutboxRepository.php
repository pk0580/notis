<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Repositories;

use App\Application\Notification\Outbox\OutboxEntry;
use App\Application\Notification\Outbox\OutboxRepository;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\OutboxMessageModel;
use Illuminate\Support\Str;

final readonly class EloquentOutboxRepository implements OutboxRepository
{
    public function appendMany(array $entries): void
    {
        $chunks = array_chunk($entries, 2000);

        foreach ($chunks as $chunk) {
            $rows = array_map(fn (OutboxEntry $entry) => [
                'id' => (string) Str::uuid(),
                'notification_id' => $entry->notificationId->value,
                'priority' => $entry->priority->value,
                'created_at' => now(),
            ], $chunk);

            OutboxMessageModel::query()->insert($rows);
        }
    }

    public function persist(string $notificationId, string $priority): void
    {
        OutboxMessageModel::query()->create([
            'id' => (string) Str::uuid(),
            'notification_id' => $notificationId,
            'priority' => $priority,
        ]);
    }

    public function findUnpublished(int $limit): array
    {
        return OutboxMessageModel::query()
            ->whereNull('published_at')
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (OutboxMessageModel $model) => [
                'id' => $model->id,
                'notification_id' => $model->notification_id,
                'priority' => $model->priority,
            ])
            ->all();
    }

    public function markAsPublished(string $id): void
    {
        OutboxMessageModel::query()
            ->where('id', $id)
            ->update(['published_at' => now()]);
    }

    public function markAsFailed(string $id, string $error): void
    {
        OutboxMessageModel::query()
            ->where('id', $id)
            ->increment('attempts', 1, ['last_error' => $error]);
    }
}
