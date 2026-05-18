<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification\Persistence\Eloquent\Mappers;

use App\Domain\Notification\Entity\Notification;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\NotificationId;
use App\Domain\Notification\ValueObject\NotificationStatus;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\ProviderMessageId;
use App\Domain\Notification\ValueObject\Recipient;
use App\Domain\Notification\ValueObject\StatusHistory;
use App\Infrastructure\Notification\Persistence\Eloquent\Models\NotificationModel;

final class NotificationMapper
{
    public function toDomain(NotificationModel $model): Notification
    {
        return Notification::reconstitute(
            id: new NotificationId($model->id),
            recipient: Recipient::fromString(Channel::from($model->channel), $model->recipient),
            channel: Channel::from($model->channel),
            priority: Priority::from($model->priority),
            body: new MessageBody($model->body),
            status: NotificationStatus::from($model->status),
            history: new StatusHistory($model->status_history),
            traceId: $model->trace_id,
            providerMessageId: $model->provider_message_id ? new ProviderMessageId($model->provider_message_id) : null,
            attempts: $model->attempts,
            lastError: $model->last_error,
            createdAt: $model->created_at->toDateTimeImmutable(),
            updatedAt: $model->updated_at->toDateTimeImmutable(),
            version: $model->version
        );
    }

    public function toRow(Notification $notification): array
    {
        return [
            'id' => $notification->id->value,
            'recipient' => $notification->recipient->value,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'body' => $notification->body->value,
            'status' => $notification->status()->value,
            'status_history' => $notification->history()->items,
            'attempts' => $notification->attempts(),
            'last_error' => $notification->lastError(),
            'provider_message_id' => $notification->providerMessageId()?->value,
            'trace_id' => $notification->traceId,
            'version' => $notification->version(),
        ];
    }
}
