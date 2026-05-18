<?php

declare(strict_types=1);

namespace App\Interface\Http\Notification\Controller;

use App\Application\Notification\UseCase\DispatchNotifications\DispatchNotificationsAction;
use App\Application\Notification\UseCase\DispatchNotifications\DispatchNotificationsData;
use App\Domain\Notification\ValueObject\Channel;
use App\Domain\Notification\ValueObject\MessageBody;
use App\Domain\Notification\ValueObject\Priority;
use App\Domain\Notification\ValueObject\Recipient;
use App\Interface\Http\Notification\Request\DispatchNotificationsRequest;
use App\Interface\Http\Notification\Resource\DispatchAcceptedResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class DispatchNotificationsController
{
    public function __construct(
        private DispatchNotificationsAction $action
    ) {}

    public function __invoke(DispatchNotificationsRequest $request): JsonResponse
    {
        $channel = Channel::from($request->input('channel'));

        $data = new DispatchNotificationsData(
            channel: $channel,
            priority: Priority::from($request->input('priority')),
            body: MessageBody::for($channel, $request->input('body')),
            recipients: array_map(
                static fn (string $r) => Recipient::fromString($channel, $r),
                $request->input('recipients')
            ),
            traceId: (string) $request->attributes->get('trace_id'),
        );

        $result = $this->action->handle($data);

        return (new DispatchAcceptedResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
