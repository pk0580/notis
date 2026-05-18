<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Repositories;

use App\Application\Notification\Query\GetNotificationsByRecipient\NotificationView;
use App\Application\Notification\ReadRepository\NotificationReadRepository;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\Recipient;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;

final class EloquentNotificationReadRepository implements NotificationReadRepository
{
    public function findByRecipient(Recipient $recipient, ?string $cursor, int $perPage): array
    {
        $paginator = NotificationModel::query()
            ->select([
                'id',
                'recipient',
                'channel',
                'priority',
                'status',
                'attempts',
                'last_error',
                'status_history',
                'created_at',
                'updated_at',
            ])
            ->where('recipient', $recipient->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        $items = collect($paginator->items())->map(function (NotificationModel $model) {
            $channel = Channel::from($model->channel);
            $r = new Recipient($model->recipient, $channel);

            return new NotificationView(
                id: (string) $model->id,
                channel: $model->channel,
                priority: $model->priority,
                status: $model->status,
                recipient_masked: $r->masked(),
                attempts: $model->attempts,
                last_error: $model->last_error,
                status_history: $model->status_history ?? [],
                created_at: $model->created_at->toIso8601String(),
                updated_at: $model->updated_at->toIso8601String()
            );
        })->all();

        return [
            'data' => $items,
            'next_cursor' => $paginator->nextCursor()?->encode(),
        ];
    }
}
