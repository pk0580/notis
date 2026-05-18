<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Resource;

use App\Application\Notification\UseCase\DispatchNotifications\DispatchAcceptedResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read DispatchAcceptedResult $resource
 */
final class DispatchAcceptedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'accepted' => (bool) $this->resource->accepted,
            'notification_ids' => array_map(
                static fn ($id) => $id->value,
                $this->resource->notificationIds
            ),
        ];
    }
}
