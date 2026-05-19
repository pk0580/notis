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
}
