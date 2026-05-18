<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Controller;

use App\Application\Notification\Query\GetNotificationsByRecipient\GetNotificationsByRecipientHandler;
use App\Application\Notification\Query\GetNotificationsByRecipient\GetNotificationsByRecipientQuery;
use App\Interface\Http\Notification\Request\GetNotificationsByRecipientRequest;
use App\Interface\Http\Notification\Resource\NotificationCollection;
use Illuminate\Http\JsonResponse;

final readonly class GetNotificationsByRecipientController
{
    public function __construct(
        private GetNotificationsByRecipientHandler $handler
    ) {
    }

    public function __invoke(GetNotificationsByRecipientRequest $request): JsonResponse
    {
        $query = new GetNotificationsByRecipientQuery(
            recipient: $request->getRecipient(),
            cursor: $request->query('cursor') ? (string) $request->query('cursor') : null,
            perPage: (int) $request->query('per_page', 20)
        );

        $result = $this->handler->handle($query);

        return (new NotificationCollection($result['data']))
            ->additional([
                'meta' => [
                    'next_cursor' => $result['next_cursor'],
                ],
            ])
            ->response();
    }
}
