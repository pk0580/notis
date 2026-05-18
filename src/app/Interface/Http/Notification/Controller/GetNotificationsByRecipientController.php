<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Controller;

use App\Application\Notification\Query\GetNotificationsByRecipient\GetNotificationsByRecipientHandler;
use App\Application\Notification\Query\GetNotificationsByRecipient\GetNotificationsByRecipientQuery;
use App\Interface\Http\Notification\Request\GetNotificationsByRecipientRequest;
use Illuminate\Http\JsonResponse;

final class GetNotificationsByRecipientController
{
    public function __invoke(
        GetNotificationsByRecipientRequest $request,
        GetNotificationsByRecipientHandler $handler
    ): JsonResponse {
        $query = new GetNotificationsByRecipientQuery(
            recipient: $request->getRecipient(),
            cursor: (string) $request->query('cursor'),
            perPage: (int) $request->query('per_page', 20)
        );

        $result = $handler->handle($query);

        return new JsonResponse([
            'data' => $result['data'],
            'meta' => [
                'next_cursor' => $result['next_cursor'],
            ],
        ]);
    }
}
