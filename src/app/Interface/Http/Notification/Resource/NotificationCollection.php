<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class NotificationCollection extends ResourceCollection
{
    public $collects = NotificationResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
