<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Resource;

use App\Application\Notification\Query\GetNotificationsByRecipient\NotificationView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read NotificationView $resource
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'channel' => $this->resource->channel,
            'priority' => $this->resource->priority,
            'status' => $this->resource->status,
            'recipient_masked' => $this->resource->recipient_masked,
            'attempts' => $this->resource->attempts,
            'last_error' => $this->resource->last_error,
            'status_history' => $this->resource->status_history,
            'created_at' => $this->resource->created_at,
            'updated_at' => $this->resource->updated_at,
        ];
    }
}
